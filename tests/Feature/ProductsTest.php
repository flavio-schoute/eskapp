<?php

use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Products\ProductResource;
use App\Jobs\SyncProducts;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductSync;
use App\Support\Finance\ProductStreams;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.plug_and_pay.api_key' => 'pnp-token']);
    Http::preventStrayRequests();
    actingAs(User::factory()->create());
});

it('syncs the products with their order counts and marks the ones in use', function () {
    Http::fake([
        'api.plugandpay.com/v2/products?*is_deleted=1*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/products*' => Http::response(['data' => [
            ['id' => 321827, 'title' => 'Van consument naar ondernemer traject - HRM', 'type' => 'installments', 'created_at' => '2025-01-01T00:00:00Z'],
            ['id' => 201385, 'title' => 'Oud traject - 1 termijn', 'type' => 'one_off', 'created_at' => '2024-06-14T08:03:11Z'],
        ], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/orders*' => Http::response(['data' => [
            ['id' => 1, 'created_at' => '2026-10-01T10:00:00Z', 'items' => [['product_id' => 321827]]],
            ['id' => 2, 'created_at' => '2026-10-16T10:00:00Z', 'items' => [['product_id' => 321827], ['product_id' => null]]],
        ], 'meta' => ['last_page' => 1]]),
    ]);
    $removed = Product::factory()->create(['plug_and_pay_product_id' => 999999]);

    expect(app(ProductSync::class)->sync())->toBe(['products' => 2, 'never_ordered' => 1]);

    expect(Product::firstWhere('plug_and_pay_product_id', 321827))
        ->in_use->toBeTrue()
        ->orders_count->toBe(2)
        ->last_ordered_at->toDateString()->toBe('2026-10-16')
        ->and(Product::firstWhere('plug_and_pay_product_id', 201385))->in_use->toBeFalse()->orders_count->toBe(0)
        ->and($removed->fresh()->deleted_in_plug_and_pay_at)->not->toBeNull();
});

it('keeps the in use choice made in the app when syncing again', function () {
    Product::factory()->create(['plug_and_pay_product_id' => 321827, 'in_use' => false]);
    Http::fake([
        'api.plugandpay.com/v2/products?*is_deleted=1*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/products*' => Http::response(['data' => [['id' => 321827, 'title' => 'HRM']], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/orders*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
    ]);

    app(ProductSync::class)->sync();

    expect(Product::sole()->in_use)->toBeFalse();
});

it('takes over a changed SKU in the finance order lines and links export lines to the product', function () {
    Product::factory()->create(['plug_and_pay_product_id' => 321827, 'sku' => '259254', 'revenue_stream_id' => null]);
    Order::create(['external_id' => '1#1', 'plug_and_pay_order_id' => 1, 'order_ref' => 'ESKOOL1', 'plug_and_pay_product_id' => 321827, 'sku' => '259254', 'subtotal_ex_vat_cents' => 100, 'status' => 'paid']);
    Order::create(['external_id' => '2#sku-888999-1', 'plug_and_pay_order_id' => 2, 'order_ref' => 'ESKOOL2', 'sku' => '888999', 'subtotal_ex_vat_cents' => 100, 'status' => 'paid']);
    Http::fake([
        'api.plugandpay.com/v2/products?*is_deleted=1*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/products*' => Http::response(['data' => [['id' => 321827, 'title' => 'HRM', 'sku' => '888999']], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/orders*' => Http::response(['data' => [], 'meta' => ['last_page' => 1]]),
    ]);

    app(ProductSync::class)->sync();

    expect(Order::firstWhere('external_id', '1#1')->sku)->toBe('888999')
        ->and(Order::firstWhere('external_id', '2#sku-888999-1')->plug_and_pay_product_id)->toBe(321827)
        // No stream yet: the new SKU's stream is picked up.
        ->and(Product::sole()->revenue_stream_id)->toBe(RevenueStream::firstWhere('name', ProductStreams::streamFor('888999'))?->id);
});

it('lists the products in use and not in use in separate tabs', function () {
    $used = Product::factory()->create(['in_use' => true]);
    $unused = Product::factory()->create(['in_use' => false]);

    Livewire::test(ListProducts::class)
        ->assertCanSeeTableRecords([$used])
        ->assertCanNotSeeTableRecords([$unused])
        ->set('activeTab', 'not_in_use')
        ->assertCanSeeTableRecords([$unused])
        ->assertCanNotSeeTableRecords([$used]);
});

it('shows the never ordered products in their own tab and warns about recently ordered unused ones', function () {
    $neverOrdered = Product::factory()->create(['orders_count' => 0]);
    $ordered = Product::factory()->create(['orders_count' => 5, 'last_ordered_at' => now()->subWeek()]);

    Livewire::test(ListProducts::class)
        ->set('activeTab', 'never_ordered')
        ->assertCanSeeTableRecords([$neverOrdered])
        ->assertCanNotSeeTableRecords([$ordered])
        ->set('activeTab', 'not_in_use')
        ->assertSee('Still ordered recently, check before removing')
        ->assertSee('1 products marked as not in use were still ordered in the last 3 months');
});

it('marks a product as not in use from the list', function () {
    $product = Product::factory()->create(['in_use' => true]);

    Livewire::test(ListProducts::class)
        ->call('updateTableColumnState', 'in_use', (string) $product->getKey(), false);

    expect($product->fresh()->in_use)->toBeFalse();
});

it('syncs the products in the background from the page', function () {
    Queue::fake();

    Livewire::test(ListProducts::class)->callAction('sync')->assertNotified('Products are being synced');

    Queue::assertPushed(SyncProducts::class);
});

it('keeps viewers out of the products', function () {
    actingAs(User::factory()->viewer()->create());

    get(ProductResource::getUrl())->assertForbidden();
});

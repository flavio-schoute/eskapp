<?php

use App\Filament\Pages\Finance\Import as ImportPage;
use App\Models\Finance\Import;
use App\Models\Finance\Order;
use App\Models\Finance\RevenueStream;
use App\Models\Product;
use App\Models\User;
use App\Services\Finance\OrderFileImporter;
use App\Services\Finance\OrderSync;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->editor()->create());
    $this->csv = base_path('tests/Fixtures/finance/plug-and-pay-orders.csv');
    $this->stream = RevenueStream::firstOrCreate(['name' => 'Coaching sales']);
    Product::factory()->create(['plug_and_pay_product_id' => 321827, 'sku' => '888999', 'revenue_stream_id' => $this->stream->id]);
});

/**
 * The CSV fixture as an XLSX file, the way Plug&Pay exports it (numbers as numbers).
 */
function ordersXlsx(string $csv): string
{
    $path = tempnam(sys_get_temp_dir(), 'orders').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);

    foreach (array_map('str_getcsv', file($csv, FILE_IGNORE_NEW_LINES)) as $index => $values) {
        $writer->addRow(Row::fromValues($index === 0 ? $values : array_map(fn (string $value): string|int|float => is_numeric($value) ? $value + 0 : $value, $values)));
    }

    $writer->close();

    return $path;
}

it('imports a Plug&Pay export per order line, from CSV and XLSX, without duplicates', function (bool $xlsx) {
    $path = $xlsx ? ordersXlsx($this->csv) : $this->csv;
    $name = $xlsx ? 'orders.xlsx' : 'orders.csv';

    expect(app(OrderFileImporter::class)->preview($path, $name))
        ->toMatchArray(['total' => 4, 'orders' => 3, 'new' => 4, 'updated' => 0, 'skipped' => 1, 'paid' => 3, 'paid_ex_vat_cents' => 375000, 'date_from' => '2026-10-18', 'date_to' => '2026-10-19', 'unknown_skus' => ['530222', '530223']]);

    $result = app(OrderFileImporter::class)->commit($path, $name);

    expect($result)->toMatchArray(['new' => 4, 'unchanged' => 0])
        ->and(Order::firstWhere('external_id', '18668990#sku-888999-1'))
        ->plug_and_pay_product_id->toBe(321827)->subtotal_ex_vat_cents->toBe(350000)->total_cents->toBe(423500)
        ->paid_on->toDateString()->toBe('2026-10-19')->import_id->toBe($result['import']->id)
        // 22:45 UTC is already the next day in the Netherlands, like the API sync counts it.
        ->and(Order::firstWhere('external_id', '18668991#sku-530222-1')->paid_on->toDateString())->toBe('2026-10-19')
        ->and(Order::firstWhere('external_id', '18668992#sku-888999-1'))->status->toBe('processing')->paid_on->toBeNull();

    expect(app(OrderFileImporter::class)->commit($path, $name))->toMatchArray(['new' => 0, 'updated' => 0, 'unchanged' => 4])
        ->and(Order::count())->toBe(4);
})->with(['csv' => false, 'xlsx' => true]);

it('only updates the payment status of orders the API already fetched, matched on SKU', function () {
    Order::create(['external_id' => '18668992#1', 'plug_and_pay_order_id' => 18668992, 'order_ref' => 'ESKOOL202602733', 'plug_and_pay_product_id' => 321827, 'sku' => '888999', 'subtotal_ex_vat_cents' => 116667, 'total_cents' => 116667, 'status' => 'paid', 'invoiced_on' => '2026-10-19', 'paid_on' => '2026-10-19']);

    expect(app(OrderFileImporter::class)->commit($this->csv, 'orders.csv'))->toMatchArray(['new' => 3, 'updated' => 1])
        ->and(Order::where('plug_and_pay_order_id', 18668992)->count())->toBe(1)
        ->and(Order::firstWhere('external_id', '18668992#1'))->status->toBe('processing')->paid_on->toBeNull();
});

it('replaces imported lines with the API lines once the sync fetches the order', function () {
    app(OrderFileImporter::class)->commit($this->csv, 'orders.csv');
    config(['services.plug_and_pay.api_key' => 'pnp-token']);
    Http::preventStrayRequests();
    Http::fake(['api.plugandpay.com/v2/orders*' => Http::response(['data' => [[
        'id' => 18668990, 'invoice_number' => 'ESKOOL202602731', 'invoice_date' => '2026-10-19',
        'payment' => ['status' => 'paid', 'paid_at' => '2026-10-19T12:36:38Z'],
        'items' => [['id' => 1, 'product_id' => 321827, 'label' => 'Traject', 'quantity' => 1, 'amount' => '3500.00', 'amount_with_tax' => '4235.00']],
    ]], 'meta' => ['last_page' => 1]])]);

    app(OrderSync::class)->sync();

    expect(Order::where('plug_and_pay_order_id', 18668990)->pluck('external_id')->all())->toBe(['18668990#1']);
});

it('uploads a Plug&Pay export on the import page and undoes it', function () {
    $file = UploadedFile::fake()->createWithContent('orders.xlsx', file_get_contents(ordersXlsx($this->csv)));

    Livewire::test(ImportPage::class)
        ->set('data.orders_file', $file)
        ->assertSee(['Orderregels', 'in 3 orders', 'Betaald excl. btw', '€ 3.750'])
        ->call('commitOrders')
        ->assertNotified('4 orderregels toegevoegd');

    expect(Order::count())->toBe(4);

    Livewire::test(ImportPage::class)
        ->assertSee(['Plug & Pay-orders', 'Plug & Pay-export (XLSX)'])
        ->callAction(TestAction::make('undo')->table(Import::sole()));

    expect(Order::count())->toBe(0);
});

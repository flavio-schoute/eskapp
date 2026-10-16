<?php

use App\Enums\CustomerMatch;
use App\Filament\Resources\Customers\CustomerResource;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\DebtorCasesRelationManager;
use App\Jobs\SyncCustomers;
use App\Models\Customer;
use App\Models\DebtorCase;
use App\Models\User;
use App\Services\CustomerSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\GetPaginatedCustomerRequest;
use Mollie\Api\Resources\CustomerCollection;
use Mollie\Laravel\Facades\Mollie;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.plug_and_pay.api_key' => 'pnp-token', 'mollie.key' => 'access_'.str_repeat('a', 30)]);
    Http::preventStrayRequests();
    actingAs(User::factory()->create());
});

function fakeCustomerSources(): void
{
    $order = fn (int $id, string $email, string $created, ?string $mollieCustomer, string $phone) => [
        'id' => $id,
        'created_at' => $created,
        'customer_id' => 5682,
        'billing' => [
            'contact' => ['firstname' => 'Angelo', 'lastname' => 'Dap', 'company' => 'ASD-services', 'email' => $email, 'telephone' => $phone, 'vat_id_number' => null],
            'address' => ['street' => 'Korvet', 'housenumber' => '2', 'zipcode' => '3232TK', 'city' => 'Brielle', 'country' => 'NL'],
        ],
        'payment' => ['customer_id' => $mollieCustomer],
    ];

    Http::fake([
        'api.plugandpay.com/v2/customers*' => Http::response(['data' => [
            ['name' => 'Angelo Dap', 'email' => 'Angelo@Example.com', 'count' => 2, 'total' => 2176.16, 'open' => 0, 'created_at' => '2026-01-06T19:53:35Z', 'last_order' => '2026-08-14T18:47:00Z'],
            ['name' => 'Lisa Pitroipa', 'email' => 'lisa@example.com', 'count' => 9, 'total' => 2828.43, 'open' => 314.27, 'created_at' => '2025-12-10T10:00:00Z', 'last_order' => '2026-10-10T03:38:00Z'],
            ['name' => 'Bank Transfer', 'email' => 'bank@example.com', 'count' => 1, 'total' => 100, 'open' => 100, 'created_at' => '2026-02-01T10:00:00Z', 'last_order' => '2026-02-01T10:00:00Z'],
        ], 'meta' => ['last_page' => 1]]),
        'api.plugandpay.com/v2/orders*' => Http::response(['data' => [
            $order(1, 'angelo@example.com', '2026-01-06T19:53:35Z', 'cst_old', '0600000000'),
            $order(2, 'angelo@example.com', '2026-08-14T18:47:00Z', 'cst_angelo', '+31681937501'),
            $order(3, 'lisa@example.com', '2026-10-10T03:38:00Z', null, '+32470123456'),
        ], 'meta' => ['last_page' => 1]]),
    ]);

    Mollie::fake([
        GetPaginatedCustomerRequest::class => MockResponse::list(CustomerCollection::class)
            ->add(['resource' => 'customer', 'id' => 'cst_angelo', 'name' => 'Angelo Dap', 'email' => 'angelo@example.com', 'createdAt' => '2026-01-06T19:53:35+00:00'])
            ->add(['resource' => 'customer', 'id' => 'cst_lisa', 'name' => 'L. Pitroipa', 'email' => 'lisa@example.com', 'createdAt' => '2025-12-10T10:00:00+00:00'])
            ->add(['resource' => 'customer', 'id' => 'cst_only', 'name' => 'Only Mollie', 'email' => 'only@example.com', 'createdAt' => '2025-12-10T10:00:00+00:00'])
            ->create(),
    ]);
}

it('builds the customers from plug and pay and matches them with mollie', function () {
    fakeCustomerSources();

    expect(app(CustomerSync::class)->sync())->toBe(['customers' => 4, 'matched' => 1, 'mismatched' => 1]);

    expect(Customer::firstWhere('email', 'angelo@example.com'))
        ->name->toBe('Angelo Dap')
        ->company->toBe('ASD-services')
        ->phone->toBe('+31681937501')
        ->addressLine()->toBe('Korvet 2, 3232TK Brielle, NL')
        ->mollie_customer_ids->toEqualCanonicalizing(['cst_old', 'cst_angelo'])
        ->match_status->toBe(CustomerMatch::Linked)
        ->orders_count->toBe(2)
        ->and(Customer::firstWhere('email', 'lisa@example.com'))
        ->match_status->toBe(CustomerMatch::Mismatch)
        ->mollie_name->toBe('L. Pitroipa')
        ->open_amount->toBe('314.27')
        ->and(Customer::firstWhere('email', 'bank@example.com')->match_status)->toBe(CustomerMatch::PlugAndPayOnly)
        ->and(Customer::firstWhere('email', 'only@example.com')->match_status)->toBe(CustomerMatch::MollieOnly);
});

it('matches names regardless of accents, case and spaces', function () {
    expect(CustomerSync::normalizeName('José  van der Berg'))->toBe(CustomerSync::normalizeName('jose van-der berg'));
});

it('lists the customers and shows one with their debtor cases', function () {
    $customer = Customer::factory()->create(['name' => 'Angelo Dap', 'email' => 'angelo@example.com']);
    $case = DebtorCase::factory()->create(['email' => 'angelo@example.com', 'invoice_number' => 'ESKOOL202602188']);

    Livewire::test(ListCustomers::class)->assertCanSeeTableRecords([$customer]);

    get(CustomerResource::getUrl('view', ['record' => $customer]))
        ->assertOk()
        ->assertSee(['Angelo Dap', 'Korvet 2, 3232TK Brielle, NL', 'Matched']);

    Livewire::test(DebtorCasesRelationManager::class, ['ownerRecord' => $customer, 'pageClass' => ViewCustomer::class])
        ->assertCanSeeTableRecords([$case]);
});

it('syncs the customers in the background from the page', function () {
    Queue::fake();

    Livewire::test(ListCustomers::class)
        ->callAction('sync')
        ->assertNotified('Customers are being synced');

    Queue::assertPushed(SyncCustomers::class);
});

it('keeps viewers out of the customers', function () {
    actingAs(User::factory()->viewer()->create());

    get(CustomerResource::getUrl())->assertForbidden();
});

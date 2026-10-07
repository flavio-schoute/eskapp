<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\Integration;
use App\Enums\InvoiceLanguage;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Models\Affiliate;
use App\Models\IntegrationError;
use App\Models\User;
use App\Services\MollieCustomers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\UpdateCustomerRequest;
use Mollie\Laravel\Facades\Mollie;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'mollie.key' => 'test_'.str_repeat('a', 30),
        'services.mollie.sync_customers' => true,
    ]);

    actingAs(User::factory()->create());
});

function fakeMollieCustomers(): void
{
    Mollie::fake([
        CreateCustomerRequest::class => MockResponse::created(['resource' => 'customer', 'id' => 'cst_8wmqcHMN4U']),
        UpdateCustomerRequest::class => MockResponse::ok(['resource' => 'customer', 'id' => 'cst_8wmqcHMN4U']),
    ]);
}

/**
 * @return array<string, mixed>
 */
function chineseInvoiceAffiliateFormData(): array
{
    return [
        'name' => 'DayOne',
        'type' => AffiliateType::Software->value,
        'status' => AffiliateStatus::Active->value,
        'login_url' => 'https://dayone.test',
        'username' => 'eskapp',
        'password' => 'secret',
        'payment_method' => AffiliatePaymentMethod::Invoice->value,
        'invoice_company_name' => 'DAYONE FULFILLMENT CO.,LTD',
        'invoice_kvk_number' => '91330211MA2H1234X',
        'invoice_vat_number' => 'CN91330211',
        'invoice_email' => 'billing@dayone.test',
        'invoice_phone' => '+86 574 1234 5678',
        'invoice_address_line_1' => 'Rm808 Block A Zhongguanxilu 1277#',
        'invoice_postal_code' => '315201',
        'invoice_city' => 'Ningbo',
        'invoice_region' => 'Zhenhaiqu',
        'invoice_country' => 'CN',
        'invoice_language' => InvoiceLanguage::English->value,
    ];
}

it('creates a mollie customer with all invoice details when an invoiced affiliate is created', function () {
    fakeMollieCustomers();

    Livewire::test(CreateAffiliate::class)
        ->fillForm(chineseInvoiceAffiliateFormData())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotNotified('Saved, but Mollie sync failed');

    $affiliate = Affiliate::sole();

    expect($affiliate->mollie_customer_id)->toBe('cst_8wmqcHMN4U');

    Mollie::assertSent(function (PendingRequest $request) use ($affiliate): bool {
        $payload = $request->payload()->all();

        return $request->getRequest() instanceof CreateCustomerRequest
            && $payload['name'] === 'DAYONE FULFILLMENT CO.,LTD'
            && $payload['email'] === 'billing@dayone.test'
            && $payload['locale'] === 'en_US'
            && $payload['metadata'] === [
                'affiliate_id' => $affiliate->getKey(),
                'type' => 'company',
                'organization_number' => '91330211MA2H1234X',
                'vat_number' => 'CN91330211',
                'phone' => '+86 574 1234 5678',
                'street_and_number' => 'Rm808 Block A Zhongguanxilu 1277#',
                'postal_code' => '315201',
                'city' => 'Ningbo',
                'country' => 'CN',
                'region' => 'Zhenhaiqu',
            ];
    });
});

it('does not create mollie customers while the customer sync is switched off', function () {
    config(['services.mollie.sync_customers' => false]);
    fakeMollieCustomers();

    $affiliate = Affiliate::factory()->withInvoiceDetails()->create();

    expect($affiliate->refresh()->mollie_customer_id)->toBeNull();
    Mollie::assertSentCount(0);
});

it('does not create a mollie customer for automatic payouts', function () {
    fakeMollieCustomers();

    $affiliate = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Automatic]);

    expect($affiliate->refresh()->mollie_customer_id)->toBeNull();
    Mollie::assertSentCount(0);
});

it('waits with the mollie customer until the invoice details are complete', function () {
    fakeMollieCustomers();

    $affiliate = Affiliate::factory()->withInvoiceDetails()->create(['invoice_city' => null]);

    expect($affiliate->refresh()->mollie_customer_id)->toBeNull();

    $affiliate->update(['invoice_city' => 'Amsterdam']);

    expect($affiliate->refresh()->mollie_customer_id)->toBe('cst_8wmqcHMN4U');
});

it('creates the mollie customer when an affiliate switches to invoice payments', function () {
    fakeMollieCustomers();

    $affiliate = Affiliate::factory()->withInvoiceDetails()->create(['payment_method' => AffiliatePaymentMethod::Automatic]);

    expect($affiliate->refresh()->mollie_customer_id)->toBeNull();

    $affiliate->update(['payment_method' => AffiliatePaymentMethod::Invoice]);

    expect($affiliate->refresh()->mollie_customer_id)->toBe('cst_8wmqcHMN4U');
});

it('updates the mollie customer when the invoice details change', function () {
    fakeMollieCustomers();

    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create([
        'mollie_customer_id' => 'cst_8wmqcHMN4U',
    ]));

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->fillForm(['invoice_email' => 'new-billing@acme.test'])
        ->call('save')
        ->assertHasNoFormErrors();

    Mollie::assertSent(fn (PendingRequest $request): bool => $request->getRequest() instanceof UpdateCustomerRequest
        && str_ends_with($request->url(), '/customers/cst_8wmqcHMN4U')
        && $request->method() === 'PATCH'
        && $request->payload()->get('email') === 'new-billing@acme.test');
    Mollie::assertSentCount(1);
});

it('does not update the mollie customer when nothing invoice related changes', function () {
    fakeMollieCustomers();

    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create([
        'mollie_customer_id' => 'cst_8wmqcHMN4U',
    ]));

    $affiliate->update(['notes' => 'Just a note']);

    Mollie::assertSentCount(0);
});

it('uses the regional mollie locale for the preferred language', function (InvoiceLanguage $language, string $country, string $locale) {
    expect($language->mollieLocale($country))->toBe($locale);
})->with([
    'Dutch in the Netherlands' => [InvoiceLanguage::Dutch, 'NL', 'nl_NL'],
    'Dutch in Belgium' => [InvoiceLanguage::Dutch, 'BE', 'nl_BE'],
    'French in Belgium' => [InvoiceLanguage::French, 'BE', 'fr_BE'],
    'German in Austria' => [InvoiceLanguage::German, 'AT', 'de_AT'],
    'English in the UK' => [InvoiceLanguage::English, 'GB', 'en_GB'],
    'English in China' => [InvoiceLanguage::English, 'CN', 'en_US'],
]);

it('keeps the customer metadata within the mollie size limit', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->make([
        'invoice_address_line_1' => str_repeat('Long street name ', 20),
        'invoice_address_line_2' => str_repeat('Building ', 30),
        'invoice_region' => str_repeat('Region ', 30),
        'invoice_contact_person' => str_repeat('Name ', 30),
    ]);

    $metadata = app(MollieCustomers::class)->payload($affiliate)['metadata'];

    expect(strlen(json_encode($metadata)))->toBeLessThanOrEqual(1024)
        ->and($metadata)->toHaveKeys(['street_and_number', 'postal_code', 'city', 'country'])
        ->not->toHaveKey('contact_person');
});

it('logs and warns when mollie is not configured', function () {
    config(['mollie.key' => 'test_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxx']);

    Livewire::test(CreateAffiliate::class)
        ->fillForm(chineseInvoiceAffiliateFormData())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved, but Mollie sync failed');

    expect(IntegrationError::where('integration', Integration::Mollie)->sole())
        ->action->toBe('Create customer')
        ->message->toBe('Mollie is not configured.');
});

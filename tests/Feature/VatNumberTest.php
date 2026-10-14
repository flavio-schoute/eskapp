<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\InvoiceLanguage;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Models\Affiliate;
use App\Models\User;
use App\Services\ViesVatValidator;
use App\Support\InvoiceAddressFormat;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.vies.enabled' => true,
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    actingAs(User::factory()->create());
});

function fakeVies(string $userError, bool $isValid, string $name = '---', string $address = '---'): void
{
    Http::fake([
        'ec.europa.eu/taxation_customs/vies/rest-api/*' => Http::response(compact('isValid', 'userError', 'name', 'address')),
    ]);
}

/**
 * @return array<string, mixed>
 */
function belgianAffiliateFormData(string $vatNumber): array
{
    return [
        'name' => 'Brussels Partner',
        'type' => AffiliateType::Affiliate->value,
        'status' => AffiliateStatus::Active->value,
        'login_url' => 'https://partner.test',
        'username' => 'eskapp',
        'password' => 'secret',
        'payment_method' => AffiliatePaymentMethod::Invoice->value,
        'invoice_company_name' => 'Brussels Partner BV',
        'invoice_kvk_number' => '0123.456.789',
        'invoice_vat_number' => $vatNumber,
        'invoice_email' => 'billing@partner.test',
        'invoice_address_line_1' => 'Rue de la Loi 16',
        'invoice_postal_code' => '1000',
        'invoice_city' => 'Brussel',
        'invoice_country' => 'BE',
    ];
}

it('saves a belgian vat number written with dots and spaces in the official notation', function () {
    fakeVies('VALID', true);

    Livewire::test(CreateAffiliate::class)
        ->fillForm(belgianAffiliateFormData('be 0123.456.789'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Affiliate::sole()->invoice_vat_number)->toBe('BE0123456789');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/BE/vat/0123456789');
});

it('rejects a vat number that vies says is not valid', function () {
    fakeVies('INVALID', false);

    Livewire::test(CreateAffiliate::class)
        ->fillForm(belgianAffiliateFormData('BE0000000000'))
        ->call('create')
        ->assertHasFormErrors(['invoice_vat_number']);

    expect(Affiliate::count())->toBe(0);
});

it('still saves when vies is not available', function () {
    fakeVies('MS_UNAVAILABLE', false);

    Livewire::test(CreateAffiliate::class)
        ->fillForm(belgianAffiliateFormData('BE0123456789'))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Affiliate::sole()->invoice_vat_number)->toBe('BE0123456789');
});

it('does not check vat numbers from outside the eu with vies', function () {
    expect(app(ViesVatValidator::class)->check('CN91330211')['status'])->toBe(ViesVatValidator::NotEu);

    Http::assertNothingSent();
});

it('shows the company behind a vat number when checking it with vies', function () {
    fakeVies('VALID', true, 'BRUSSELS PARTNER BV', "Rue de la Loi 16\n1000 Brussel");
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create([
        'invoice_country' => 'BE',
        'invoice_vat_number' => 'BE0123456789',
    ]));

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->callAction(TestAction::make('checkVatNumber')->schemaComponent('invoice_vat_number'))
        ->assertNotified('BE0123456789 is valid');
});

it('shows the notation partners are used to per country', function (string $country, string $vatPlaceholder, ?string $registrationLabel) {
    expect(InvoiceAddressFormat::vatNumberPlaceholder($country))->toBe($vatPlaceholder);

    if ($registrationLabel) {
        expect(InvoiceAddressFormat::registrationNumberLabel($country))->toBe($registrationLabel);
    }
})->with([
    'Belgium' => ['BE', 'BE 0123.456.789', 'Enterprise number (KBO)'],
    'Netherlands' => ['NL', 'NL123456789B01', 'KvK number'],
    'Germany' => ['DE', 'DE123456789', 'Company registration number'],
    'Greece uses EL' => ['GR', 'EL123456789', null],
]);

it('fills in the empty invoice fields from vies', function () {
    fakeVies('VALID', true, 'ESKOOL B.V.', "KWIKSTAARTLAAN 00042\n3704GS ZEIST");

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_vat_number' => 'NL866580748B01',
        ])
        ->callAction(TestAction::make('checkVatNumber')->schemaComponent('invoice_vat_number'))
        ->assertNotified('NL866580748B01 is valid')
        ->assertSchemaStateSet([
            'invoice_company_name' => 'Eskool B.V.',
            'invoice_address_line_1' => 'Kwikstaartlaan 42',
            'invoice_postal_code' => '3704 GS',
            'invoice_city' => 'Zeist',
            'invoice_country' => 'NL',
            'invoice_language' => InvoiceLanguage::Dutch,
        ]);
});

it('does not overwrite invoice fields that are already filled in', function () {
    fakeVies('VALID', true, 'ESKOOL B.V.', "KWIKSTAARTLAAN 00042\n3704GS ZEIST");

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_vat_number' => 'NL866580748B01',
            'invoice_company_name' => 'e-Skool',
            'invoice_city' => 'Utrecht',
        ])
        ->callAction(TestAction::make('checkVatNumber')->schemaComponent('invoice_vat_number'))
        ->assertSchemaStateSet([
            'invoice_company_name' => 'e-Skool',
            'invoice_city' => 'Utrecht',
            'invoice_address_line_1' => 'Kwikstaartlaan 42',
        ]);
});

it('checks the vat number with vies and fills in the address when you leave the field', function () {
    fakeVies('VALID', true, 'ESKOOL B.V.', "KWIKSTAARTLAAN 00042\n3704GS ZEIST");

    Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => AffiliatePaymentMethod::Invoice->value])
        ->set('data.invoice_vat_number', 'NL 8665.80748.B01')
        ->assertNotified('NL866580748B01 is valid')
        ->assertSchemaStateSet([
            'invoice_company_name' => 'Eskool B.V.',
            'invoice_address_line_1' => 'Kwikstaartlaan 42',
            'invoice_postal_code' => '3704 GS',
            'invoice_city' => 'Zeist',
        ]);
});

it('warns right away when vies says the vat number is not valid', function () {
    fakeVies('INVALID', false);

    Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => AffiliatePaymentMethod::Invoice->value])
        ->set('data.invoice_vat_number', 'BE0000000000')
        ->assertNotified('BE0000000000 is not valid according to VIES');
});

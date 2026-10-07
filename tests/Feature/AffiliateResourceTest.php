<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\InvoiceLanguage;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Filament\Resources\Affiliates\Widgets\AffiliateStatusOverview;
use App\Filament\Resources\Affiliates\Widgets\PipelineAffiliates;
use App\Models\Affiliate;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    actingAs(User::factory()->create());
});

it('requires authentication to view the affiliates list', function () {
    Auth::logout();

    get('/admin/affiliates')->assertRedirect('/admin/login');
});

it('shows only active affiliates by default', function () {
    $active = Affiliate::factory()->count(2)->create();
    $onHold = Affiliate::factory()->onHold()->create();
    $stopped = Affiliate::factory()->stopped()->create();

    Livewire::test(ListAffiliates::class)
        ->assertOk()
        ->assertCanSeeTableRecords($active)
        ->assertCanNotSeeTableRecords([$onHold, $stopped]);
});

it('can filter affiliates by status', function () {
    $active = Affiliate::factory()->create();
    $onHold = Affiliate::factory()->onHold()->create();

    Livewire::test(ListAffiliates::class)
        ->filterTable('status', AffiliateStatus::OnHold->value)
        ->assertCanSeeTableRecords([$onHold])
        ->assertCanNotSeeTableRecords([$active])
        ->removeTableFilters()
        ->assertCanSeeTableRecords([$active, $onHold]);
});

it('can update the status inline from the list', function () {
    $affiliate = Affiliate::factory()->create();

    Livewire::test(ListAffiliates::class)
        ->call('updateTableColumnState', 'status', (string) $affiliate->getKey(), AffiliateStatus::OnHold->value);

    expect($affiliate->refresh()->status)->toBe(AffiliateStatus::OnHold);
});

it('can create an affiliate', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Acme Partners',
            'type' => AffiliateType::Partnership->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://dashboard.acme.test',
            'username' => 'eskapp',
            'password' => 'secret-password',
            'agreement' => '20% recurring per sale.',
            'payment_method' => AffiliatePaymentMethod::Automatic->value,
            'affiliate_link' => 'https://acme.test/?ref=eskapp',
            'notes' => 'Contact via e-mail.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $affiliate = Affiliate::sole();

    expect($affiliate->name)->toBe('Acme Partners')
        ->and($affiliate->type)->toBe(AffiliateType::Partnership)
        ->and($affiliate->payment_method)->toBe(AffiliatePaymentMethod::Automatic)
        ->and($affiliate->password)->toBe('secret-password')
        ->and($affiliate->getRawOriginal('password'))->not->toBe('secret-password');
});

it('requires the basics, access and payment fields', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => null,
            'type' => null,
            'status' => null,
            'login_url' => 'not-a-url',
            'username' => null,
            'password' => null,
            'payment_method' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'type' => 'required',
            'status' => 'required',
            'login_url' => 'url',
            'username' => 'required',
            'password' => 'required',
            'payment_method' => 'required',
        ]);
});

it('can edit an affiliate and keeps the stored password filled in', function () {
    $affiliate = Affiliate::factory()->create(['password' => 'original-password']);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertSchemaStateSet(['password' => 'original-password'])
        ->fillForm(['status' => AffiliateStatus::Stopped->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $affiliate->refresh();

    expect($affiliate->status)->toBe(AffiliateStatus::Stopped)
        ->and($affiliate->password)->toBe('original-password');
});

it('can view an affiliate', function () {
    $affiliate = Affiliate::factory()->create();

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertOk()
        ->assertSee($affiliate->name);
});

it('can delete an affiliate from the list', function () {
    $affiliate = Affiliate::factory()->create();

    Livewire::test(ListAffiliates::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($affiliate));

    assertDatabaseMissing($affiliate);
});

it('lists only pipeline affiliates in the pipeline block', function () {
    $pipeline = Affiliate::factory()->pipeline()->count(2)->create();
    $active = Affiliate::factory()->create();

    Livewire::test(PipelineAffiliates::class)
        ->assertCanSeeTableRecords($pipeline)
        ->assertCanNotSeeTableRecords([$active]);
});

it('keeps pipeline affiliates out of the default active list', function () {
    $pipeline = Affiliate::factory()->pipeline()->create();

    Livewire::test(ListAffiliates::class)
        ->assertCanNotSeeTableRecords([$pipeline]);
});

it('can make a pipeline affiliate active from the pipeline block', function () {
    $affiliate = Affiliate::factory()->pipeline()->create();

    Livewire::test(PipelineAffiliates::class)
        ->callAction(TestAction::make('activate')->table($affiliate))
        ->assertDispatched('affiliate-status-updated')
        ->assertCanNotSeeTableRecords([$affiliate]);

    expect($affiliate->refresh()->status)->toBe(AffiliateStatus::Active);
});

it('shows the number of affiliates per status', function () {
    Affiliate::factory()->count(3)->create();
    Affiliate::factory()->pipeline()->count(2)->create();

    Livewire::test(AffiliateStatusOverview::class)
        ->assertSeeInOrder(['In pipeline', '2', 'Active', '3', 'On hold', '0', 'Stopped', '0']);
});

it('prefills the pipeline status when adding from the pipeline block', function () {
    Livewire::withQueryParams(['status' => AffiliateStatus::Pipeline->value])
        ->test(CreateAffiliate::class)
        ->assertSchemaStateSet(['status' => AffiliateStatus::Pipeline]);
});

it('saves optional invoice details and shows the formatted address', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'DayOne',
            'type' => AffiliateType::Software->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://dayone.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_company_name' => 'DAYONE FULFILLMENT CO.,LTD',
            'invoice_address_line_1' => 'Rm808 Block A Zhongguanxilu 1277#',
            'invoice_postal_code' => '315201',
            'invoice_city' => 'Ningbo',
            'invoice_region' => 'Zhenhaiqu',
            'invoice_country' => 'CN',
            'invoice_contact_person' => 'Li Wei',
            'invoice_phone' => '+86 574 1234 5678',
            'invoice_email' => 'billing@dayone.test',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $affiliate = Affiliate::sole();

    expect($affiliate->invoice_country)->toBe('CN')
        ->and($affiliate->invoice_email)->toBe('billing@dayone.test');

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertSee('Rm808 Block A Zhongguanxilu 1277# Zhenhaiqu Ningbo, 315201, China')
        ->assertSee('Li Wei');
});

it('validates the invoice email address', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_email' => 'not-an-email',
        ])
        ->call('create')
        ->assertHasFormErrors(['invoice_email' => 'email']);
});

it('groups affiliates and slack members under one affiliates menu', function () {
    get('/admin/affiliates')
        ->assertOk()
        ->assertSeeInOrder(['e-Skool Tools', 'Affiliates', 'All affiliates', 'Slack members']);
});

it('lists affiliates paid by invoice first by default, then by name', function () {
    $automatic = Affiliate::factory()->create(['name' => 'Alpha', 'payment_method' => AffiliatePaymentMethod::Automatic]);
    $other = Affiliate::factory()->create(['name' => 'Bravo', 'payment_method' => AffiliatePaymentMethod::Other]);
    $invoiceZulu = Affiliate::factory()->create(['name' => 'Zulu', 'payment_method' => AffiliatePaymentMethod::Invoice]);
    $invoiceCharlie = Affiliate::factory()->create(['name' => 'Charlie', 'payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(ListAffiliates::class)
        ->assertCanSeeTableRecords([$invoiceCharlie, $invoiceZulu, $other, $automatic], inOrder: true)
        ->sortTable('payment_method', 'desc')
        ->assertCanSeeTableRecords([$automatic, $other, $invoiceCharlie, $invoiceZulu], inOrder: true);
});

it('requires the main invoice details when the affiliate is paid by invoice', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => AffiliatePaymentMethod::Invoice->value])
        ->call('create')
        ->assertHasFormErrors([
            'invoice_company_name' => 'required',
            'invoice_email' => 'required',
            'invoice_address_line_1' => 'required',
            'invoice_postal_code' => 'required',
            'invoice_city' => 'required',
            'invoice_country' => 'required',
        ])
        ->assertHasNoFormErrors([
            'invoice_contact_person',
            'invoice_phone',
            'invoice_address_line_2',
            'invoice_vat_number',
        ]);
});

it('does not require invoice details for automatic payouts', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => AffiliatePaymentMethod::Automatic->value])
        ->call('create')
        ->assertHasNoFormErrors(['invoice_company_name', 'invoice_email', 'invoice_country']);
});

it('asks for the registration and vat number for every country', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => AffiliatePaymentMethod::Invoice->value, 'invoice_country' => 'NL'])
        ->assertFormFieldVisible('invoice_kvk_number')
        ->assertFormFieldVisible('invoice_vat_number')
        ->fillForm(['invoice_country' => 'CN'])
        ->assertFormFieldVisible('invoice_kvk_number')
        ->assertFormFieldVisible('invoice_vat_number');
});

it('accepts a foreign company registration number that is not a kvk number', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_country' => 'CN',
            'invoice_kvk_number' => '91330211MA2H1234X',
        ])
        ->call('create')
        ->assertHasNoFormErrors(['invoice_kvk_number']);
});

it('suggests the preferred language from the country', function (string $country, InvoiceLanguage $language) {
    Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => AffiliatePaymentMethod::Invoice->value])
        ->set('data.invoice_country', $country)
        ->assertSchemaStateSet(['invoice_language' => $language]);
})->with([
    'Netherlands' => ['NL', InvoiceLanguage::Dutch],
    'Germany' => ['DE', InvoiceLanguage::German],
    'France' => ['FR', InvoiceLanguage::French],
    'China' => ['CN', InvoiceLanguage::English],
    'Spain (no Spanish invoices)' => ['ES', InvoiceLanguage::English],
]);

it('validates and normalises the kvk and vat numbers', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_country' => 'NL',
            'invoice_kvk_number' => '1234',
            'invoice_vat_number' => '123456789',
        ])
        ->call('create')
        ->assertHasFormErrors(['invoice_kvk_number' => 'regex', 'invoice_vat_number' => 'regex']);

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Acme',
            'type' => AffiliateType::Affiliate->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://acme.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
            'invoice_company_name' => 'Acme B.V.',
            'invoice_kvk_number' => '1234 5678',
            'invoice_vat_number' => 'nl 1234.56789 b01',
            'invoice_email' => 'billing@acme.test',
            'invoice_address_line_1' => 'Keizersgracht 1',
            'invoice_postal_code' => '1015 CJ',
            'invoice_city' => 'Amsterdam',
            'invoice_country' => 'NL',
            'invoice_language' => InvoiceLanguage::Dutch->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Affiliate::sole())
        ->invoice_kvk_number->toBe('12345678')
        ->invoice_vat_number->toBe('NL123456789B01')
        ->invoice_language->toBe(InvoiceLanguage::Dutch);
});

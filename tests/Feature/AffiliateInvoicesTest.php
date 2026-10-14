<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\Integration;
use App\Enums\InvoiceLanguage;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\IntegrationError;
use App\Models\User;
use App\Support\InvoiceVat;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreateSalesInvoiceRequest;
use Mollie\Laravel\Facades\Mollie;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-07 12:00');

    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    actingAs(User::factory()->create());
});

it('lists only active affiliates that are paid by invoice', function () {
    $invoiced = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);
    $automatic = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Automatic]);
    $other = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Other]);
    $onHold = Affiliate::factory()->onHold()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);
    $pipeline = Affiliate::factory()->pipeline()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(AffiliateInvoices::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$invoiced])
        ->assertCanNotSeeTableRecords([$automatic, $other, $onHold, $pipeline]);
});

it('shows whether the invoice details are complete', function () {
    $complete = Affiliate::factory()->withInvoiceDetails()->create();
    $incomplete = Affiliate::factory()->withInvoiceDetails()->create(['invoice_city' => null]);

    Livewire::test(AffiliateInvoices::class)
        ->assertTableColumnStateSet('invoice_details_status', 'Complete', $complete)
        ->assertTableColumnStateSet('invoice_details_status', 'Incomplete', $incomplete);
});

it('is listed as invoices under the affiliates menu', function () {
    get(AffiliateInvoices::getUrl())
        ->assertOk()
        ->assertSeeInOrder(['Affiliates', 'All affiliates', 'Invoices', 'System', 'Email templates', 'Slack settings', 'Integration errors'])
        ->assertSee('Mollie');
});

it('creates and sends the invoice in mollie and keeps a record of it', function () {
    config(['mollie.key' => 'access_'.str_repeat('a', 30)]);
    Mollie::fake([
        CreateSalesInvoiceRequest::class => MockResponse::created([
            'resource' => 'sales-invoice',
            'id' => 'invoice_4Y0eZitmBnQ6IDoMqZQKh',
            'invoiceNumber' => 'INV-0042',
            'status' => 'issued',
            'totalAmount' => ['currency' => 'EUR', 'value' => '1513.11'],
            '_links' => ['pdfLink' => ['href' => 'https://mollie.test/invoice.pdf', 'type' => 'application/pdf']],
        ]),
    ]);

    $affiliate = Affiliate::factory()->withInvoiceDetails()->create([
        'name' => 'Acme',
        'invoice_company_name' => 'Acme B.V.',
        'invoice_contact_person' => 'Jan',
        'invoice_email' => 'billing@acme.test',
        'invoice_country' => 'NL',
        'invoice_language' => InvoiceLanguage::Dutch,
    ]);

    Livewire::test(AffiliateInvoices::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => $affiliate->getKey(),
            'period' => '2026-09-01',
            'amount' => '1250.50',
            'description' => 'Affiliate commissie – september 2026',
            'payment_term' => '14 days',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Invoice INV-0042 sent to billing@acme.test');

    Mollie::assertSent(function (PendingRequest $request) use ($affiliate): bool {
        $sent = json_decode((string) $request->payload(), true);

        return $request->getRequest() instanceof CreateSalesInvoiceRequest
            && $sent['status'] === 'issued'
            && $sent['paymentTerm'] === '14 days'
            && $sent['recipientIdentifier'] === "affiliate-{$affiliate->getKey()}"
            && $sent['recipient']['type'] === 'business'
            && $sent['recipient']['organizationName'] === 'Acme B.V.'
            && $sent['recipient']['email'] === 'billing@acme.test'
            && $sent['recipient']['locale'] === 'nl_NL'
            && $sent['lines'] === [[
                'description' => 'Affiliate commissie – september 2026',
                'quantity' => 1,
                'vatRate' => '21.00',
                'unitPrice' => ['currency' => 'EUR', 'value' => '1250.50'],
            ]]
            && $sent['emailDetails']['subject'] === 'Factuur affiliate commissie september 2026'
            && str_starts_with($sent['emailDetails']['body'], 'Beste Jan,')
            && ! isset($sent['memo']);
    });

    expect(AffiliateInvoice::sole())
        ->affiliate_id->toBe($affiliate->getKey())
        ->mollie_sales_invoice_id->toBe('invoice_4Y0eZitmBnQ6IDoMqZQKh')
        ->invoice_number->toBe('INV-0042')
        ->amount->toBe('1250.50')
        ->vat_rate->toBe('21.00')
        ->total_amount->toBe('1513.11')
        ->sent_to->toBe('billing@acme.test')
        ->pdf_url->toBe('https://mollie.test/invoice.pdf');
});

it('shows the vat and total before sending', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create([
        'invoice_company_name' => 'Acme B.V.',
        'invoice_email' => 'billing@acme.test',
        'invoice_country' => 'NL',
    ]);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoice')->table())
        ->fillForm(['affiliate_id' => $affiliate->getKey(), 'amount' => '100'])
        ->assertSchemaStateSet(['invoice_summary' => [
            'To: Acme B.V. <billing@acme.test>',
            'Commission: € 100,00',
            'VAT: 21% – Netherlands = € 21,00',
            'Total: € 121,00',
        ]]);
});

it('does not send anything and logs the error when mollie refuses the invoice', function () {
    config(['mollie.key' => 'access_'.str_repeat('a', 30)]);
    Mollie::fake([
        CreateSalesInvoiceRequest::class => MockResponse::error(500, 'Internal Server Error', 'Something went wrong'),
    ]);
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Acme']);

    Livewire::test(AffiliateInvoices::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => $affiliate->getKey(),
            'period' => '2026-09-01',
            'amount' => '100',
            'description' => 'Affiliate commissie – september 2026',
            'payment_term' => '30 days',
        ])
        ->assertNotified('Could not create the invoice for Acme');

    expect(AffiliateInvoice::count())->toBe(0)
        ->and(IntegrationError::where('integration', Integration::Mollie)->where('action', 'Generate invoice')->count())->toBe(1);
});

it('uses the partner\'s country for the vat on the invoice', function (string $country, ?string $vatNumber, string $rate, ?string $notePart) {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->make([
        'invoice_country' => $country,
        'invoice_vat_number' => $vatNumber,
        'invoice_language' => InvoiceLanguage::English,
    ]);

    expect(InvoiceVat::rateFor($affiliate))->toBe($rate);

    $notePart === null
        ? expect(InvoiceVat::invoiceNote($affiliate))->toBeNull()
        : expect(InvoiceVat::invoiceNote($affiliate))->toContain($notePart);
})->with([
    'Netherlands' => ['NL', 'NL123456789B01', '21.00', null],
    'EU business with a VAT number' => ['DE', 'DE123456789', '0.00', 'VAT reverse charged to the recipient'],
    'EU business without a VAT number' => ['DE', null, '21.00', null],
    'outside the EU' => ['CN', null, '0.00', 'VAT not applicable'],
]);

it('writes the reverse charge note in dutch for dutch speaking partners', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->make([
        'invoice_country' => 'BE',
        'invoice_vat_number' => 'BE0123456789',
        'invoice_language' => InvoiceLanguage::Dutch,
    ]);

    expect(InvoiceVat::invoiceNote($affiliate))->toStartWith('Btw verlegd naar de afnemer');
});

it('shows the last invoice per partner', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create();
    AffiliateInvoice::factory()->for($affiliate)->create(['invoice_number' => 'INV-0001', 'created_at' => now()->subMonth()]);
    AffiliateInvoice::factory()->for($affiliate)->create(['invoice_number' => 'INV-0002']);

    Livewire::test(AffiliateInvoices::class)
        ->assertTableColumnFormattedStateSet('latestInvoice.invoice_number', 'INV-0002 · '.now()->translatedFormat('j M Y'), $affiliate);
});

it('prefills the affiliate when generating from its row', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create();

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet([
            'affiliate_id' => $affiliate->getKey(),
            'period' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);
});

it('requires an affiliate, period and positive amount to generate an invoice', function () {
    Affiliate::factory()->withInvoiceDetails()->create();

    Livewire::test(AffiliateInvoices::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => null,
            'period' => null,
            'amount' => '0',
        ])
        ->assertHasActionErrors(['affiliate_id' => 'required', 'period' => 'required', 'amount' => 'min']);
});

it('disables generating an invoice for an affiliate with incomplete invoice details', function () {
    $complete = Affiliate::factory()->withInvoiceDetails()->create();
    $incomplete = Affiliate::factory()->create([
        'payment_method' => AffiliatePaymentMethod::Invoice,
        'invoice_company_name' => 'Acme B.V.',
    ]);

    Livewire::test(AffiliateInvoices::class)
        ->assertActionEnabled(TestAction::make('generateInvoiceForAffiliate')->table($complete))
        ->assertActionDisabled(TestAction::make('generateInvoiceForAffiliate')->table($incomplete))
        ->assertActionExists(
            TestAction::make('generateInvoiceForAffiliate')->table($incomplete),
            fn (Action $action): bool => $action->getTooltip() === 'Add the KvK number, email address, street and house number, postal code, city and country to the invoice details first.',
        );
});

it('keeps the generate invoice button available when no affiliate is complete yet', function () {
    Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(AffiliateInvoices::class)
        ->assertActionEnabled(TestAction::make('generateInvoice')->table())
        ->mountAction(TestAction::make('generateInvoice')->table())
        ->assertActionMounted(TestAction::make('generateInvoice')->table());
});

it('lists incomplete affiliates as unavailable in the generate invoice form', function () {
    $complete = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Complete Partner']);
    $incomplete = Affiliate::factory()->create(['name' => 'Incomplete Partner', 'payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoice')->table())
        ->assertFormFieldExists('affiliate_id', function (Select $field) use ($complete, $incomplete): bool {
            return $field->getOptions() === [
                $complete->getKey() => 'Complete Partner',
                $incomplete->getKey() => 'Incomplete Partner (invoice details incomplete)',
            ]
                && ! $field->isOptionDisabled($complete->getKey(), 'Complete Partner')
                && $field->isOptionDisabled($incomplete->getKey(), 'Incomplete Partner (invoice details incomplete)');
        });
});

it('does not generate an invoice for an affiliate with incomplete invoice details', function () {
    Affiliate::factory()->withInvoiceDetails()->create();
    $incomplete = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(AffiliateInvoices::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => $incomplete->getKey(),
            'period' => '2026-09-01',
            'amount' => '100',
            'description' => 'Affiliate commission September 2026',
        ])
        ->assertHasActionErrors(['affiliate_id']);
});

it('only shows invoice details when the payment method is not automatic', function (?AffiliatePaymentMethod $paymentMethod, bool $isVisible) {
    $component = Livewire::test(CreateAffiliate::class)
        ->fillForm(['payment_method' => $paymentMethod?->value]);

    $isVisible
        ? $component->assertFormFieldVisible('invoice_company_name')
        : $component->assertFormFieldHidden('invoice_company_name');
})->with([
    'automatic' => [AffiliatePaymentMethod::Automatic, false],
    'invoice' => [AffiliatePaymentMethod::Invoice, true],
    'other' => [AffiliatePaymentMethod::Other, true],
    'not chosen yet' => [null, false],
]);

it('shows invoice details when editing an affiliate that is paid by invoice', function () {
    $affiliate = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertFormFieldVisible('invoice_company_name');
});

it('hides invoice details on the view page for automatic payouts', function () {
    $automatic = Affiliate::factory()->create([
        'payment_method' => AffiliatePaymentMethod::Automatic,
        'invoice_contact_person' => 'Hidden Person',
    ]);
    $invoiced = Affiliate::factory()->create([
        'payment_method' => AffiliatePaymentMethod::Invoice,
        'invoice_contact_person' => 'Visible Person',
    ]);

    Livewire::test(ViewAffiliate::class, ['record' => $automatic->getRouteKey()])
        ->assertDontSee('Hidden Person');

    Livewire::test(ViewAffiliate::class, ['record' => $invoiced->getRouteKey()])
        ->assertSee('Visible Person');
});

it('allows generating an invoice for every partner with complete invoice details', function () {
    $exported = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Exported']);
    $notExported = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Not Exported']);
    $incomplete = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Incomplete', 'invoice_city' => null]);

    Livewire::test(AffiliateInvoices::class)
        ->assertActionEnabled(TestAction::make('generateInvoiceForAffiliate')->table($exported))
        ->assertActionEnabled(TestAction::make('generateInvoiceForAffiliate')->table($notExported))
        ->assertActionDisabled(TestAction::make('generateInvoiceForAffiliate')->table($incomplete))
        ->mountAction(TestAction::make('generateInvoice')->table())
        ->assertFormFieldExists('affiliate_id', function (Select $field) use ($exported, $notExported, $incomplete): bool {
            return $field->getOptions() === [
                $exported->getKey() => 'Exported',
                $incomplete->getKey() => 'Incomplete (invoice details incomplete)',
                $notExported->getKey() => 'Not Exported',
            ]
                && ! $field->isOptionDisabled($notExported->getKey(), 'Not Exported')
                && $field->isOptionDisabled($incomplete->getKey(), 'Incomplete (invoice details incomplete)');
        });
});
it('lets you pick a month instead of a date for the invoice period', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create();

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet(['period' => '2026-09-01'])
        ->assertFormFieldExists('period', function (Select $field): bool {
            $options = $field->getOptions();

            return array_key_first($options) === '2026-10-01'
                && $options['2026-10-01'] === 'October 2026'
                && $options['2026-09-01'] === 'September 2026'
                && array_key_last($options) === '2026-04-01'
                && count($options) === 7;
        });
});

it('adds the new month to the invoice periods when it starts', function () {
    $this->travelTo('2026-11-01 08:00');
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create();

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet(['period' => '2026-10-01'])
        ->assertFormFieldExists('period', fn (Select $field): bool => array_key_first($field->getOptions()) === '2026-11-01'
            && array_key_last($field->getOptions()) === '2026-05-01');
});

it('shows whether a partner is in mollie with its customer id', function () {
    $inMollie = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create(['mollie_customer_id' => 'cst_8wmqcHMN4U']));
    $notInMollie = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create(['mollie_customer_id' => null]));

    Livewire::test(AffiliateInvoices::class)
        ->assertTableColumnFormattedStateSet('mollie_customer_id', 'In Mollie', $inMollie)
        ->assertTableColumnHasDescription('mollie_customer_id', 'cst_8wmqcHMN4U', $inMollie)
        ->assertTableColumnStateNotSet('mollie_customer_id', 'cst_8wmqcHMN4U', $notInMollie)
        ->assertSee('1 partner is not in Mollie yet.');
});

it('adds a complete partner that is not in mollie yet to mollie', function () {
    config(['mollie.key' => 'live_'.str_repeat('a', 30)]);
    Mollie::fake([
        CreateCustomerRequest::class => MockResponse::created(['resource' => 'customer', 'id' => 'cst_new']),
    ]);
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Acme', 'mollie_customer_id' => null]));
    $incomplete = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create(['invoice_kvk_number' => null]));

    Livewire::test(AffiliateInvoices::class)
        ->assertActionHidden(TestAction::make('addToMollie')->table($incomplete))
        ->callAction(TestAction::make('addToMollie')->table($affiliate))
        ->assertNotified('Acme is now in Mollie');

    expect($affiliate->refresh()->mollie_customer_id)->toBe('cst_new');
});

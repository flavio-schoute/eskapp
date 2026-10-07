<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\Integration;
use App\Enums\InvoiceLanguage;
use App\Enums\MollieExportStatus;
use App\Filament\Pages\InvoiceSettings;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\IntegrationError;
use App\Models\User;
use App\Services\MollieInvoicingCustomersExport;
use App\Support\InvoiceVat;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\PendingRequest;
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

    Livewire::test(InvoiceSettings::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$invoiced])
        ->assertCanNotSeeTableRecords([$automatic, $other, $onHold, $pipeline]);
});

it('shows whether the invoice details are complete', function () {
    $complete = Affiliate::factory()->withInvoiceDetails()->create();
    $incomplete = Affiliate::factory()->withInvoiceDetails()->create(['invoice_city' => null]);

    Livewire::test(InvoiceSettings::class)
        ->assertTableColumnStateSet('invoice_details_status', 'Complete', $complete)
        ->assertTableColumnStateSet('invoice_details_status', 'Incomplete', $incomplete);
});

it('is listed under the system menu', function () {
    get(InvoiceSettings::getUrl())
        ->assertOk()
        ->assertSeeInOrder(['System', 'Invoice settings', 'Integration errors'])
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

    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create([
        'name' => 'Acme',
        'invoice_company_name' => 'Acme B.V.',
        'invoice_contact_person' => 'Jan',
        'invoice_email' => 'billing@acme.test',
        'invoice_country' => 'NL',
        'invoice_language' => InvoiceLanguage::Dutch,
    ]);

    Livewire::test(InvoiceSettings::class)
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
    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create([
        'invoice_company_name' => 'Acme B.V.',
        'invoice_email' => 'billing@acme.test',
        'invoice_country' => 'NL',
    ]);

    Livewire::test(InvoiceSettings::class)
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
    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create(['name' => 'Acme']);

    Livewire::test(InvoiceSettings::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => $affiliate->getKey(),
            'period' => '2026-09-01',
            'amount' => '100',
            'description' => 'Affiliate commissie – september 2026',
            'payment_term' => '30 days',
        ])
        ->assertNotified('Could not create the invoice for Acme');

    expect(AffiliateInvoice::count())->toBe(0)
        ->and(IntegrationError::where('integration', Integration::Mollie)->sole()->action)->toBe('Generate invoice');
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
    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();
    AffiliateInvoice::factory()->for($affiliate)->create(['invoice_number' => 'INV-0001', 'created_at' => now()->subMonth()]);
    AffiliateInvoice::factory()->for($affiliate)->create(['invoice_number' => 'INV-0002']);

    Livewire::test(InvoiceSettings::class)
        ->assertTableColumnFormattedStateSet('latestInvoice.invoice_number', 'INV-0002 · '.now()->translatedFormat('j M Y'), $affiliate);
});

it('prefills the affiliate when generating from its row', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();

    Livewire::test(InvoiceSettings::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet([
            'affiliate_id' => $affiliate->getKey(),
            'period' => now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
        ]);
});

it('requires an affiliate, period and positive amount to generate an invoice', function () {
    Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();

    Livewire::test(InvoiceSettings::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => null,
            'period' => null,
            'amount' => '0',
        ])
        ->assertHasActionErrors(['affiliate_id' => 'required', 'period' => 'required', 'amount' => 'min']);
});

it('disables generating an invoice for an affiliate with incomplete invoice details', function () {
    $complete = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();
    $incomplete = Affiliate::factory()->create([
        'payment_method' => AffiliatePaymentMethod::Invoice,
        'invoice_company_name' => 'Acme B.V.',
    ]);

    Livewire::test(InvoiceSettings::class)
        ->assertActionEnabled(TestAction::make('generateInvoiceForAffiliate')->table($complete))
        ->assertActionDisabled(TestAction::make('generateInvoiceForAffiliate')->table($incomplete))
        ->assertActionExists(
            TestAction::make('generateInvoiceForAffiliate')->table($incomplete),
            fn (Action $action): bool => $action->getTooltip() === 'Add the email address, street and house number, postal code, city and country to the invoice details first.',
        );
});

it('keeps the generate invoice button available when no affiliate is complete yet', function () {
    Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(InvoiceSettings::class)
        ->assertActionEnabled(TestAction::make('generateInvoice')->table())
        ->mountAction(TestAction::make('generateInvoice')->table())
        ->assertActionMounted(TestAction::make('generateInvoice')->table());
});

it('lists incomplete affiliates as unavailable in the generate invoice form', function () {
    $complete = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create(['name' => 'Complete Partner']);
    $incomplete = Affiliate::factory()->create(['name' => 'Incomplete Partner', 'payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(InvoiceSettings::class)
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
    Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();
    $incomplete = Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(InvoiceSettings::class)
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

it('exports partners with complete invoice details in mollie\'s customer import format', function () {
    $this->travelTo('2026-10-07 12:00');

    Affiliate::factory()->withInvoiceDetails()->create([
        'name' => 'DayOne',
        'invoice_company_name' => 'DAYONE FULFILLMENT CO.,LTD',
        'invoice_kvk_number' => '91330211MA2H1234X',
        'invoice_email' => 'billing@dayone.test',
        'invoice_phone' => '+86 574 1234 5678',
        'invoice_address_line_1' => 'Rm808 Block A Zhongguanxilu 1277#',
        'invoice_address_line_2' => null,
        'invoice_postal_code' => '315201',
        'invoice_city' => 'Ningbo',
        'invoice_region' => 'Zhenhaiqu',
        'invoice_country' => 'CN',
        'invoice_language' => InvoiceLanguage::English,
    ]);
    Affiliate::factory()->withInvoiceDetails()->create(['invoice_city' => null]);
    Affiliate::factory()->withInvoiceDetails()->create(['payment_method' => AffiliatePaymentMethod::Automatic]);

    $expectedCsv = implode("\n", [
        'type,company_name,company_number,first_name,last_name,email,phone_number,postal_code,street_name_and_number,address_line_2,city,country_code,preferred_language',
        'company,"DAYONE FULFILLMENT CO.,LTD",91330211MA2H1234X,,,billing@dayone.test,"+86 574 1234 5678",315201,"Rm808 Block A Zhongguanxilu 1277#",Zhenhaiqu,Ningbo,cn,en',
    ])."\n";

    Livewire::test(InvoiceSettings::class)
        ->callAction(TestAction::make('exportForMollie')->table())
        ->assertFileDownloaded('mollie-customers-2026-10-07.csv', $expectedCsv)
        ->assertNotified('Exported 1 partner for Mollie');
});

it('only exports partners that are not in mollie yet and remembers the export', function () {
    $exported = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Already Exported']);
    app(MollieInvoicingCustomersExport::class)->markAsExported(collect([$exported]));
    $new = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'New Partner']);

    Livewire::test(InvoiceSettings::class)
        ->assertActionExists(TestAction::make('exportForMollie')->table(), fn (Action $action): bool => $action->getLabel() === 'Export for Mollie (1)')
        ->callAction(TestAction::make('exportForMollie')->table())
        ->assertFileDownloaded(content: app(MollieInvoicingCustomersExport::class)->toCsv(collect([$new])));

    expect($new->refresh()->mollieExportStatus())->toBe(MollieExportStatus::Exported)
        ->and($new->mollie_exported_at)->not->toBeNull();

    Livewire::test(InvoiceSettings::class)
        ->callAction(TestAction::make('exportForMollie')->table())
        ->assertNoFileDownloaded()
        ->assertNotified('Nothing new to export');
});

it('reminds how many partners are not in mollie yet', function () {
    Affiliate::factory()->withInvoiceDetails()->count(2)->create();
    $exported = Affiliate::factory()->withInvoiceDetails()->create();
    app(MollieInvoicingCustomersExport::class)->markAsExported(collect([$exported]));

    Livewire::test(InvoiceSettings::class)
        ->assertSee('2 partners are not in Mollie yet')
        ->assertTableColumnFormattedStateSet('mollie_export_status', 'Exported '.now()->translatedFormat('j M Y'), $exported);
});

it('flags partners whose invoice details changed after the export until they are updated in mollie', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Acme']);
    app(MollieInvoicingCustomersExport::class)->markAsExported(collect([$affiliate]));

    $affiliate->update(['invoice_email' => 'new-billing@acme.test']);

    expect($affiliate->refresh()->mollieExportStatus())->toBe(MollieExportStatus::ChangedSinceExport);

    Livewire::test(InvoiceSettings::class)
        ->assertSee('1 partner changed since the export')
        ->assertActionVisible(TestAction::make('markUpdatedInMollie')->table($affiliate))
        ->callAction(TestAction::make('markUpdatedInMollie')->table($affiliate))
        ->assertNotified('Acme is up to date in Mollie');

    expect($affiliate->refresh()->mollieExportStatus())->toBe(MollieExportStatus::Exported);
});

it('uses the same columns as mollie\'s customer import template', function () {
    expect(MollieInvoicingCustomersExport::Columns)->toBe([
        'type', 'company_name', 'company_number', 'first_name', 'last_name', 'email', 'phone_number',
        'postal_code', 'street_name_and_number', 'address_line_2', 'city', 'country_code', 'preferred_language',
    ]);
});

it('does not download an empty export', function () {
    Affiliate::factory()->create(['payment_method' => AffiliatePaymentMethod::Invoice]);

    Livewire::test(InvoiceSettings::class)
        ->callAction(TestAction::make('exportForMollie')->table())
        ->assertNoFileDownloaded()
        ->assertNotified('Nothing new to export');
});

it('only allows generating an invoice for partners that are in mollie with their current details', function () {
    $inMollie = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create(['name' => 'In Mollie']);
    $notInMollie = Affiliate::factory()->withInvoiceDetails()->create(['name' => 'Not In Mollie']);
    $changed = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create(['name' => 'Changed']);
    $changed->update(['invoice_email' => 'new-billing@changed.test']);

    Livewire::test(InvoiceSettings::class)
        ->assertActionEnabled(TestAction::make('generateInvoiceForAffiliate')->table($inMollie))
        ->assertActionDisabled(TestAction::make('generateInvoiceForAffiliate')->table($notInMollie))
        ->assertActionExists(
            TestAction::make('generateInvoiceForAffiliate')->table($notInMollie),
            fn (Action $action): bool => $action->getTooltip() === 'Not in Mollie yet: click "Export for Mollie" and upload the file in Invoicing → Klanten first.',
        )
        ->assertActionDisabled(TestAction::make('generateInvoiceForAffiliate')->table($changed))
        ->mountAction(TestAction::make('generateInvoice')->table())
        ->assertFormFieldExists('affiliate_id', function (Select $field) use ($inMollie, $notInMollie, $changed): bool {
            return $field->getOptions() === [
                $changed->getKey() => 'Changed (changed since export)',
                $inMollie->getKey() => 'In Mollie',
                $notInMollie->getKey() => 'Not In Mollie (not in Mollie yet)',
            ]
                && ! $field->isOptionDisabled($inMollie->getKey(), 'In Mollie')
                && $field->isOptionDisabled($notInMollie->getKey(), 'Not In Mollie (not in Mollie yet)')
                && $field->isOptionDisabled($changed->getKey(), 'Changed (changed since export)');
        });
});

it('does not generate an invoice for a partner that is not in mollie yet', function () {
    Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();
    $notInMollie = Affiliate::factory()->withInvoiceDetails()->create();

    Livewire::test(InvoiceSettings::class)
        ->callAction(TestAction::make('generateInvoice')->table(), data: [
            'affiliate_id' => $notInMollie->getKey(),
            'period' => '2026-09-01',
            'amount' => '100',
            'description' => 'Affiliate commission September 2026',
        ])
        ->assertHasActionErrors(['affiliate_id']);
});

it('lets you pick a month instead of a date for the invoice period', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();

    Livewire::test(InvoiceSettings::class)
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
    $affiliate = Affiliate::factory()->withInvoiceDetails()->exportedToMollie()->create();

    Livewire::test(InvoiceSettings::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet(['period' => '2026-10-01'])
        ->assertFormFieldExists('period', fn (Select $field): bool => array_key_first($field->getOptions()) === '2026-11-01'
            && array_key_last($field->getOptions()) === '2026-05-01');
});

<?php

use App\Enums\EmailTemplateType;
use App\Enums\InvoiceLanguage;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Resources\EmailTemplates\Pages\ManageEmailTemplates;
use App\Models\Affiliate;
use App\Models\EmailTemplate;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
    $this->travelTo('2026-10-14 12:00');
    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    actingAs(User::factory()->create());
});

function fakeMollieInvoice(): void
{
    config(['mollie.key' => 'live_'.str_repeat('a', 30)]);
    Mollie::fake([
        CreateCustomerRequest::class => MockResponse::created(['resource' => 'customer', 'id' => 'cst_test']),
        CreateSalesInvoiceRequest::class => MockResponse::created([
            'resource' => 'sales-invoice',
            'id' => 'invoice_test',
            'invoiceNumber' => 'INV-0001',
            'status' => 'issued',
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function sentEmailDetails(): array
{
    $email = null;

    Mollie::assertSent(function (PendingRequest $request) use (&$email): bool {
        if (! $request->getRequest() instanceof CreateSalesInvoiceRequest) {
            return false;
        }

        $email = json_decode((string) $request->payload(), true)['emailDetails'];

        return true;
    });

    return $email;
}

it('starts with a dutch and an english default mollie invoice template', function () {
    expect(EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::Dutch)->subject)->toBe('Factuur affiliate commissie {month}')
        ->and(EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::English)->subject)->toBe('Invoice affiliate commission {month}');
});

it('falls back to the english default when there is no default in the partner\'s language', function () {
    expect(EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::German)->language)->toBe(InvoiceLanguage::English);
});

it('is listed under the system menu', function () {
    get('/admin/email-templates')
        ->assertOk()
        ->assertSeeInOrder(['System', 'Email templates', 'Integration errors']);
});

it('can edit a template', function () {
    $template = EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::Dutch);

    Livewire::test(ManageEmailTemplates::class)
        ->assertCanSeeTableRecords([$template])
        ->callAction(TestAction::make('edit')->table($template), data: [
            'subject' => 'Je factuur voor {month}',
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('Email template Standaard (Nederlands) saved');

    expect($template->refresh()->subject)->toBe('Je factuur voor {month}');
});

it('keeps only one default template per language', function () {
    $old = EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::Dutch);

    Livewire::test(ManageEmailTemplates::class)
        ->callAction('create', data: [
            'type' => EmailTemplateType::MollieInvoice->value,
            'language' => InvoiceLanguage::Dutch->value,
            'name' => 'Vriendelijk',
            'is_default' => true,
            'subject' => 'Hoi {contact_person}, je factuur',
            'body' => 'Hoi {contact_person}!',
        ])
        ->assertHasNoActionErrors();

    expect($old->refresh()->is_default)->toBeFalse()
        ->and(EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::Dutch)->name)->toBe('Vriendelijk');
});

it('fills in the placeholders', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->make([
        'name' => 'Acme',
        'invoice_company_name' => 'Acme B.V.',
        'invoice_contact_person' => null,
    ]);

    expect(EmailTemplate::fillPlaceholders('{contact_person} · {company_name} · {partner_name} · {month}', $affiliate, Carbon::parse('2026-09-01'), InvoiceLanguage::Dutch))
        ->toBe('Acme B.V. · Acme B.V. · Acme · september 2026');
});

it('sends the default template in the partner\'s language with the invoice', function () {
    fakeMollieInvoice();
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create([
        'invoice_language' => InvoiceLanguage::English,
        'invoice_contact_person' => 'Jane',
    ]);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet(['email_template' => (string) EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::English)->getKey()])
        ->fillForm(['amount' => '10', 'period' => '2026-09-01'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(sentEmailDetails())->toBe([
        'subject' => 'Invoice affiliate commission September 2026',
        'body' => "Dear Jane,\n\nPlease find attached the invoice for the affiliate commission of September 2026.\n\nKind regards,\ne-Skool",
    ]);
});

it('sends a custom email for one invoice', function () {
    fakeMollieInvoice();
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create([
        'invoice_language' => InvoiceLanguage::Dutch,
        'invoice_contact_person' => 'Jan',
    ]);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->fillForm(['email_template' => 'custom'])
        ->assertSchemaStateSet([
            'email_subject' => 'Factuur affiliate commissie september 2026',
        ])
        ->fillForm([
            'amount' => '10',
            'period' => '2026-09-01',
            'email_subject' => 'Extra: factuur {month}',
            'email_body' => "Hoi {contact_person},\nDit is een extra factuur.",
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(sentEmailDetails())->toBe([
        'subject' => 'Extra: factuur september 2026',
        'body' => "Hoi Jan,\nDit is een extra factuur.",
    ]);
});

it('groups the templates by language and explains which one was picked', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create([
        'name' => 'Acme',
        'invoice_language' => InvoiceLanguage::Dutch,
    ]);
    $dutch = EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::Dutch);
    $english = EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::English);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertFormFieldExists('email_template', fn (Select $field): bool => $field->getOptions() === [
            'Dutch' => [(string) $dutch->getKey() => 'Standaard (Nederlands)'],
            'English' => [(string) $english->getKey() => 'Default (English)'],
            'Other' => ['custom' => 'Custom – write the email for this invoice'],
        ] && $field->getHelperText() === "Picked automatically from Acme's preferred language (Dutch). Choose another template or Custom to change the email.");
});

it('picks the template from the country when no preferred language is set', function () {
    $affiliate = Affiliate::factory()->withInvoiceDetails()->create([
        'invoice_language' => null,
        'invoice_country' => 'BE',
    ]);

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet(['email_template' => (string) EmailTemplate::defaultFor(EmailTemplateType::MollieInvoice, InvoiceLanguage::Dutch)->getKey()]);
});

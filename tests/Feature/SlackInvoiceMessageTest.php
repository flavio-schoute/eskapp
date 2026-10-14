<?php

use App\Enums\Integration;
use App\Enums\InvoiceLanguage;
use App\Enums\MessageTemplateType;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Resources\SlackMembers\Widgets\AffiliateSlackChannels;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\IntegrationError;
use App\Models\MessageTemplate;
use App\Models\User;
use App\Services\SlackInvoiceMessages;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mollie\Api\Fake\MockResponse;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\CreateSalesInvoiceRequest;
use Mollie\Laravel\Facades\Mollie;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-14 12:00');
    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => 'xoxb-test-token',
        'mollie.key' => 'live_'.str_repeat('a', 30),
    ]);

    actingAs(User::factory()->create());
});

/**
 * An invoice partner with a Slack channel and a Slack contact; created without the automatic integrations.
 *
 * @param  array<string, mixed>  $attributes
 */
function slackInvoicePartner(array $attributes = []): Affiliate
{
    return Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create([
        'name' => 'Acme',
        'invoice_contact_person' => 'Jan',
        'invoice_email' => 'billing@acme.test',
        'invoice_language' => InvoiceLanguage::Dutch,
        'slack_channel_id' => 'C123',
        'slack_channel_name' => 'acme-affiliate',
        'slack_contact_name' => 'Kim',
        'slack_contact_member_id' => 'U0PARTNER01',
        'slack_contact_language' => InvoiceLanguage::English,
        'mollie_customer_id' => 'cst_test',
        ...$attributes,
    ]));
}

function fakeSlackUpload(string $pdfResponse = '%PDF-1.7 fake pdf'): void
{
    Http::fake([
        'api.mollie.com/*' => Http::response($pdfResponse),
        'slack.com/api/files.getUploadURLExternal' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/abc', 'file_id' => 'F123']),
        'files.slack.com/upload/*' => Http::response('OK'),
        'slack.com/api/files.completeUploadExternal' => Http::response(['ok' => true]),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);
}

it('has a default slack invoice message in every language', function (InvoiceLanguage $language, string $start) {
    expect(MessageTemplate::defaultFor(MessageTemplateType::SlackInvoice, $language)->body)->toStartWith($start);
})->with([
    'Dutch' => [InvoiceLanguage::Dutch, "Hé {mention},\n\nDe factuur voor *{month}* (factuurnummer: {invoice_number}) is zojuist verstuurd naar *{email}*."],
    'English' => [InvoiceLanguage::English, "Hi {mention},\n\nThe invoice for *{month}* (invoice number: {invoice_number}) has just been sent to *{email}*."],
    'German' => [InvoiceLanguage::German, "Hallo {mention},\n\ndie Rechnung für *{month}* (Rechnungsnummer: {invoice_number}) wurde soeben an *{email}* gesendet."],
    'French' => [InvoiceLanguage::French, "Bonjour {mention},\n\nLa facture pour *{month}* (numéro de facture : {invoice_number}) vient d’être envoyée à *{email}*."],
]);

it('tags the slack contact and uses the slack language', function () {
    $affiliate = slackInvoicePartner();

    expect(app(SlackInvoiceMessages::class)->message($affiliate, Carbon::parse('2026-09-01'), 'INV-0042', '€ 121,00', 'billing@acme.test'))
        ->toBe("Hi <@U0PARTNER01>,\n\nThe invoice for *September 2026* (invoice number: INV-0042) has just been sent to *billing@acme.test*.\n\nCould you check that you have received it?\n\nIf you have any questions, just let us know. We would also appreciate a short confirmation once the payment has been made.\n\nThanks in advance!");
});

it('uses the contact name and the invoice language when there is no member id or slack language', function () {
    $affiliate = slackInvoicePartner(['slack_contact_member_id' => null, 'slack_contact_language' => null]);

    expect(app(SlackInvoiceMessages::class)->message($affiliate, Carbon::parse('2026-09-01'), 'INV-0042', '€ 121,00', 'billing@acme.test'))
        ->toStartWith("Hé Kim,\n\nDe factuur voor *september 2026* (factuurnummer: INV-0042) is zojuist verstuurd naar *billing@acme.test*.");
});

it('posts the message with the invoice pdf in the affiliate\'s channel', function () {
    fakeSlackUpload();
    $affiliate = slackInvoicePartner();
    $invoice = AffiliateInvoice::factory()->for($affiliate)->create([
        'invoice_number' => 'INV-0042',
        'total_amount' => '121.00',
        'sent_to' => 'billing@acme.test',
        'pdf_url' => 'https://api.mollie.com/v2/sales-invoices/invoice_test/pdf',
        'period' => '2026-09-01',
    ]);

    app(SlackInvoiceMessages::class)->post($affiliate, $invoice);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.mollie.com/v2/sales-invoices/invoice_test/pdf'
        && $request->hasHeader('Authorization', 'Bearer '.config('mollie.key')));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/files.getUploadURLExternal'
        && $request['filename'] === 'Invoice INV-0042.pdf'
        && (int) $request['length'] === strlen('%PDF-1.7 fake pdf'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://files.slack.com/upload/v1/abc'
        && $request->body() === '%PDF-1.7 fake pdf');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/files.completeUploadExternal'
        && $request['channel_id'] === 'C123'
        && str_starts_with($request['initial_comment'], "Hi <@U0PARTNER01>,\n\nThe invoice for *September 2026* (invoice number: INV-0042)")
        && json_decode($request['files'], true) === [['id' => 'F123', 'title' => 'Invoice INV-0042.pdf']]);

    expect($invoice->refresh()->slack_posted_at)->not->toBeNull();
});

it('posts the message with a link when the pdf cannot be downloaded', function () {
    fakeSlackUpload(pdfResponse: '<html>not a pdf</html>');
    $affiliate = slackInvoicePartner();
    $invoice = AffiliateInvoice::factory()->for($affiliate)->create([
        'pdf_url' => 'https://api.mollie.com/v2/sales-invoices/invoice_test/pdf',
    ]);

    app(SlackInvoiceMessages::class)->post($affiliate, $invoice);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'C123'
        && str_contains($request['text'], '<https://api.mollie.com/v2/sales-invoices/invoice_test/pdf|Download the invoice (PDF)>'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'files.completeUploadExternal'));
});

it('never sends the mollie key to another domain', function () {
    Http::fake([
        'pdf.example.com/*' => Http::response('%PDF-1.7 fake pdf'),
        'slack.com/api/*' => Http::response(['ok' => true, 'upload_url' => 'https://files.slack.com/upload/v1/abc', 'file_id' => 'F123']),
        'files.slack.com/*' => Http::response('OK'),
    ]);
    $affiliate = slackInvoicePartner();
    $invoice = AffiliateInvoice::factory()->for($affiliate)->create(['pdf_url' => 'https://pdf.example.com/invoice.pdf']);

    app(SlackInvoiceMessages::class)->post($affiliate, $invoice);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://pdf.example.com/invoice.pdf'
        && ! $request->hasHeader('Authorization'));
});

it('posts in slack after generating an invoice', function () {
    fakeSlackUpload();
    Mollie::fake([
        CreateCustomerRequest::class => MockResponse::created(['resource' => 'customer', 'id' => 'cst_test']),
        CreateSalesInvoiceRequest::class => MockResponse::created([
            'resource' => 'sales-invoice',
            'id' => 'invoice_test',
            'invoiceNumber' => 'INV-0042',
            'status' => 'issued',
            'totalAmount' => ['currency' => 'EUR', 'value' => '12.10'],
            '_links' => ['pdfLink' => ['href' => 'https://api.mollie.com/v2/sales-invoices/invoice_test/pdf', 'type' => 'application/pdf']],
        ]),
    ]);
    $affiliate = slackInvoicePartner();

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->assertSchemaStateSet(['post_to_slack' => true])
        ->fillForm(['amount' => '10', 'period' => '2026-09-01'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Posted in Slack #acme-affiliate');

    expect(AffiliateInvoice::sole()->slack_posted_at)->not->toBeNull();
});

it('does not post in slack when switched off for the invoice', function () {
    fakeSlackUpload();
    Mollie::fake([
        CreateSalesInvoiceRequest::class => MockResponse::created(['resource' => 'sales-invoice', 'id' => 'invoice_test', 'invoiceNumber' => 'INV-0042', 'status' => 'issued']),
    ]);
    $affiliate = slackInvoicePartner();

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->fillForm(['amount' => '10', 'period' => '2026-09-01', 'post_to_slack' => false])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'slack.com'));
});

it('keeps the invoice and logs it when the slack message fails', function () {
    Http::fake([
        'api.mollie.com/*' => Http::response('%PDF-1.7 fake pdf'),
        'slack.com/api/*' => Http::response(['ok' => false, 'error' => 'missing_scope']),
    ]);
    Mollie::fake([
        CreateSalesInvoiceRequest::class => MockResponse::created(['resource' => 'sales-invoice', 'id' => 'invoice_test', 'invoiceNumber' => 'INV-0042', 'status' => 'issued']),
    ]);
    $affiliate = slackInvoicePartner();

    Livewire::test(AffiliateInvoices::class)
        ->mountAction(TestAction::make('generateInvoiceForAffiliate')->table($affiliate))
        ->fillForm(['amount' => '10', 'period' => '2026-09-01'])
        ->callMountedAction()
        ->assertNotified('The invoice was sent, but the Slack message failed');

    expect(AffiliateInvoice::sole()->slack_posted_at)->toBeNull()
        ->and(IntegrationError::where('integration', Integration::Slack)->where('action', 'Post invoice message')->count())->toBe(1);
});

it('lets you set the slack contact\'s language in the slack settings', function () {
    $affiliate = slackInvoicePartner(['slack_contact_language' => null, 'slack_contact_member_id' => null]);

    Livewire::test(AffiliateSlackChannels::class)
        ->assertTableColumnStateSet('slack_contact_language', 'Dutch', $affiliate)
        ->callAction(TestAction::make('editContact')->table($affiliate), data: [
            'slack_contact_language' => InvoiceLanguage::French->value,
        ])
        ->assertHasNoActionErrors();

    expect($affiliate->refresh()->slack_contact_language)->toBe(InvoiceLanguage::French);
});

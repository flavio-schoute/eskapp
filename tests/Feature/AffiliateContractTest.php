<?php

use App\Enums\ContractStatus;
use App\Enums\Integration;
use App\Filament\Pages\Contracts;
use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Jobs\StoreSignedContract;
use App\Models\Affiliate;
use App\Models\AffiliateContract;
use App\Models\IntegrationError;
use App\Models\User;
use App\Services\AffiliateContracts;
use App\Support\ContractTerms;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

const ContractTemplateId = 'template-123';

beforeEach(function () {
    config([
        'services.esignatures.secret_token' => 'esignatures-secret',
        'services.esignatures.template_id' => ContractTemplateId,
        'services.esignatures.test_mode' => true,
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    Http::preventStrayRequests();

    actingAs(User::factory()->create());
});

/**
 * Fake the eSignatures API: a template using a few placeholders, contract creation and a contract lookup.
 */
function fakeESignatures(string $remoteStatus = 'sent'): void
{
    Http::fake([
        'esignatures.com/api/templates/*' => Http::response(['data' => [
            'template_id' => ContractTemplateId,
            'placeholder_fields' => ['partner_naam', 'partner_rechtsvorm_kvk', 'partner_plaats', 'vergoeding', 'overige_afspraken', 'ingangsdatum', 'opzegtermijn'],
        ]]),
        'esignatures.com/api/contracts/*' => Http::response(['data' => ['contract' => [
            'id' => 'contract-1',
            'status' => $remoteStatus,
            'contract_pdf_url' => $remoteStatus === 'signed' ? 'https://files.esignatures.test/contract-1.pdf' : null,
        ]]]),
        'esignatures.com/api/contracts' => Http::response(['status' => 'queued', 'data' => ['contract' => [
            'id' => 'contract-1',
            'status' => 'sent',
            'signers' => [
                ['id' => 'signer-1', 'email' => 'jan@acme.test', 'sign_page_url' => 'https://esignatures.com/sign/signer-1'],
                ['id' => 'signer-2', 'email' => 'ops@e-skool.nl', 'sign_page_url' => 'https://esignatures.com/sign/signer-2'],
            ],
        ]]]),
    ]);
}

function signedWebhook(array $payload, ?string $secret = 'esignatures-secret'): TestResponse
{
    $body = json_encode($payload);

    return postJson(route('webhooks.esignatures'), $payload, [
        'X-Signature-SHA256' => hash_hmac('sha256', $body, (string) $secret),
    ]);
}

/**
 * @return array<string, mixed>
 */
function contractTerms(array $overrides = []): array
{
    return [
        'partner_name' => 'Acme Partners',
        'legal_form' => 'B.V.',
        'registration_number' => '12345678',
        'city' => 'Utrecht',
        'compensation_basis' => ContractTerms::PercentageOfRevenue,
        'compensation_amount' => '20',
        'compensation' => ContractTerms::compensationText(ContractTerms::PercentageOfRevenue, 20),
        'other_agreements' => '',
        'start_date' => '2026-10-15',
        'notice_period' => '1 week',
        ...$overrides,
    ];
}

it('fills the template placeholders with the contract terms', function () {
    fakeESignatures();
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['invoice_country' => 'NL']));

    $contract = app(AffiliateContracts::class)->send($affiliate, 'Jan Jansen', 'jan@acme.test', contractTerms());

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://esignatures.com/api/contracts') {
            return false;
        }

        $placeholders = collect($request['placeholder_fields'])->pluck('replace_with_text', 'placeholder_key')->all();

        return $request['template_id'] === ContractTemplateId
            && $request['test'] === 'yes'
            && $request['locale'] === 'nl'
            && $request['signers'][0]['email'] === 'jan@acme.test'
            && $request['signers'][0]['signature_request_delivery_methods'] === ['email']
            && $request['signers'][1]['name'] === 'Gairo Juliana'
            && $request['signers'][1]['email'] === 'ops@e-skool.nl'
            && $request['signers'][1]['auto_sign'] === 'yes'
            && $request['signers'][1]['signature_request_delivery_methods'] === []
            && $placeholders === [
                'partner_naam' => 'Acme Partners',
                'partner_rechtsvorm_kvk' => 'B.V., KvK-nummer 12345678',
                'partner_plaats' => 'Utrecht',
                'vergoeding' => 'e-Skool ontvangt van Partner een vergoeding van 20% over de omzet die Partner realiseert met Aangebrachte Klanten, zoals nader overeengekomen tussen Partijen.',
                'overige_afspraken' => 'Geen.',
                'ingangsdatum' => '15 oktober 2026',
                'opzegtermijn' => '1 week',
            ]
            && ! isset($request['custom_webhook_url'])
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('esignatures-secret:'));
    });

    expect($contract)
        ->status->toBe(ContractStatus::Sent)
        ->esignatures_contract_id->toBe('contract-1')
        ->sign_page_url->toBe('https://esignatures.com/sign/signer-1')
        ->fields->toMatchArray(['legal_form' => 'B.V.', 'compensation_amount' => '20']);
});

it('writes article 4.1 for each compensation basis', function (string $basis, ?string $amount, string $expected) {
    expect(ContractTerms::compensationText($basis, $amount))->toBe($expected);
})->with([
    'percentage' => [ContractTerms::PercentageOfRevenue, '12.5', 'e-Skool ontvangt van Partner een vergoeding van 12,5% over de omzet die Partner realiseert met Aangebrachte Klanten, zoals nader overeengekomen tussen Partijen.'],
    'fixed amount of revenue' => [ContractTerms::FixedAmountOfRevenue, '250', 'e-Skool ontvangt van Partner een vergoeding van een vast bedrag van € 250,- per maand over de omzet die Partner realiseert met Aangebrachte Klanten, zoals nader overeengekomen tussen Partijen.'],
    'per customer' => [ContractTerms::FixedAmountPerCustomer, '99.95', 'e-Skool ontvangt van Partner een vergoeding van een vast bedrag van € 99,95 per Aangebrachte Klant, zoals nader overeengekomen tussen Partijen.'],
    'no amount yet' => [ContractTerms::PercentageOfRevenue, null, 'e-Skool ontvangt van Partner een vergoeding van [PERCENTAGE] over de omzet die Partner realiseert met Aangebrachte Klanten, zoals nader overeengekomen tussen Partijen.'],
]);

it('writes the legal form and registration number after the partner name', function (?string $legalForm, ?string $number, ?string $country, string $expected) {
    expect(ContractTerms::legalFormAndRegistration($legalForm, $number, $country))->toBe($expected);
})->with([
    'dutch' => ['B.V.', '12345678', 'NL', 'B.V., KvK-nummer 12345678'],
    'foreign' => ['Buitenlandse onderneming', '0123.456.789', 'BE', 'Buitenlandse onderneming, registratienummer 0123.456.789'],
    'no registration number' => ['Eenmanszaak', null, 'NL', 'Eenmanszaak'],
]);

it('generates a contract from the contracts page with the affiliate details filled in', function () {
    fakeESignatures();
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create([
        'invoice_company_name' => 'Acme Partners B.V.',
        'invoice_kvk_number' => '87654321',
        'invoice_city' => 'Zeist',
        'invoice_contact_person' => 'Jan Jansen',
        'invoice_email' => 'jan@acme.test',
    ]));

    Livewire::test(Contracts::class)
        ->mountAction(TestAction::make('generateContract')->table())
        ->fillForm(['affiliate_id' => $affiliate->getKey()])
        ->assertSchemaStateSet([
            'partner_name' => 'Acme Partners B.V.',
            'registration_number' => '87654321',
            'city' => 'Zeist',
            'signer_email' => 'jan@acme.test',
            'notice_period' => '1 week',
            'start_date' => now()->toDateString(),
        ])
        ->fillForm(['legal_form' => 'B.V.', 'compensation_basis' => ContractTerms::FixedAmountPerCustomer, 'compensation_amount' => '50'])
        ->assertSchemaStateSet(['compensation' => ContractTerms::compensationText(ContractTerms::FixedAmountPerCustomer, 50)])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Contract sent to jan@acme.test');

    expect($affiliate->latestContract)->signer_name->toBe('Jan Jansen')->fields->toMatchArray(['compensation_amount' => '50']);
});

it('keeps a custom article 4.1 text', function () {
    fakeESignatures();
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create());

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getKey()])
        ->mountAction('generateContract')
        ->fillForm(['compensation_basis' => ContractTerms::CustomCompensation])
        ->fillForm(['compensation' => 'e-Skool ontvangt van Partner € 100,- per kwartaal.', 'city' => 'Utrecht', 'signer_name' => 'Jan', 'signer_email' => 'jan@acme.test'])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect($affiliate->latestContract->fields['compensation'])->toBe('e-Skool ontvangt van Partner € 100,- per kwartaal.');
});

it('requires the partner details before sending', function () {
    fakeESignatures();
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['invoice_city' => null, 'invoice_email' => null]));

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getKey()])
        ->callAction('generateContract', ['signer_email' => 'not-an-email'])
        ->assertHasFormErrors(['city' => 'required', 'signer_email' => 'email']);

    expect(AffiliateContract::count())->toBe(0);
});

it('logs a contract that could not be sent', function () {
    Http::fake(['esignatures.com/*' => Http::response(['error' => 'Invalid template'], 422)]);
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->withInvoiceDetails()->create(['invoice_city' => 'Utrecht']));

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getKey()])
        ->callAction('generateContract', ['signer_name' => 'Jan', 'signer_email' => 'jan@acme.test'])
        ->assertNotified('The contract could not be sent');

    expect(IntegrationError::sole())->integration->toBe(Integration::ESignatures)->action->toBe('Send contract');
});

it('rejects webhooks that are not signed with the secret token', function () {
    $contract = AffiliateContract::factory()->create(['esignatures_contract_id' => 'contract-1']);

    signedWebhook(['status' => 'contract-signed', 'data' => ['contract' => ['id' => 'contract-1']]], secret: 'wrong')
        ->assertForbidden();

    expect($contract->fresh()->status)->toBe(ContractStatus::Sent);
});

it('marks a contract as viewed and signed from webhooks and stores the signed pdf', function () {
    Queue::fake();
    $contract = AffiliateContract::factory()->create(['esignatures_contract_id' => 'contract-1']);

    signedWebhook(['status' => 'signer-viewed-the-contract', 'data' => ['contract' => ['id' => 'contract-1']]])->assertNoContent();
    expect($contract->fresh()->status)->toBe(ContractStatus::Viewed);

    signedWebhook(['status' => 'contract-signed', 'data' => ['contract' => ['id' => 'contract-1']]])->assertNoContent();
    expect($contract->fresh())->status->toBe(ContractStatus::Signed)->signed_at->not->toBeNull();

    Queue::assertPushed(fn (StoreSignedContract $job): bool => $job->contractId === $contract->getKey());
});

it('stores the signed contract in the drive folder as the agreement', function () {
    config(['services.google_drive.refresh_token' => 'refresh-token', 'services.google_drive.affiliates_folder_id' => 'parent']);
    fakeESignatures('signed');
    Http::fake([
        'files.esignatures.test/*' => Http::response('%PDF-signed'),
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'token', 'expires_in' => 3599]),
        'www.googleapis.com/upload/drive/v3/files?*' => Http::response(headers: ['Location' => 'https://www.googleapis.com/upload/session?upload_id=abc']),
        'www.googleapis.com/upload/session?*' => Http::response(['id' => 'signed-file', 'name' => 'Signed.pdf', 'webViewLink' => 'https://drive.google.com/file/d/signed-file/view']),
    ]);
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['google_drive_folder_id' => 'folder-1']));
    $contract = AffiliateContract::factory()->signed()->for($affiliate)->create(['esignatures_contract_id' => 'contract-1']);

    app(AffiliateContracts::class)->storeSignedPdf($contract);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://www.googleapis.com/upload/session?upload_id=abc' && $request->body() === '%PDF-signed');
    expect($contract->fresh()->drive_url)->toBe('https://drive.google.com/file/d/signed-file/view')
        ->and($affiliate->fresh()->agreement_drive_file_id)->toBe('signed-file');
});

it('picks up signed contracts when a webhook was missed', function () {
    Queue::fake();
    fakeESignatures('signed');
    $contract = AffiliateContract::factory()->create(['esignatures_contract_id' => 'contract-1']);

    artisan('contracts:sync-status')->assertSuccessful();

    expect($contract->fresh()->status)->toBe(ContractStatus::Signed);
    Queue::assertPushed(StoreSignedContract::class);
});

<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\Integration;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Jobs\SyncAffiliateIntegrations;
use App\Jobs\UploadAffiliateAgreement;
use App\Models\Affiliate;
use App\Models\IntegrationError;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    actingAs(User::factory()->create());
});

it('syncs drive, slack and mollie in the background instead of while saving', function () {
    Queue::fake();

    $affiliate = Affiliate::factory()->create();
    $affiliate->update(['name' => 'Renamed', 'notes' => 'Changed']);

    Queue::assertPushed(SyncAffiliateIntegrations::class, 2);
    Queue::assertPushed(fn (SyncAffiliateIntegrations $job): bool => $job->affiliateId === $affiliate->getKey()
        && in_array('name', $job->changedAttributes, true)
        && in_array('notes', $job->changedAttributes, true));
});

it('uploads the agreement in the background and keeps the file until then', function () {
    Queue::fake();
    Storage::fake('local');

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Acme',
            'type' => AffiliateType::Affiliate->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://acme.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Automatic->value,
            'agreement_upload' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    Queue::assertPushed(function (UploadAffiliateAgreement $job): bool {
        Storage::disk('local')->assertExists($job->path);

        return $job->affiliateId === Affiliate::sole()->getKey() && $job->originalName === 'agreement.pdf';
    });
});

it('logs a failed agreement upload and removes the stored file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('agreement-uploads/file.pdf', '%PDF');
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create());

    (new UploadAffiliateAgreement($affiliate->getKey(), 'agreement-uploads/file.pdf', 'agreement.pdf'))
        ->failed(new RuntimeException('Google Drive is not configured.'));

    expect(IntegrationError::where('integration', Integration::GoogleDrive)->sole()->action)->toBe('Upload agreement');
    Storage::disk('local')->assertMissing('agreement-uploads/file.pdf');
});

it('retries a failed agreement upload', function () {
    $job = new UploadAffiliateAgreement(1, 'agreement-uploads/file.pdf', 'agreement.pdf');

    expect($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120]);
});

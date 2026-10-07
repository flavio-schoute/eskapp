<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\Integration;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\IntegrationErrors\IntegrationErrorResource;
use App\Filament\Resources\IntegrationErrors\Pages\ManageIntegrationErrors;
use App\Models\Affiliate;
use App\Models\IntegrationError;
use App\Models\User;
use App\Services\IntegrationErrorLogger;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('writes failed steps to the integrations log file with the affiliate', function () {
    $affiliate = Affiliate::factory()->create(['name' => 'Acme']);
    $channel = Mockery::spy();
    Log::shouldReceive('channel')->with('integrations')->andReturn($channel);

    app(IntegrationErrorLogger::class)->record(Integration::Slack, 'Create channel', new RuntimeException('missing_scope'), $affiliate);

    $channel->shouldHaveReceived('error')->with('Slack: Create channel failed', Mockery::on(
        fn (array $context): bool => $context['affiliate_id'] === $affiliate->getKey()
            && $context['affiliate'] === 'Acme'
            && $context['message'] === 'missing_scope',
    ));
});

it('counts the same unresolved error on one row', function () {
    $affiliate = Affiliate::factory()->create();
    $logger = app(IntegrationErrorLogger::class);

    $logger->record(Integration::Slack, 'Create channel', new RuntimeException('missing_scope'), $affiliate);
    $logger->record(Integration::Slack, 'Create channel', new RuntimeException('missing_scope'), $affiliate);
    $logger->record(Integration::Slack, 'Create channel', new RuntimeException('channel_limit'), $affiliate);

    expect(IntegrationError::count())->toBe(2)
        ->and(IntegrationError::where('message', 'missing_scope')->sole()->occurrences)->toBe(2);
});

it('starts a new row when the same error comes back after it was resolved', function () {
    $affiliate = Affiliate::factory()->create();
    $logger = app(IntegrationErrorLogger::class);

    $logger->record(Integration::GoogleDrive, 'Create folder', new RuntimeException('Not Found'), $affiliate)
        ->update(['resolved_at' => now()]);
    $logger->record(Integration::GoogleDrive, 'Create folder', new RuntimeException('Not Found'), $affiliate);

    expect(IntegrationError::count())->toBe(2)
        ->and(IntegrationError::unresolved()->count())->toBe(1);
});

it('logs a failure from saving outside the form', function () {
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test-token']);
    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'missing_scope'])]);

    $affiliate = Affiliate::factory()->create();

    expect(IntegrationError::sole())
        ->integration->toBe(Integration::Slack)
        ->action->toBe('Create channel')
        ->affiliate_id->toBe($affiliate->getKey())
        ->message->toBe('Slack conversations.create failed: missing_scope');
});

it('logs a failure from the form and tells the user where to find it', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Acme',
            'type' => AffiliateType::Affiliate->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://acme.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Invoice->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved, but Google Drive sync failed');

    expect(IntegrationError::where('integration', Integration::GoogleDrive)->sole())
        ->action->toBe('Create folder')
        ->message->toBe('Google Drive is not configured.');
});

it('shows unresolved errors by default', function () {
    $unresolved = IntegrationError::factory()->create();
    $resolved = IntegrationError::factory()->resolved()->create();

    Livewire::test(ManageIntegrationErrors::class)
        ->assertCanSeeTableRecords([$unresolved])
        ->assertCanNotSeeTableRecords([$resolved]);
});

it('can mark an error as resolved', function () {
    $error = IntegrationError::factory()->create();

    Livewire::test(ManageIntegrationErrors::class)
        ->callAction(TestAction::make('resolve')->table($error))
        ->assertNotified('Error marked as resolved')
        ->assertCanNotSeeTableRecords([$error]);

    expect($error->refresh()->isResolved())->toBeTrue();
});

it('shows the number of unresolved errors in the menu', function () {
    expect(IntegrationErrorResource::getNavigationBadge())->toBeNull();

    IntegrationError::factory()->count(2)->create();
    IntegrationError::factory()->resolved()->create();

    expect(IntegrationErrorResource::getNavigationBadge())->toBe('2');
});

it('keeps the error when the affiliate is deleted', function () {
    $affiliate = Affiliate::factory()->create();
    $error = IntegrationError::factory()->create(['affiliate_id' => $affiliate->getKey()]);

    $affiliate->delete();

    expect($error->refresh()->affiliate_id)->toBeNull();
});

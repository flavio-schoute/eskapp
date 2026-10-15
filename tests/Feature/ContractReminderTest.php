<?php

use App\Filament\Resources\SlackMembers\Pages\ManageSlackMembers;
use App\Models\ActivityLog;
use App\Models\AffiliateContract;
use App\Models\User;
use App\Support\ContractReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.esignatures.secret_token' => 'esignatures-secret',
        'services.esignatures.template_id' => 'template-123',
        'services.slack.notifications.bot_user_oauth_token' => 'xoxb-test',
        'services.google_drive.refresh_token' => null,
    ]);
    Http::preventStrayRequests();
    Http::fake([
        'esignatures.com/*' => Http::response(['status' => 'queued']),
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);
    ContractReminder::save(['enabled' => true, 'days' => 5, 'remind_partner' => true, 'channel' => 'C0ADMIN', 'message' => '{partner}: {signer} ({email}) na {days} dagen']);
});

it('reminds the partner and the team once when a contract is not signed after 5 days', function () {
    $contract = AffiliateContract::factory()->create([
        'esignatures_contract_id' => 'contract-1',
        'esignatures_signer_id' => 'signer-1',
        'signer_name' => 'Jan',
        'signer_email' => 'jan@acme.test',
        'fields' => ['partner_name' => 'Acme B.V.'],
        'created_at' => now()->subDays(5)->subHour(),
    ]);
    $recent = AffiliateContract::factory()->create(['created_at' => now()->subDays(2)]);
    $signed = AffiliateContract::factory()->signed()->create(['created_at' => now()->subDays(10)]);

    artisan('contracts:send-reminders')->assertSuccessful();
    artisan('contracts:send-reminders')->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://esignatures.com/api/contracts/contract-1/signers/signer-1/send_contract');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/chat.postMessage' && $request['channel'] === 'C0ADMIN' && $request['text'] === 'Acme B.V.: Jan (jan@acme.test) na 5 dagen');

    expect($contract->fresh()->reminded_at)->not->toBeNull()
        ->and($recent->fresh()->reminded_at)->toBeNull()
        ->and($signed->fresh()->reminded_at)->toBeNull()
        ->and(ActivityLog::where('event', 'contract_reminder')->sole()->description)->toBe('Reminded Jan to sign the contract');
});

it('finds the signer of contracts sent before the signer id was stored', function () {
    $contract = AffiliateContract::factory()->make(['esignatures_signer_id' => null, 'sign_page_url' => 'https://esignatures.com/sign/abc-123']);

    expect($contract->signerId())->toBe('abc-123');
});

it('does not remind when turned off', function () {
    ContractReminder::save(['enabled' => false, 'days' => 5, 'remind_partner' => true, 'channel' => 'C0ADMIN', 'message' => 'x']);
    AffiliateContract::factory()->create(['created_at' => now()->subDays(9)]);

    artisan('contracts:send-reminders')->assertSuccessful();

    Http::assertNothingSent();
});

it('changes the contract reminder under slack settings', function () {
    actingAs(User::factory()->create());

    Livewire::test(ManageSlackMembers::class)
        ->callAction('contractReminder', [
            'enabled' => true,
            'days' => 7,
            'remind_partner' => false,
            'channel' => 'C0TEAM123',
            'message' => '{partner} heeft nog niet getekend',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('Contract reminder saved');

    expect(ContractReminder::settings())->toMatchArray(['days' => 7, 'remind_partner' => false, 'channel' => 'C0TEAM123', 'message' => '{partner} heeft nog niet getekend']);
});

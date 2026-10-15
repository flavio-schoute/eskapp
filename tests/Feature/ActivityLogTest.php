<?php

use App\Enums\AffiliateStatus;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Filament\Resources\Affiliates\RelationManagers\ActivityLogsRelationManager;
use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\AffiliateContract;
use App\Models\AffiliateInvoice;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);
});

it('logs who created, changed and deleted an affiliate', function () {
    actingAs($user = User::factory()->create(['name' => 'Flavio']));

    $affiliate = Affiliate::factory()->create(['name' => 'Acme', 'status' => AffiliateStatus::Active, 'invoice_city' => 'Zeist']);
    $affiliate->update(['invoice_city' => 'Utrecht', 'notes' => 'Belt elke maandag']);
    $affiliate->update(['status' => AffiliateStatus::OnHold]);
    $affiliate->delete();

    $logs = ActivityLog::query()->orderBy('id')->get();

    expect($logs->pluck('description')->all())->toBe([
        'Created the affiliate',
        'Updated Notes and City',
        'Changed the status to On hold',
        'Deleted the affiliate',
    ])
        ->and($logs->pluck('user_name')->unique()->all())->toBe(['Flavio'])
        ->and($logs[1]->changes)->toEqual([
            'City' => ['old' => 'Zeist', 'new' => 'Utrecht'],
            'Notes' => ['old' => null, 'new' => 'Belt elke maandag'],
        ])
        ->and($logs[3]->affiliate_name)->toBe('Acme');
});

it('never logs passwords', function () {
    actingAs(User::factory()->create());
    $affiliate = Affiliate::factory()->create(['password' => 'old-secret']);

    $affiliate->update(['password' => 'new-secret']);

    expect(ActivityLog::latest('id')->first()->changes)->toBe(['Password' => ['old' => null, 'new' => '(changed)']])
        ->and(ActivityLog::query()->pluck('changes')->toJson())->not->toContain('secret');
});

it('logs background changes as done by the system', function () {
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create());

    $affiliate->update(['mollie_customer_id' => 'cst_123']);

    expect(ActivityLog::sole())->causer()->toBe('System')->description->toBe('Updated Mollie customer');
});

it('logs invoices and contracts of an affiliate', function () {
    actingAs(User::factory()->create());
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create());

    AffiliateInvoice::factory()->for($affiliate)->create(['invoice_number' => 'INV-0001', 'period' => '2026-09-01', 'total_amount' => '121.00', 'sent_to' => 'jan@acme.test']);
    $contract = AffiliateContract::factory()->for($affiliate)->create(['signer_name' => 'Jan', 'signer_email' => 'jan@acme.test']);
    $contract->update(['status' => 'signed']);

    expect($affiliate->activityLogs()->orderBy('id')->pluck('description')->all())->toBe([
        'Sent invoice INV-0001 for September 2026 (€ 121,00) to jan@acme.test',
        'Sent the contract to Jan (jan@acme.test)',
        'Jan signed the contract',
    ]);
});

it('shows the activity on the affiliate page, also for viewers', function () {
    actingAs(User::factory()->viewer()->create());
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create());
    $log = ActivityLog::factory()->for($affiliate)->create(['description' => 'Changed the status to On hold']);

    Livewire::test(ActivityLogsRelationManager::class, ['ownerRecord' => $affiliate, 'pageClass' => ViewAffiliate::class])
        ->assertOk()
        ->assertCanSeeTableRecords([$log])
        ->assertSee('Active');
});

it('lists all activity for admins', function () {
    actingAs(User::factory()->create());
    $logs = ActivityLog::factory()->count(2)->create();

    Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords($logs);
});

it('exports the filtered activity to a csv file for excel', function () {
    actingAs(User::factory()->create());
    $acme = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['name' => 'Acme']));
    ActivityLog::factory()->for($acme)->create(['affiliate_name' => 'Acme', 'user_name' => 'Flavio', 'description' => 'Updated City', 'changes' => ['City' => ['old' => 'Zeist', 'new' => 'Utrecht']]]);
    ActivityLog::factory()->create(['affiliate_name' => 'Other', 'description' => 'Created the affiliate']);

    $response = Livewire::test(ListActivityLogs::class)
        ->filterTable('affiliate_id', $acme->getKey())
        ->callAction(TestAction::make('export')->table())
        ->assertFileDownloaded('activity-log-'.now()->format('Y-m-d').'.csv');

    $csv = base64_decode($response->effects['download']['content']);

    expect($csv)->toStartWith("\xEF\xBB\xBFWhen;Affiliate;Who;What;Changes")
        ->toContain('Acme;Flavio;"Updated City";"City: Zeist → Utrecht"')
        ->not->toContain('Other');
});

it('exports the activity of one affiliate from its page', function () {
    actingAs(User::factory()->viewer()->create());
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['name' => 'Acme Partners']));
    ActivityLog::factory()->for($affiliate)->create();

    Livewire::test(ActivityLogsRelationManager::class, ['ownerRecord' => $affiliate, 'pageClass' => ViewAffiliate::class])
        ->callAction(TestAction::make('export')->table())
        ->assertFileDownloaded('activity-log-acme-partners-'.now()->format('Y-m-d').'.csv');
});

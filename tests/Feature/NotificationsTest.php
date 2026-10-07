<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use App\Filament\Resources\Affiliates\Widgets\PipelineAffiliates;
use App\Filament\Resources\SlackMembers\Pages\ManageSlackMembers;
use App\Models\Affiliate;
use App\Models\SlackMember;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
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

it('keeps notifications on screen for 10 seconds', function () {
    expect(Notification::make()->getDuration())->toBe(10000);
});

it('shows the countdown bar styles in the panel', function () {
    get('/admin/affiliates')
        ->assertOk()
        ->assertSee('notification-countdown 10000ms linear', escape: false);
});

it('confirms when an affiliate is created', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Acme',
            'type' => AffiliateType::Affiliate->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://acme.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Automatic->value,
        ])
        ->call('create')
        ->assertNotified('Affiliate Acme created');
});

it('confirms when an affiliate is saved', function () {
    $affiliate = Affiliate::factory()->create(['name' => 'Acme']);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->call('save')
        ->assertNotified('Affiliate Acme saved');
});

it('explains why an affiliate could not be saved', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm(['name' => null])
        ->call('create')
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title('Could not save')
                ->body('Please fix the 6 highlighted fields: The partner name field is required. The type field is required. The login URL (dashboard / backend) field is required. …'),
        );
});

it('confirms a status change in the list and the pipeline block', function () {
    $active = Affiliate::factory()->create(['name' => 'Acme']);
    $pipeline = Affiliate::factory()->pipeline()->create(['name' => 'Prospect']);

    Livewire::test(ListAffiliates::class)
        ->call('updateTableColumnState', 'status', (string) $active->getKey(), AffiliateStatus::OnHold->value)
        ->assertNotified('Acme is now On hold');

    Livewire::test(PipelineAffiliates::class)
        ->call('updateTableColumnState', 'status', (string) $pipeline->getKey(), AffiliateStatus::Active->value)
        ->assertNotified('Prospect is now Active');
});

it('confirms switching a slack member on and off', function () {
    $member = SlackMember::factory()->create(['name' => 'Gairo']);

    Livewire::test(ManageSlackMembers::class)
        ->call('updateTableColumnState', 'is_active', (string) $member->getKey(), false)
        ->assertNotified('Gairo will no longer be added to new channels')
        ->call('updateTableColumnState', 'is_active', (string) $member->getKey(), true)
        ->assertNotified('Gairo will be added to new channels');
});

it('confirms adding a slack member', function () {
    Livewire::test(ManageSlackMembers::class)
        ->callAction('create', data: ['name' => 'New Colleague', 'slack_user_id' => 'U0ABC12345'])
        ->assertNotified('Slack member New Colleague added');
});

it('explains why a slack member could not be added', function () {
    Livewire::test(ManageSlackMembers::class)
        ->callAction('create', data: ['name' => 'Someone', 'slack_user_id' => 'flavio'])
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title('Could not save')
                ->body('A Slack member ID starts with U or W, followed by capital letters and numbers.'),
        );
});

<?php

use App\Filament\Resources\SlackMembers\Pages\ManageSlackMembers;
use App\Models\SlackMember;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->create());
});

it('starts with the team members that were configured before', function () {
    expect(SlackMember::active()->pluck('slack_user_id')->sort()->values()->all())->toBe([
        'U073Z843CGP',
        'U0926AJE52T',
        'U09F6D25ABT',
        'U09JBP2T6J1',
        'U0B774NFKCL',
        'U0C0Z8HKJTD',
    ]);
});

it('requires authentication to manage slack members', function () {
    auth()->logout();

    get('/admin/slack-members')->assertRedirect('/admin/login');
});

it('lists the slack members', function () {
    Livewire::test(ManageSlackMembers::class)
        ->assertOk()
        ->assertCanSeeTableRecords(SlackMember::all());
});

it('can add a slack member with a name and member id', function () {
    Livewire::test(ManageSlackMembers::class)
        ->callAction('create', data: [
            'name' => 'New Colleague',
            'slack_user_id' => ' u0abc12345 ',
        ])
        ->assertHasNoActionErrors();

    expect(SlackMember::where('name', 'New Colleague')->sole())
        ->slack_user_id->toBe('U0ABC12345')
        ->is_active->toBeTrue();
});

it('rejects invalid and duplicate slack member ids', function (string $slackUserId) {
    Livewire::test(ManageSlackMembers::class)
        ->callAction('create', data: [
            'name' => 'Someone',
            'slack_user_id' => $slackUserId,
        ])
        ->assertHasActionErrors(['slack_user_id']);
})->with([
    'not a member id' => ['flavio'],
    'channel id instead of member id' => ['C0C6MJ6LP0F'],
    'already added' => ['U0926AJE52T'],
]);

it('can stop adding a member to new channels without deleting them', function () {
    $member = SlackMember::where('slack_user_id', 'U0926AJE52T')->sole();

    Livewire::test(ManageSlackMembers::class)
        ->call('updateTableColumnState', 'is_active', (string) $member->getKey(), false);

    expect($member->refresh()->is_active)->toBeFalse();
});

it('can delete a slack member', function () {
    $member = SlackMember::factory()->create();

    Livewire::test(ManageSlackMembers::class)
        ->callAction(TestAction::make('delete')->table($member));

    expect(SlackMember::find($member->getKey()))->toBeNull();
});

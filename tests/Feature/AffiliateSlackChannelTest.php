<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Models\Affiliate;
use App\Models\SlackMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.google_drive.refresh_token' => null,
        'services.slack.notifications.bot_user_oauth_token' => 'xoxb-test-token',
    ]);

    SlackMember::query()->delete();
    SlackMember::factory()->create(['slack_user_id' => 'U0926AJE52T']);
    SlackMember::factory()->create(['slack_user_id' => 'U073Z843CGP']);
    SlackMember::factory()->inactive()->create(['slack_user_id' => 'U09JBP2T6J1']);

    Http::preventStrayRequests();

    actingAs(User::factory()->create());
});

/**
 * Fake the Slack Web API. Channel names in $takenNames are reported as already taken.
 *
 * @param  list<string>  $takenNames
 */
function fakeSlack(array $takenNames = []): void
{
    Http::fake([
        'slack.com/api/conversations.create' => fn (Request $request) => in_array($request['name'], $takenNames, true)
            ? Http::response(['ok' => false, 'error' => 'name_taken'])
            : Http::response(['ok' => true, 'channel' => ['id' => 'C123', 'name' => $request['name']]]),
        'slack.com/api/conversations.invite' => Http::response(['ok' => true]),
        'slack.com/api/conversations.rename' => fn (Request $request) => Http::response(['ok' => true, 'channel' => ['id' => $request['channel'], 'name' => $request['name']]]),
    ]);
}

it('builds the channel name from the partner name and type', function (string $name, ?string $type, string $expected) {
    expect(Affiliate::slackChannelNameFor($name, $type))->toBe($expected);
})->with([
    'partnership' => ['Test KFB', 'partnership', 'test-kfb-partnership'],
    'punctuation and company type' => ['DAYONE Fulfillment Co., Ltd', 'software', 'dayone-fulfillment-co-ltd-software'],
    'accents' => ['Café Müller', 'affiliate', 'cafe-muller-affiliate'],
    'no type yet' => ['Test KFB', null, 'test-kfb'],
]);

it('keeps long channel names within the slack limit of 80 characters', function () {
    $name = Affiliate::slackChannelNameFor(str_repeat('Partner ', 20), 'partnership');

    expect(strlen($name))->toBeLessThanOrEqual(80)
        ->and($name)->toEndWith('-partnership')
        ->not->toContain('--');
});

it('creates a private slack channel and invites the active slack members when an affiliate is created', function () {
    fakeSlack();

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Test KFB',
            'type' => AffiliateType::Partnership->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://kfb.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Automatic->value,
        ])
        ->assertSchemaStateSet(['slack_channel_preview' => '#test-kfb-partnership'])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotNotified('Saved, but Slack sync failed');

    $affiliate = Affiliate::sole();

    expect($affiliate->slack_channel_id)->toBe('C123')
        ->and($affiliate->slack_channel_name)->toBe('test-kfb-partnership');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/conversations.create'
        && $request['name'] === 'test-kfb-partnership'
        && $request['is_private'] === true
        && $request->hasHeader('Authorization', 'Bearer xoxb-test-token'));

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/conversations.invite'
        && $request['channel'] === 'C123'
        && $request['users'] === 'U0926AJE52T,U073Z843CGP');
});

it('adds a number when the channel name is already taken', function () {
    fakeSlack(takenNames: ['test-kfb-partnership']);

    $affiliate = Affiliate::factory()->create(['name' => 'Test KFB', 'type' => AffiliateType::Partnership]);

    expect($affiliate->refresh()->slack_channel_name)->toBe('test-kfb-partnership-2');
});

it('renames the channel when the partner name or type changes', function () {
    fakeSlack();

    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create([
        'name' => 'Test KFB',
        'type' => AffiliateType::Partnership,
        'slack_channel_id' => 'C123',
        'slack_channel_name' => 'test-kfb-partnership',
    ]));

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->fillForm(['type' => AffiliateType::Software->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($affiliate->refresh()->slack_channel_name)->toBe('test-kfb-software');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/conversations.rename'
        && $request['channel'] === 'C123'
        && $request['name'] === 'test-kfb-software');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/conversations.create');
});

it('still saves the affiliate and warns when slack returns an error', function () {
    Http::fake(['slack.com/api/*' => Http::response(['ok' => false, 'error' => 'missing_scope'])]);

    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Test KFB',
            'type' => AffiliateType::Partnership->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://kfb.test',
            'username' => 'eskapp',
            'password' => 'secret',
            'payment_method' => AffiliatePaymentMethod::Automatic->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved, but Slack sync failed');

    expect(Affiliate::sole()->slack_channel_id)->toBeNull();
});

it('does not call slack when no bot token is configured', function () {
    config(['services.slack.notifications.bot_user_oauth_token' => null]);

    Affiliate::factory()->create();

    Http::assertNothingSent();
});

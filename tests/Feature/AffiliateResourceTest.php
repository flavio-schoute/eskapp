<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use App\Filament\Resources\Affiliates\Pages\ViewAffiliate;
use App\Models\Affiliate;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

beforeEach(function () {
    actingAs(User::factory()->create());
});

it('requires authentication to view the affiliates list', function () {
    auth()->logout();

    get('/admin/affiliates')->assertRedirect('/admin/login');
});

it('shows only active affiliates by default', function () {
    $active = Affiliate::factory()->count(2)->create();
    $onHold = Affiliate::factory()->onHold()->create();
    $stopped = Affiliate::factory()->stopped()->create();

    Livewire::test(ListAffiliates::class)
        ->assertOk()
        ->assertCanSeeTableRecords($active)
        ->assertCanNotSeeTableRecords([$onHold, $stopped]);
});

it('can filter affiliates by status', function () {
    $active = Affiliate::factory()->create();
    $onHold = Affiliate::factory()->onHold()->create();

    Livewire::test(ListAffiliates::class)
        ->filterTable('status', AffiliateStatus::OnHold->value)
        ->assertCanSeeTableRecords([$onHold])
        ->assertCanNotSeeTableRecords([$active])
        ->removeTableFilters()
        ->assertCanSeeTableRecords([$active, $onHold]);
});

it('can update the status inline from the list', function () {
    $affiliate = Affiliate::factory()->create();

    Livewire::test(ListAffiliates::class)
        ->call('updateTableColumnState', 'status', (string) $affiliate->getKey(), AffiliateStatus::OnHold->value);

    expect($affiliate->refresh()->status)->toBe(AffiliateStatus::OnHold);
});

it('can create an affiliate', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => 'Acme Partners',
            'type' => AffiliateType::Partnership->value,
            'status' => AffiliateStatus::Active->value,
            'login_url' => 'https://dashboard.acme.test',
            'username' => 'eskapp',
            'password' => 'secret-password',
            'agreement' => '20% recurring per sale.',
            'payment_method' => AffiliatePaymentMethod::Automatic->value,
            'affiliate_link' => 'https://acme.test/?ref=eskapp',
            'notes' => 'Contact via e-mail.',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $affiliate = Affiliate::sole();

    expect($affiliate->name)->toBe('Acme Partners')
        ->and($affiliate->type)->toBe(AffiliateType::Partnership)
        ->and($affiliate->payment_method)->toBe(AffiliatePaymentMethod::Automatic)
        ->and($affiliate->password)->toBe('secret-password')
        ->and($affiliate->getRawOriginal('password'))->not->toBe('secret-password');
});

it('requires the basics, access and payment fields', function () {
    Livewire::test(CreateAffiliate::class)
        ->fillForm([
            'name' => null,
            'type' => null,
            'status' => null,
            'login_url' => 'not-a-url',
            'username' => null,
            'password' => null,
            'payment_method' => null,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'name' => 'required',
            'type' => 'required',
            'status' => 'required',
            'login_url' => 'url',
            'username' => 'required',
            'password' => 'required',
            'payment_method' => 'required',
        ]);
});

it('can edit an affiliate and keeps the stored password filled in', function () {
    $affiliate = Affiliate::factory()->create(['password' => 'original-password']);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertSchemaStateSet(['password' => 'original-password'])
        ->fillForm(['status' => AffiliateStatus::Stopped->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $affiliate->refresh();

    expect($affiliate->status)->toBe(AffiliateStatus::Stopped)
        ->and($affiliate->password)->toBe('original-password');
});

it('can view an affiliate', function () {
    $affiliate = Affiliate::factory()->create();

    Livewire::test(ViewAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->assertOk()
        ->assertSee($affiliate->name);
});

it('can delete an affiliate from the list', function () {
    $affiliate = Affiliate::factory()->create();

    Livewire::test(ListAffiliates::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($affiliate));

    assertDatabaseMissing($affiliate);
});

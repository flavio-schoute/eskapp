<?php

use App\Enums\UserRole;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Pages\Contracts;
use App\Filament\Pages\Debtors;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\IntegrationErrors\IntegrationErrorResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\SlackMembers\SlackMemberResource;
use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

it('lets viewers work with affiliates, invoices and contracts', function () {
    actingAs(User::factory()->viewer()->create());

    get(AffiliateResource::getUrl())->assertOk()->assertDontSee('Integration errors');
    get(AffiliateResource::getUrl('create'))->assertOk();
    get(AffiliateInvoices::getUrl())->assertOk();
    get(Contracts::getUrl())->assertOk();
});

it('keeps viewers out of the system settings', function (string $resource) {
    actingAs(User::factory()->viewer()->create());

    get($resource::getUrl())->assertForbidden();
})->with([
    'templates' => MessageTemplateResource::class,
    'slack settings' => SlackMemberResource::class,
    'integration errors' => IntegrationErrorResource::class,
    'users' => UserResource::class,
    'activity log' => ActivityLogResource::class,
]);

it('lets admins open the system settings', function () {
    actingAs(User::factory()->create());

    get(UserResource::getUrl())->assertOk()->assertSee(['Templates', 'Slack settings', 'Integration errors', 'Users', 'Activity log']);
    get(ActivityLogResource::getUrl())->assertOk();
});

it('makes users created on the command line admins', function () {
    artisan('make:filament-user', ['--name' => 'Flavio', '--email' => 'flavio@e-skool.nl', '--password' => 'secret'])
        ->assertSuccessful();

    expect(User::sole()->role)->toBe(UserRole::Admin);
});

it('adds a colleague as viewer', function () {
    actingAs(User::factory()->create());

    Livewire::test(ManageUsers::class)
        ->callAction('create', [
            'name' => 'Gairo',
            'email' => 'gairo@e-skool.nl',
            'role' => UserRole::Viewer->value,
            'password' => 'secret-password',
        ])
        ->assertHasNoFormErrors()
        ->assertNotified('User Gairo added as Viewer');

    $user = User::firstWhere('email', 'gairo@e-skool.nl');

    expect($user->role)->toBe(UserRole::Viewer)
        ->and(Hash::check('secret-password', $user->password))->toBeTrue();
});

it('keeps the password when it is left empty while editing', function () {
    actingAs(User::factory()->create());
    $colleague = User::factory()->viewer()->create();
    $password = $colleague->password;

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('edit')->table($colleague), ['name' => 'New name', 'role' => UserRole::Admin->value, 'password' => ''])
        ->assertHasNoFormErrors();

    expect($colleague->fresh())
        ->name->toBe('New name')
        ->role->toBe(UserRole::Admin)
        ->password->toBe($password);
});

it('does not let admins delete themselves or demote themselves', function () {
    actingAs($admin = User::factory()->create());

    Livewire::test(ManageUsers::class)
        ->assertActionHidden(TestAction::make('delete')->table($admin))
        ->mountAction(TestAction::make('edit')->table($admin))
        ->assertFormFieldDisabled('role');
});

it('sends a colleague a password reset link', function () {
    Notification::fake();
    actingAs(User::factory()->create());
    $colleague = User::factory()->viewer()->create();

    Livewire::test(ManageUsers::class)
        ->callAction(TestAction::make('sendPasswordReset')->table($colleague))
        ->assertNotified("Password reset link sent to {$colleague->email}");

    Notification::assertSentTo($colleague, ResetPassword::class);
});

it('shows debiteurenbeheer to admins only', function () {
    actingAs(User::factory()->create());
    get(Debtors::getUrl())->assertOk()->assertSee('Debiteurenbeheer');

    actingAs(User::factory()->viewer()->create());
    get(Debtors::getUrl())->assertForbidden();
});

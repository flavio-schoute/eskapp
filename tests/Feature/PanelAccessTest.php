<?php

use App\Filament\Pages\Debtors;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

it('lets e-skool staff into the panel', function (string $email) {
    actingAs(User::factory()->create(['email' => $email]));

    get('/admin')->assertOk();
})->with([
    'staff' => 'flavio@e-skool.nl',
    'uppercase domain' => 'Gairo@E-Skool.NL',
]);

// Disabled for now: every user account may use the panel (see User::canAccessPanel()).
// Re-enable when the panel is limited to @e-skool.nl addresses again.
// it('keeps everyone else out of the panel', function (string $email) {
//     actingAs(User::factory()->create(['email' => $email]));
//
//     get('/admin')->assertForbidden();
//     get('/admin/affiliates')->assertForbidden();
// })->with([
//     'other domain' => 'admin@gmail.com',
//     'look-alike domain' => 'someone@note-skool.nl',
//     'domain as part of another domain' => 'someone@e-skool.nl.example.com',
//     'subdomain' => 'someone@mail.e-skool.nl',
// ]);

it('sends guests to the login page', function () {
    get('/admin')->assertRedirect('/admin/login');
});

it('uses the e-skool icons in the panel for every browser', function () {
    get('/admin/login')
        ->assertOk()
        ->assertSee('<link rel="icon" href="'.asset('favicon-eskool.ico').'" sizes="32x32" />', escape: false)
        ->assertSee('<link rel="icon" href="'.asset('favicon-eskool.svg').'" type="image/svg+xml" />', escape: false)
        ->assertSee('<link rel="apple-touch-icon" href="'.asset('favicon-eskool-apple-touch.png').'" />', escape: false)
        ->assertSee('<link rel="manifest" href="'.asset('site.webmanifest').'" />', escape: false);
});

it('ships every icon file the pages and manifest refer to', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    $files = [
        'favicon.ico',
        'favicon-eskool.ico',
        'favicon-eskool.svg',
        'favicon-eskool-apple-touch.png',
        ...array_map(fn (array $icon): string => ltrim($icon['src'], '/'), $manifest['icons']),
    ];

    foreach ($files as $file) {
        expect(public_path($file))->toBeFile();
    }

    $dimensions = fn (string $file): array => array_slice(getimagesize(public_path($file)), 0, 2);

    expect($dimensions('favicon-eskool.png'))->toBe([512, 512])
        ->and($dimensions('favicon-eskool-192.png'))->toBe([192, 192])
        ->and($dimensions('favicon-eskool-apple-touch.png'))->toBe([180, 180])
        ->and(file_get_contents(public_path('favicon-eskool.svg')))->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"');
});

it('lets users reset a forgotten password', function () {
    Notification::fake();
    $user = User::factory()->create(['email' => 'flavio@e-skool.nl']);

    get('/admin/login')->assertSee(Filament\Facades\Filament::getRequestPasswordResetUrl());

    Livewire\Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => 'flavio@e-skool.nl'])
        ->call('request')
        ->assertHasNoFormErrors();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('links each group icon in the collapsed sidebar to the first item of the group', function () {
    actingAs(User::factory()->create());

    $html = get('/admin')->assertOk()->getContent();

    expect($html)
        ->toMatch('~href="'.preg_quote(AffiliateResource::getUrl(), '~').'"[^>]*aria-label="Affiliates"~s')
        ->toMatch('~href="'.preg_quote(Debtors::getUrl(), '~').'"[^>]*aria-label="Debiteurenbeheer"~s');
});

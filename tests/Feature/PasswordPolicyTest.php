<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\InvalidOptionException;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Passwords that appeared in data breaches are rejected in production; none of these test passwords have.
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
});

function runInProduction(): void
{
    app()->instance('env', 'production');
}

it('requires a strong password in production', function (string $password) {
    runInProduction();

    expect(Validator::make(['password' => $password], ['password' => Password::defaults()])->fails())->toBeTrue();
})->with([
    'too short' => 'Sh0rt!pass',
    'no capital' => 'longpassword1!',
    'no number' => 'LongPassword!!',
    'no symbol' => 'LongPassword123',
]);

it('accepts a strong password in production', function () {
    runInProduction();

    expect(Validator::make(['password' => 'Correct-Horse-42-Battery'], ['password' => Password::defaults()])->passes())->toBeTrue();
});

it('does not require a strong password outside production', function () {
    expect(app()->isProduction())->toBeFalse()
        ->and(Validator::make(['password' => 'secret'], ['password' => Password::defaults()])->passes())->toBeTrue();
});

it('refuses a weak password when creating a user in production', function () {
    runInProduction();

    expect(fn () => artisan('make:filament-user', [
        '--name' => 'Flavio',
        '--email' => 'flavio@e-skool.nl',
        '--password' => 'password',
    ]))->toThrow(InvalidOptionException::class);

    expect(User::count())->toBe(0);
});

it('creates a user with a strong password in production', function () {
    runInProduction();

    artisan('make:filament-user', [
        '--name' => 'Flavio',
        '--email' => 'flavio@e-skool.nl',
        '--password' => 'Correct-Horse-42-Battery',
    ])->assertSuccessful();

    expect(Hash::check('Correct-Horse-42-Battery', User::sole()->password))->toBeTrue();
});

it('creates a user with any password outside production', function () {
    artisan('make:filament-user', [
        '--name' => 'Flavio',
        '--email' => 'flavio@e-skool.nl',
        '--password' => 'secret',
    ])->assertSuccessful();

    expect(User::sole()->email)->toBe('flavio@e-skool.nl');
});

it('only creates users with an e-skool email address', function () {
    expect(fn () => artisan('make:filament-user', [
        '--name' => 'Someone',
        '--email' => 'someone@gmail.com',
        '--password' => 'secret',
    ]))->toThrow(InvalidOptionException::class, 'Only @e-skool.nl email addresses can use the panel.');

    expect(User::count())->toBe(0);
});

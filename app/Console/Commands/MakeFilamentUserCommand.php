<?php

namespace App\Console\Commands;

use App\Models\User;
use Filament\Commands\MakeUserCommand;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Console\Exception\InvalidOptionException;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Filament's make:filament-user, but only for e-Skool staff and with the app's password rules
 * (strong passwords in production).
 */
class MakeFilamentUserCommand extends MakeUserCommand
{
    /**
     * @return array{'name': string, 'email': string, 'password': string}
     */
    protected function getUserData(): array
    {
        return [
            'name' => $this->options['name'] ?? text(
                label: 'Name',
                required: true,
            ),
            'email' => $this->validatedOption('email', fn (string $email): ?string => $this->emailError($email)) ?? text(
                label: 'Email address',
                placeholder: 'name@'.User::StaffEmailDomain,
                required: true,
                validate: fn (string $email): ?string => $this->emailError($email),
            ),
            'password' => Hash::make($this->validatedOption('password', fn (string $password): ?string => $this->passwordError($password)) ?? password(
                label: 'Password',
                required: true,
                validate: fn (string $password): ?string => $this->passwordError($password),
                hint: app()->isProduction() ? 'At least 12 characters with upper and lower case letters, a number and a symbol.' : '',
            )),
        ];
    }

    /**
     * Validate a value passed as a command option, failing the command when it is invalid.
     */
    protected function validatedOption(string $option, callable $validate): ?string
    {
        $value = $this->options[$option] ?? null;

        if ($value !== null && ($error = $validate($value))) {
            throw new InvalidOptionException($error);
        }

        return $value;
    }

    protected function emailError(string $email): ?string
    {
        return match (true) {
            ! filter_var($email, FILTER_VALIDATE_EMAIL) => 'The email address must be valid.',
            ! Str::of($email)->lower()->endsWith('@'.User::StaffEmailDomain) => 'Only @'.User::StaffEmailDomain.' email addresses can use the panel.',
            static::getUserModel()::query()->where('email', $email)->exists() => 'A user with this email address already exists',
            default => null,
        };
    }

    protected function passwordError(string $password): ?string
    {
        $validator = Validator::make(['password' => $password], ['password' => ['required', Password::defaults()]]);

        return $validator->fails() ? implode(' ', $validator->errors()->get('password')) : null;
    }
}

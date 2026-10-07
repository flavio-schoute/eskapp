<?php

namespace App\Providers;

use App\Console\Commands\MakeFilamentUserCommand;
use Filament\Commands\MakeUserCommand;
use Filament\Notifications\Notification;
use Filament\Pages\BasePage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * How long notifications stay on screen, in milliseconds.
     */
    public const NotificationDuration = 10000;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MakeUserCommand::class, MakeFilamentUserCommand::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Strong passwords on the live site; no strength rules locally or in tests.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(12)->letters()->mixedCase()->numbers()->symbols()->uncompromised()
            : Password::min(1));

        Notification::configureUsing(fn (Notification $notification): Notification => $notification->duration(self::NotificationDuration));

        BasePage::$reportValidationErrorUsing = function (ValidationException $exception): void {
            $messages = collect($exception->errors())->flatten()->unique();

            Notification::make()
                ->danger()
                ->title('Could not save')
                ->body($messages->count() === 1
                    ? $messages->first()
                    : 'Please fix the '.$messages->count().' highlighted fields: '.$messages->take(3)->implode(' ').($messages->count() > 3 ? ' …' : ''))
                ->send();
        };
    }
}

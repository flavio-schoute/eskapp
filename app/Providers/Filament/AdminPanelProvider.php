<?php

namespace App\Providers\Filament;

use App\Providers\AppServiceProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->passwordReset()
            ->brandName('e-Skool Tools')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.favicons'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.external-link-styles'))
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn () => view('filament.notification-countdown-styles', ['duration' => AppServiceProvider::NotificationDuration]),
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                NavigationGroup::make('Affiliates')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->collapsible(),
                NavigationGroup::make('Debiteurenbeheer')
                    ->icon(Heroicon::OutlinedScale)
                    ->collapsible(),
                NavigationGroup::make('System')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->collapsible(),
            ])
            ->navigationItems([
                NavigationItem::make('Finance')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->sort(10)
                    ->url(fn (): string => config('services.finance_dashboard.url'), shouldOpenInNewTab: true)
                    ->visible(fn (): bool => filled(config('services.finance_dashboard.url'))),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}

<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EditProfile;
use App\Filament\Pages\Finance\Inbox;
use App\Providers\AppServiceProvider;
use Filament\Enums\UserMenuPosition;
use Filament\Facades\Filament;
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
            ->profile(EditProfile::class, isSimple: false)
            ->userMenu(position: UserMenuPosition::Sidebar)
            ->sidebarCollapsibleOnDesktop()
            ->brandName('e-Skool Tools')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.favicons'))
            ->renderHook(PanelsRenderHook::STYLES_AFTER, fn () => view('filament.sidebar-styles'))
            ->renderHook(PanelsRenderHook::PAGE_END, fn () => view('filament.finance.inbox-shortcuts'), scopes: Inbox::class)
            // Before Filament's scripts, so the chart widgets pick up the Finance chart plugin when they start.
            ->renderHook(PanelsRenderHook::SCRIPTS_BEFORE, fn (): string => '<script src="'.asset('js/finance-charts.js').'?v='.filemtime(public_path('js/finance-charts.js')).'"></script>')
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
                NavigationGroup::make('Finance')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->collapsible(),
                NavigationGroup::make('System')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->collapsible(),
                NavigationGroup::make('Settings')
                    ->collapsible(false),
            ])
            ->navigationItems([
                NavigationItem::make('Profile')
                    ->group('Settings')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->sort(2)
                    ->url(fn (): string => Filament::getProfileUrl())
                    ->isActiveWhen(fn (): bool => request()->routeIs('filament.admin.auth.profile')),
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

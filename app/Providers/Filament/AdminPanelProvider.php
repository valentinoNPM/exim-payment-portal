<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\PaymentSlipQueueTable;
use App\Filament\Widgets\PaymentSlipStats;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
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

            // Branding
            ->brandName('EXIM Payment Portal')
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('1.5rem')
            ->favicon(asset('favicon.ico'))

            // Color scheme — Fintech Gold primary with Slate grays
            ->colors([
                'primary' => '#F59E0B', // Fintech Gold/Amber
                'gray' => Color::Slate,
                'danger' => Color::Rose,
                'info' => Color::Sky,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
            ])

            // Dark mode default / forced if needed, but let's keep it toggleable
            ->darkMode()

            // Modern font for Fintech Dashboard
            ->font('Plus Jakarta Sans')

            // Sidebar
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                NavigationGroup::make('Payment')
                    ->icon('heroicon-o-banknotes'),
                NavigationGroup::make('Master Data')
                    ->icon('heroicon-o-circle-stack')
                    ->collapsed(),
                NavigationGroup::make('Settings')
                    ->icon('heroicon-o-cog-6-tooth')
                    ->collapsed(),
            ])

            // Max content width
            ->maxContentWidth(Width::Full)

            // Global search
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])

            // Auto-discovery
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                PaymentSlipStats::class,
                AccountWidget::class,
                PaymentSlipQueueTable::class,
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

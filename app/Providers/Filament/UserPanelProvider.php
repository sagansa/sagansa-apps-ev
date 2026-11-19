<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Panel\ChargerLocationResource;
use App\Filament\Resources\Panel\ChargerResource;
use App\Filament\Resources\Panel\ChargeResource;
use App\Filament\Resources\Panel\DiscountHomeChargingResource;
use App\Filament\Resources\Panel\StateOfHealthResource;
use App\Filament\Resources\Panel\VehicleResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class UserPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('user')
            ->path('user')
            // registration() & passwordReset() dihapus — jalur self-service
            // auth web ditutup (spec 2026-11-19), konsisten dengan panel admin.
            ->login()
            ->emailVerification()
            ->navigationGroups([
                'Aplikasi',
            ])
            ->colors([
                'primary' => Color::Sky,
            ])
            ->resources([
                VehicleResource::class,
                ChargeResource::class,
                ChargerLocationResource::class,
                ChargerResource::class,
                StateOfHealthResource::class,
                DiscountHomeChargingResource::class,
            ])
            ->discoverPages(in: app_path('Filament/User/Pages'), for: 'App\\Filament\\User\\Pages')
            ->pages([
                Dashboard::class
            ])
            ->discoverWidgets(in: app_path('Filament/User/Widgets'), for: 'App\\Filament\\User\\Widgets')
            ->widgets([
                // Widgets\AccountWidget::class,
                // Widgets\FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                \BezhanSalleh\FilamentShield\FilamentShieldPlugin::make('user')
            ]);
    }
}

<?php

namespace App\Providers\Filament;

use App\Http\Controllers\PieceJointeController;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('SAV Lift Foils')
            ->login()
            ->colors([
                'primary' => Color::Amber,
                // Couleurs supplémentaires utilisées par les badges de statut
                // (App\Enums\StatutCas). Filament n'enregistre par défaut que
                // danger, gray, info, primary, success et warning — et parmi
                // celles-ci primary et warning sont toutes deux ambre.
                'cyan' => Color::Cyan,
                'indigo' => Color::Indigo,
                'orange' => Color::Orange,
                'violet' => Color::Violet,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            // Déclarées ici plutôt que dans routes/web.php : elles héritent
            // ainsi de l'authentification du panneau. Les pièces jointes sont
            // des données clients, elles ne sortent que pour un utilisateur
            // connecté. Noms générés : filament.admin.pieces-jointes.*
            ->authenticatedRoutes(function (): void {
                Route::controller(PieceJointeController::class)
                    ->prefix('pieces-jointes/{pieceJointe}')
                    ->name('pieces-jointes.')
                    ->group(function (): void {
                        Route::get('telecharger', 'telecharger')->name('telecharger');
                        Route::get('apercu', 'apercu')->name('apercu');
                    });
            })
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

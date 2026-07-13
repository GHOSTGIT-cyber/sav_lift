<?php

namespace App\Providers\Filament;

use App\Http\Controllers\PieceJointeController;
use App\Http\Middleware\ConnexionDemo;
use App\Support\ModeDemo;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
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
        $this->bandeauDemo();

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            // Closure, et non chaîne : le panneau est construit au démarrage de
            // l'application, on ne veut pas figer là ce qui se lit à l'affichage.
            ->brandName(fn (): string => ModeDemo::actif() ? 'SAV Lift Foils — DÉMO' : 'SAV Lift Foils')
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
                // Après StartSession (il faut une session pour connecter) et avant
                // authMiddleware (qui, lui, exigerait un mot de passe). Inerte hors
                // démo : voir App\Support\ModeDemo.
                ConnexionDemo::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * En démo, chaque page porte un bandeau rouge qui dit ce qu'elle est.
     *
     * Sans lui, l'instance publique est le sosie exact de la production : on
     * finirait par croire qu'on regarde de vrais dossiers — ou, bien pire, par
     * cliquer dans la vraie prod en la prenant pour la démo.
     *
     * Le hook est toujours posé ; c'est à l'affichage qu'il décide. Le panneau se
     * construit au démarrage de l'application : y figer une décision, c'est la
     * prendre trop tôt, et se retrouver avec un bandeau qui dépend de l'ordre de
     * boot plutôt que de l'état réel de l'instance.
     */
    private function bandeauDemo(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::BODY_START,
            fn (): string => ModeDemo::actif() ? view('filament.bandeau-demo')->render() : '',
        );
    }
}

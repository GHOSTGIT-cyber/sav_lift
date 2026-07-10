<?php

namespace App\Http\Controllers;

use App\Models\PieceJointe;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sert les pièces jointes des dossiers SAV.
 *
 * Le disque `local` est privé : ces fichiers contiennent des données clients
 * (factures, photos d'atelier, adresses). Ils ne sortent que par ici, derrière
 * l'authentification du panneau Filament (routes déclarées dans
 * AdminPanelProvider::authenticatedRoutes), et jamais par une URL publique ni
 * une URL signée qui circulerait hors session.
 */
class PieceJointeController extends Controller
{
    public function telecharger(PieceJointe $pieceJointe): StreamedResponse
    {
        abort_unless($pieceJointe->existeSurLeDisque(), 404);

        return Storage::disk('local')->download(
            $pieceJointe->path,
            $pieceJointe->filename,
            // Le navigateur ne doit jamais renifler le type d'un fichier venu
            // d'un inconnu : `nosniff` avec un Content-Type neutre garantit un
            // téléchargement, pas une exécution.
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Aperçu inline, réservé aux formats d'image bitmap.
     *
     * Servir inline un fichier fourni par un tiers, c'est ouvrir une faille
     * XSS stockée sur le domaine de l'admin : un « justificatif.html » ou un
     * SVG porteur de <script> s'exécuterait dans la session du technicien. On
     * n'autorise donc qu'une liste blanche de types (PieceJointe::IMAGES_AFFICHABLES),
     * et on interdit au document tout chargement externe.
     */
    public function apercu(PieceJointe $pieceJointe): StreamedResponse
    {
        abort_unless($pieceJointe->existeSurLeDisque(), 404);
        abort_unless($pieceJointe->estAffichable(), 404);

        return Storage::disk('local')->response(
            $pieceJointe->path,
            $pieceJointe->filename,
            [
                'Content-Type' => $pieceJointe->mime,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
            disposition: 'inline',
        );
    }
}

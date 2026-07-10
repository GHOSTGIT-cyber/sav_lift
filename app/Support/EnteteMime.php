<?php

namespace App\Support;

/**
 * Décodage des « encoded-words » RFC 2047 (`=?utf-8?Q?tr=C3=A8s?=`).
 *
 * Pourquoi ici plutôt que dans la librairie IMAP : sans l'extension PHP `imap`
 * — que le projet n'installe pas — le décodeur de webklex/php-imap ne sait pas
 * traiter un encoded-word. Il renvoie le sujet brut. Un sujet français y perd
 * ses accents (`Autonomie de la batterie =?utf-8?Q?tr=C3=A8s?= faible`), ce qui
 * n'est pas qu'un défaut d'affichage : les gardes anti-boucle comparent le
 * sujet, et « =?utf-8?Q?R=C3=A9ponse_automatique?= » ne ressemble plus à une
 * réponse automatique. L'outil se remettrait à accuser réception d'un
 * auto-répondeur.
 */
final class EnteteMime
{
    private function __construct() {}

    public static function decoder(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        if ($valeur === '') {
            return null;
        }

        // Un en-tête déjà lisible ne contient aucun encoded-word : on n'y touche
        // pas, iconv n'aurait qu'à s'y casser les dents sur des octets 8 bits.
        if (! str_contains($valeur, '=?')) {
            return $valeur;
        }

        $decode = @iconv_mime_decode($valeur, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        if ($decode === false || trim($decode) === '') {
            $decode = @mb_decode_mimeheader($valeur);
        }

        $decode = trim((string) $decode);

        // Un décodage qui ne rend rien vaut moins que la valeur d'origine :
        // un sujet biscornu reste préférable à un sujet vide.
        return $decode === '' ? $valeur : $decode;
    }
}

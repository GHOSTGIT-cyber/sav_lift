<?php

namespace App\Support;

use Illuminate\Support\Str;

final class SujetMail
{
    /** La référence d'un dossier telle qu'elle apparaît dans un objet ou un corps de mail. */
    private const REFERENCE = '/\bSAV-\d{4}-\d{4}\b/i';

    /**
     * Le noyau d'un objet : ce qui reste une fois retirés les préfixes de
     * réponse, les crochets des robots et le n° de ticket que Zendesk colle en
     * fin de ligne.
     *
     * « Re: [Lift Foils] Re: [SAV-2026-0001] Battery not charging (#90907) »
     *   → « battery not charging »
     *
     * C'est le repli quand le threading a été perdu : deux objets qui se
     * réduisent au même noyau parlent du même dossier.
     */
    public static function noyau(?string $sujet): string
    {
        $sujet = trim((string) $sujet);

        // Préfixes de réponse et de transfert, éventuellement empilés.
        $sujet = (string) preg_replace('/^\s*(?:(?:re|fw|fwd|tr|rép|rep)\s*:\s*)+/iu', '', $sujet);

        // Étiquettes en tête : [Lift Foils], [SAV-2026-0001], [#90907]…
        $sujet = (string) preg_replace('/^\s*(?:\[[^\]]*\]\s*)+/u', '', $sujet);

        // Le n° de ticket en queue : « … (#90907) », « … [#90907] ».
        $sujet = (string) preg_replace('/\s*[(\[]#?\d{3,10}[)\]]\s*$/u', '', $sujet);

        // Un objet peut cumuler préfixes et étiquettes en alternance.
        $sujet = (string) preg_replace('/^\s*(?:(?:re|fw|fwd|tr|rép|rep)\s*:\s*|\[[^\]]*\]\s*)+/iu', '', $sujet);

        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', $sujet)));
    }

    /** La référence de dossier citée dans ces textes (objet, corps), ou null. */
    public static function reference(?string ...$textes): ?string
    {
        foreach ($textes as $texte) {
            if (filled($texte) && preg_match(self::REFERENCE, $texte, $m) === 1) {
                return Str::upper($m[0]);
            }
        }

        return null;
    }
}

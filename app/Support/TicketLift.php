<?php

namespace App\Support;

/**
 * Le n° de ticket Zendesk de Lift, lu dans leurs mails.
 *
 * L'API Zendesk de Lift est fermée (401, testé au Bloc 3-D) : on ne la
 * réessaie pas. La « synchro » passe par leurs mails, qui arrivent sur sav@ —
 * à commencer par l'accusé « Your request has been received and assigned
 * Ticket #90907 ».
 *
 * On n'applique ces motifs qu'aux mails de Lift et de son Zendesk : sur un mail
 * client, un « #12345 » serait tout aussi bien une référence de commande.
 */
final class TicketLift
{
    /**
     * Du plus explicite au plus permissif. Le premier qui accroche gagne, ce
     * qui évite qu'un « order #200 » cité plus bas dans le corps ne l'emporte
     * sur le « Ticket #90907 » de l'objet.
     */
    private const MOTIFS = [
        '/\bticket\s*[#nº°]?\s*(\d{3,10})\b/iu',
        '/\brequest\s*[#(]\s*(\d{3,10})\b/iu',
        '/[(\[]#(\d{3,10})[)\]]/',
    ];

    /** Le n° de ticket (chiffres seuls), ou null. L'objet prime sur le corps. */
    public static function numero(?string $sujet, ?string $corps = null): ?string
    {
        foreach ([$sujet, $corps] as $texte) {
            if (blank($texte)) {
                continue;
            }

            foreach (self::MOTIFS as $motif) {
                if (preg_match($motif, $texte, $m) === 1) {
                    return $m[1];
                }
            }
        }

        // Dernier recours, sur l'objet seul : « Re: Battery issue (#90907) » sans
        // le mot « ticket ». Dans le corps, un « #… » nu est trop ambigu.
        return blank($sujet) || preg_match('/#(\d{3,10})\b/', (string) $sujet, $m) !== 1
            ? null
            : $m[1];
    }
}

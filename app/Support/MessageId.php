<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Manipulation des Message-ID RFC 5322.
 *
 * Convention du projet : on stocke et on compare les identifiants **sans**
 * leurs chevrons. Les en-têtes, eux, en portent toujours (`<abc@host>`), et
 * selon la source (Webklex, Symfony Mailer, un `In-Reply-To` recopié à la main
 * par un client mail) ils arrivent avec ou sans. Tout passe donc par ici.
 */
final class MessageId
{
    private function __construct() {}

    /** Retire les chevrons et l'espace autour d'un identifiant. */
    public static function normaliser(?string $id): ?string
    {
        $id = trim((string) $id, " \t\n\r\0\x0B<>");

        return $id === '' ? null : $id;
    }

    /**
     * Éclate un en-tête `References` (ou `In-Reply-To` multiple) en une liste
     * d'identifiants normalisés, du plus ancien au plus récent.
     *
     * @param  string|iterable<mixed>|null  $references
     * @return list<string>
     */
    public static function liste(string|iterable|null $references): array
    {
        if ($references === null) {
            return [];
        }

        $bruts = match (true) {
            // Les identifiants sont censés être séparés par des espaces, mais
            // des clients y glissent des virgules ou des retours à la ligne.
            is_string($references) => preg_split('/[\s,]+/', $references, flags: PREG_SPLIT_NO_EMPTY) ?: [],
            is_array($references) => $references,
            default => iterator_to_array($references),
        };

        $ids = [];

        foreach ($bruts as $brut) {
            if (! is_string($brut)) {
                continue;
            }

            if ($id = self::normaliser($brut)) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Fabrique un Message-ID pour un mail que **nous** émettons.
     *
     * On le génère nous-mêmes au lieu de laisser Symfony Mailer le faire :
     * c'est le seul moyen de connaître l'identifiant avant l'envoi, donc de
     * l'enregistrer en base. Sans ça, la réponse du client à notre accusé de
     * réception (dont le `In-Reply-To` pointe cet identifiant) ne se
     * rattacherait à aucun dossier connu et ouvrirait un doublon.
     */
    public static function genererPourSortant(string $domaine): string
    {
        return sprintf('sav-%s@%s', Str::uuid()->toString(), $domaine);
    }

    /** Remet les chevrons, pour écrire un en-tête. */
    public static function enChevrons(string $id): string
    {
        return '<'.self::normaliser($id).'>';
    }
}

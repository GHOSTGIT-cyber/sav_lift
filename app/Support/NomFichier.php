<?php

namespace App\Support;

use Illuminate\Support\Str;

final class NomFichier
{
    /** Limite prudente : la plupart des systèmes de fichiers plafonnent à 255 octets. */
    private const LONGUEUR_MAX = 120;

    private function __construct() {}

    /**
     * Transforme le nom annoncé par un email en un segment de chemin sûr.
     *
     * Le nom d'une pièce jointe est une donnée hostile : un mail forgé peut
     * annoncer `../../../.env` ou `photo.jpg\0.php`. On ne garde donc que le
     * nom de base, dépouillé de tout ce qui n'est pas alphanumérique, point,
     * tiret ou underscore.
     */
    public static function securiser(?string $nom): string
    {
        // basename() s'arrête au dernier séparateur *de la plateforme* : sous
        // Linux il ignore les antislashs, qu'un client Windows utilise pourtant.
        $nom = basename(str_replace('\\', '/', (string) $nom));

        $extension = Str::afterLast($nom, '.');
        $base = Str::beforeLast($nom, '.');

        // Un nom sans point : beforeLast() renvoie la chaîne entière, il n'y a
        // pas d'extension à préserver.
        if ($base === '' || ! str_contains($nom, '.')) {
            $base = $nom;
            $extension = '';
        }

        $base = self::nettoyer($base, self::LONGUEUR_MAX);
        $extension = self::nettoyer($extension, 16);

        if ($base === '') {
            $base = 'piece-jointe';
        }

        return $extension === '' ? $base : "{$base}.{$extension}";
    }

    private static function nettoyer(string $valeur, int $longueurMax): string
    {
        // Str::ascii translittère « facture_été.pdf » plutôt que d'y laisser un trou.
        $valeur = Str::ascii($valeur);
        $valeur = preg_replace('/[^A-Za-z0-9._-]+/', '-', $valeur) ?? '';

        return trim(mb_substr($valeur, 0, $longueurMax), '-._');
    }
}

<?php

namespace App\Services\Dossier;

use App\Models\Cas;
use Closure;

/**
 * Un élément qu'un dossier doit porter pour être traitable — et la phrase par
 * laquelle on le réclame au client.
 *
 * Un seul objet pour les deux usages : ce qu'on affiche à Nico (« Ce qui
 * manque ») et ce qu'on demande au client (les puces du mail). C'est ce qui
 * garantit qu'on ne réclame jamais une pièce que le dossier possède déjà.
 */
final readonly class Exigence
{
    /**
     * @param  string  $cle  Identifiant stable (tests, futures options).
     * @param  string  $libelle  Formulation courte, côté outil : « Photo de l'étiquette MHS ».
     * @param  string  $demande  Formulation adressée au client, telle qu'elle apparaît
     *                           en puce dans le mail (sans ponctuation finale).
     * @param  bool  $bloquante  Vraie : le dossier ne part pas chez Lift sans elle.
     * @param  Closure(Cas): bool  $satisfaite
     */
    public function __construct(
        public string $cle,
        public string $libelle,
        public string $demande,
        public bool $bloquante,
        private Closure $satisfaite,
    ) {}

    public function satisfaitePar(Cas $cas): bool
    {
        return ($this->satisfaite)($cas);
    }
}

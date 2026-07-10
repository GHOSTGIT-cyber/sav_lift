<?php

namespace App\Models;

use App\Enums\StatutCas;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un dossier SAV : une demande client, de sa réception à sa résolution.
 */
class Cas extends Model
{
    /**
     * « Cas » est invariable (Str::plural('Cas') === 'Cas', mais
     * Str::singular('Cas') === 'Ca') : on fixe la table explicitement plutôt
     * que de dépendre de l'inflecteur.
     */
    protected $table = 'cas';

    /** @var list<string> */
    protected $fillable = [
        'reference',
        'client_nom',
        'client_email',
        'client_telephone',
        'produit',
        'modele',
        'numero_serie',
        'sales_order',
        'description',
        'statut',
        'ticket_lift',
        'tracking',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => StatutCas::class,
        ];
    }

    /**
     * `reference` est à la fois unique et nullable. Un champ de formulaire vide
     * arrive sous forme de chaîne vide : sans cette normalisation, le deuxième
     * dossier sans référence violerait l'index unique (SQL, lui, tolère
     * plusieurs NULL).
     */
    protected function reference(): Attribute
    {
        return Attribute::set(
            fn (?string $value): ?string => filled($value) ? trim($value) : null,
        );
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return HasMany<PieceJointe, $this> */
    public function pieceJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class);
    }

    /**
     * Référence lisible `SAV-2026-0001`, remise à 1 chaque année.
     *
     * Le compteur se lit sur les références existantes plutôt que sur un
     * compteur séparé : une référence saisie à la main reste prise en compte.
     * Le zéro-padding sur 4 chiffres est ce qui rend le `max()` lexicographique
     * équivalent à un `max()` numérique — jusqu'à 9999 dossiers par an, ce qui
     * nous laisse de la marge à quelques dizaines par mois.
     */
    public static function prochaineReference(?int $annee = null): string
    {
        $annee ??= now()->year;

        $derniere = static::query()
            ->where('reference', 'like', "SAV-{$annee}-%")
            ->max('reference');

        $compteur = $derniere === null ? 1 : ((int) substr($derniere, -4)) + 1;

        return sprintf('SAV-%d-%04d', $annee, $compteur);
    }
}

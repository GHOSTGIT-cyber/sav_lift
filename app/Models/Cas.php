<?php

namespace App\Models;

use App\Enums\StatutCas;
use Illuminate\Database\Eloquent\Model;

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
}

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
        'contexte',
        'urgent',
        'complet',
        'statut',
        'ticket_lift',
        'tracking',
        'brouillon_lift',
        'statut_lift',
        'source',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'statut' => StatutCas::class,
            'urgent' => 'boolean',
            'complet' => 'boolean',
            'extrait_le' => 'datetime',
            'brouillon_lift_le' => 'datetime',
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

    /**
     * Le texte soumis à l'extraction IA : les corps des mails **entrants** du
     * dossier (là où le client donne ses infos), à défaut la description.
     *
     * Borné en longueur : au-delà, l'extraction coûte cher pour rien — les
     * champs recherchés (MHS, modèle) sont presque toujours en tête de fil.
     */
    public function contenuPourExtraction(): string
    {
        $entrants = $this->messages()
            ->where('direction', 'inbound')
            ->orderBy('received_at')
            ->pluck('body_text')
            ->filter()
            ->implode("\n\n---\n\n");

        $contenu = trim($entrants) !== '' ? $entrants : (string) $this->description;

        return str($contenu)->limit(12000, '')->value();
    }

    /**
     * Fusionne le résultat d'une extraction dans le dossier, puis recalcule
     * `complet`.
     *
     * Politique de fusion — on **complète sans écraser** : un champ déjà rempli
     * (souvent saisi ou confirmé par un humain) prime sur une nouvelle
     * extraction. C'est décisif pour le MHS et le Sales Order, qu'on ne doit
     * jamais remplacer par une valeur d'un mail ultérieur qui ne les contient pas.
     * `urgent` est cumulatif : une fois signalé urgent, le dossier le reste.
     *
     * @param  array{produit: ?string, modele: ?string, mhs: ?string, sales_order: ?string, contexte: ?string, urgent: bool}  $d
     */
    public function appliquerExtraction(array $d): void
    {
        $this->produit ??= $d['produit'];
        $this->modele ??= $d['modele'];
        $this->numero_serie ??= $d['mhs'];
        $this->sales_order ??= $d['sales_order'];
        $this->contexte ??= $d['contexte'];
        $this->urgent = $this->urgent || $d['urgent'];

        $this->complet = $this->estActionnable();
        $this->extrait_le = now();
        $this->extraction_erreur = null;

        $this->save();
    }

    /**
     * Règle V1 : un dossier est actionnable (« complet ») dès qu'on connaît le
     * produit ET son numéro de série — le minimum pour ouvrir un dossier chez
     * Lift. Le reste (Sales Order, contexte) affine mais ne bloque pas.
     */
    public function estActionnable(): bool
    {
        return filled($this->produit) && filled($this->numero_serie);
    }

    /**
     * Lien profond vers le ticket sur le portail Zendesk de Lift (repli du
     * Bloc 3-D, la sync auto étant fermée). Construit à partir du n° de ticket
     * saisi à la main ; null si aucun ticket.
     */
    public function lienPortailZendesk(): ?string
    {
        // On ne garde que les chiffres : le champ peut contenir « #90907 », « Ticket 90907 »…
        $numero = preg_replace('/\D+/', '', (string) $this->ticket_lift) ?: '';

        if ($numero === '') {
            return null;
        }

        $base = rtrim((string) config('sav.zendesk.portail_url'), '/');

        return "{$base}/hc/requests/{$numero}";
    }
}

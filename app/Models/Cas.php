<?php

namespace App\Models;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Enums\VueDossier;
use App\Observers\CasObserver;
use App\Services\Dossier\Exigence;
use App\Services\Dossier\RegleCompletude;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un dossier SAV : une demande client, de sa réception à sa résolution.
 */
#[ObservedBy(CasObserver::class)]
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
        'date_achat',
        'photo_etiquette',
        'preuve_achat',
        'photos_defaut',
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
            'photo_etiquette' => 'boolean',
            'preuve_achat' => 'boolean',
            'photos_defaut' => 'boolean',
            'extrait_le' => 'datetime',
            'brouillon_lift_le' => 'datetime',
            'relance_client_le' => 'datetime',
            'envoye_lift_le' => 'datetime',
            'reponse_lift_le' => 'datetime',
            'client_avise_lift_le' => 'datetime',
        ];
    }

    /**
     * `complet` est dérivé, jamais saisi : on le recalcule à chaque écriture,
     * quelle que soit la porte d'entrée (extraction IA, formulaire Filament,
     * commande artisan). C'est ce qui garantit qu'un dossier ne reste pas dans
     * la mauvaise vue parce qu'on a oublié d'appeler la bonne méthode.
     *
     * Les pièces jointes, elles, ne passent pas par une écriture du dossier :
     * c'est PieceJointe qui redemande le calcul quand on en ajoute ou en retire.
     */
    protected static function booted(): void
    {
        static::saving(function (Cas $cas): void {
            $cas->complet = RegleCompletude::estComplet($cas);
        });
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

    // -------------------------------------------------------------- Complétude

    /**
     * Photo de l'étiquette du numéro de série.
     *
     * Aucun code ne sait ce que montre un JPEG, et « lisible » est un jugement
     * humain. On **présume** donc que le client qui nous a donné son MHS l'a
     * photographié, dès lors qu'une image est jointe au dossier. Le dossier
     * passe alors en « À valider » — la vue où, précisément, un humain regarde.
     * S'il constate que l'étiquette manque ou est floue, il bascule la pièce sur
     * « Absente » (`photo_etiquette = false`) : le dossier repart en
     * « À compléter » et le client est relancé.
     */
    public function aPhotoEtiquette(): bool
    {
        return $this->photo_etiquette ?? (
            filled($this->numero_serie)
            && $this->pieceJointes->contains(fn (PieceJointe $p): bool => $p->estImage())
        );
    }

    /** Facture (un document joint) OU numéro de Sales Order. Surchargeable à la main. */
    public function aPreuveAchat(): bool
    {
        return $this->preuve_achat ?? (
            filled($this->sales_order)
            || $this->pieceJointes->contains(fn (PieceJointe $p): bool => $p->estDocument())
        );
    }

    /** Photos et/ou vidéos du défaut. Présumées dès qu'un média est joint. */
    public function aPhotosDefaut(): bool
    {
        return $this->photos_defaut
            ?? $this->pieceJointes->contains(fn (PieceJointe $p): bool => $p->estImage() || $p->estVideo());
    }

    /** Le dossier peut-il partir chez Lift ? (aucune exigence bloquante ne manque). */
    public function estActionnable(): bool
    {
        return RegleCompletude::estComplet($this);
    }

    /**
     * Ce qui manque et qui interdit d'ouvrir le dossier chez Lift.
     *
     * @return list<Exigence>
     */
    public function manquantsBloquants(): array
    {
        return RegleCompletude::manquantsBloquants($this);
    }

    /**
     * Recalcule `complet` depuis des pièces jointes fraîches.
     *
     * Appelé quand une pièce jointe entre ou sort : la relation peut être déjà
     * chargée sur cette instance, et donc périmée d'une pièce.
     */
    public function rafraichirCompletude(): void
    {
        $this->unsetRelation('pieceJointes');

        // Le hook `saving` refait le calcul ; on n'écrit que s'il change.
        if ($this->complet !== RegleCompletude::estComplet($this)) {
            $this->save();
        }
    }

    public function vue(): VueDossier
    {
        return VueDossier::de($this);
    }

    /** L'instruction affichée en tête de la fiche. */
    public function prochaineAction(): string
    {
        return $this->vue()->prochaineAction($this);
    }

    // --------------------------------------------------------------- Extraction

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
            ->where('direction', DirectionMessage::Inbound)
            ->orderBy('received_at')
            ->pluck('body_text')
            ->filter()
            ->implode("\n\n---\n\n");

        $contenu = trim($entrants) !== '' ? $entrants : (string) $this->description;

        return str($contenu)->limit(12000, '')->value();
    }

    /**
     * Fusionne le résultat d'une extraction dans le dossier.
     *
     * Politique de fusion — on **complète sans écraser** : un champ déjà rempli
     * (souvent saisi ou confirmé par un humain) prime sur une nouvelle
     * extraction. C'est décisif pour le MHS et le Sales Order, qu'on ne doit
     * jamais remplacer par une valeur d'un mail ultérieur qui ne les contient pas.
     * `urgent` est cumulatif : une fois signalé urgent, le dossier le reste.
     *
     * `complet` est recalculé à l'enregistrement (hook `saving`).
     *
     * @param  array{produit: ?string, modele: ?string, mhs: ?string, sales_order: ?string, date_achat: ?string, contexte: ?string, urgent: bool}  $d
     */
    public function appliquerExtraction(array $d): void
    {
        $this->produit ??= $d['produit'];
        $this->modele ??= $d['modele'];
        $this->numero_serie ??= $d['mhs'];
        $this->sales_order ??= $d['sales_order'];
        $this->date_achat ??= $d['date_achat'];
        $this->contexte ??= $d['contexte'];
        $this->urgent = $this->urgent || $d['urgent'];

        $this->extrait_le = now();
        $this->extraction_erreur = null;

        $this->save();
    }

    // --------------------------------------------------------------------- Lift

    /**
     * L'objet du mail vers Lift, tiré du brouillon (« Subject: … » en 1re ligne).
     *
     * On force la référence du dossier dans l'objet. C'est notre filet pour
     * rattacher la réponse de Lift au bon dossier le jour où le threading se
     * perd en route (Zendesk réécrit volontiers les en-têtes).
     */
    public function sujetBrouillonLift(): string
    {
        $sujet = preg_match('/^\s*subject\s*:\s*(.+)$/im', (string) $this->brouillon_lift, $m) === 1
            ? trim($m[1])
            : trim(implode(' ', array_filter([$this->produit, $this->modele])));

        if ($sujet === '') {
            $sujet = 'Lift SAV request';
        }

        $reference = (string) $this->reference;

        return $reference !== '' && ! str_contains($sujet, $reference)
            ? "[{$reference}] {$sujet}"
            : $sujet;
    }

    /** Le corps du brouillon, privé de sa ligne « Subject: » (devenue l'objet). */
    public function corpsBrouillonLift(): string
    {
        $brouillon = trim((string) $this->brouillon_lift);

        return trim((string) preg_replace('/^\s*subject\s*:.*(\R+|$)/i', '', $brouillon, 1));
    }

    /**
     * Lien profond vers le ticket sur le portail Zendesk de Lift (repli du
     * Bloc 3-D, la sync auto étant fermée). Construit à partir du n° de ticket,
     * capté dans l'accusé de Lift (Bloc 4) ou saisi à la main.
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

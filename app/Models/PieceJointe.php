<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Un fichier joint à un email : photo de l'étiquette MHS, facture, vidéo du
 * défaut… Stocké sur le disque `local`, qui est privé : aucune URL publique,
 * le fichier ne sort que par App\Http\Controllers\TelechargerPieceJointe.
 */
class PieceJointe extends Model
{
    /**
     * Les seuls types que l'on accepte de rendre inline dans le navigateur.
     *
     * Liste blanche, et non « tout ce qui commence par image/ » : un SVG est
     * un document XML qui peut porter du <script>, et l'afficher dans le
     * panneau reviendrait à exécuter le code d'un inconnu dans la session du
     * technicien. Le SVG reste téléchargeable, pas affichable.
     */
    public const IMAGES_AFFICHABLES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/heic',
        'image/bmp',
    ];

    protected $table = 'piece_jointes';

    /** @var list<string> */
    protected $fillable = [
        'cas_id',
        'message_id',
        'path',
        'filename',
        'mime',
        'taille',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'taille' => 'integer',
        ];
    }

    /** @return BelongsTo<Cas, $this> */
    public function cas(): BelongsTo
    {
        return $this->belongsTo(Cas::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Une pièce jointe entre ou sort : la complétude du dossier a pu changer
     * (la photo de l'étiquette, la vidéo du défaut, la facture…). On redemande
     * le calcul au dossier, sans quoi il resterait dans la mauvaise vue jusqu'à
     * sa prochaine écriture.
     */
    protected static function booted(): void
    {
        $rafraichir = fn (PieceJointe $piece) => $piece->cas?->rafraichirCompletude();

        static::created($rafraichir);
        static::deleted($rafraichir);
    }

    public function estImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    public function estVideo(): bool
    {
        return str_starts_with((string) $this->mime, 'video/');
    }

    /**
     * Un document : facture, bon de commande, PDF de garantie… Tout ce qui
     * n'est ni une image ni une vidéo, et qui vaut donc présomption de preuve
     * d'achat (voir Cas::aPreuveAchat).
     */
    public function estDocument(): bool
    {
        return $this->mime !== null && ! $this->estImage() && ! $this->estVideo();
    }

    /** Peut-on en montrer un aperçu sans risque (voir IMAGES_AFFICHABLES) ? */
    public function estAffichable(): bool
    {
        return in_array($this->mime, self::IMAGES_AFFICHABLES, true);
    }

    public function tailleLisible(): ?string
    {
        return $this->taille === null ? null : Number::fileSize($this->taille, precision: 1);
    }

    /**
     * Le fichier peut manquer : disque non monté, ou base restaurée sans le
     * volume. La ressource Filament s'appuie dessus pour ne pas proposer un
     * téléchargement qui finirait en 404.
     */
    public function existeSurLeDisque(): bool
    {
        return Storage::disk('local')->exists($this->path);
    }
}

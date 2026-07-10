<?php

namespace App\Models;

use App\Enums\DirectionMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un email rattaché à un dossier : entrant (relevé en IMAP) ou sortant
 * (envoyé par l'outil).
 */
class Message extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'cas_id',
        'message_id',
        'in_reply_to',
        'email_references',
        'direction',
        'from_email',
        'from_name',
        'to_email',
        'subject',
        'body_text',
        'body_html',
        'received_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'direction' => DirectionMessage::class,
            'received_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Cas, $this> */
    public function cas(): BelongsTo
    {
        return $this->belongsTo(Cas::class);
    }

    /** @return HasMany<PieceJointe, $this> */
    public function pieceJointes(): HasMany
    {
        return $this->hasMany(PieceJointe::class);
    }

    /**
     * Le corps texte réduit à un extrait lisible dans une timeline : les
     * emails arrivent avec des retours à la ligne « format=flowed » tous les
     * 78 caractères, qui rendent un extrait brut illisible.
     */
    public function extrait(int $longueur = 160): string
    {
        $texte = $this->body_text ?? strip_tags((string) $this->body_html);

        return str(preg_replace('/\s+/u', ' ', $texte) ?? '')->trim()->limit($longueur)->value();
    }
}

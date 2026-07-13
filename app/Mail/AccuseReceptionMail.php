<?php

namespace App\Mail;

use App\Models\Cas;
use App\Services\Dossier\Exigence;
use App\Services\Dossier\RegleCompletude;
use App\Support\MessageId;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * L'unique mail automatique vers le client : accusé de réception **et** demande
 * des pièces manquantes, en une seule fois.
 *
 * Envoyé APRÈS l'extraction IA — et c'est tout l'intérêt : la liste des pièces
 * réclamées est **générée** à partir de ce qui manque réellement au dossier
 * (RegleCompletude). On ne redemande jamais au client ce qu'il vient de nous
 * fournir. Si plus rien ne manque, le mail le dit et ne réclame rien.
 *
 * Il sert aussi de relance, déclenchée à la main depuis la fiche du dossier.
 */
class AccuseReceptionMail extends Mailable
{
    /**
     * Les phrases à mettre en puces : ce qui manque, formulé pour le client.
     * On n'en garde que le texte — un Mailable doit rester sérialisable, et une
     * Exigence porte une closure.
     *
     * @var list<string>
     */
    public readonly array $demandes;

    /**
     * @param  string  $messageId  L'identifiant que portera CE mail, généré en amont
     *                             pour pouvoir l'enregistrer en base (voir MessageId).
     * @param  string|null  $enReponseA  Le Message-ID du mail du client, pour que
     *                                   l'accusé se glisse dans son fil de discussion.
     */
    public function __construct(
        public readonly Cas $cas,
        protected readonly string $messageId,
        protected readonly ?string $enReponseA = null,
    ) {
        $this->demandes = array_map(
            fn (Exigence $exigence): string => $exigence->demande,
            RegleCompletude::manquants($cas),
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Réception de votre demande SAV Lift Foils',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.accuse-reception',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            // Laravel ajoute lui-même les chevrons pour Message-Id et
            // References ; pour In-Reply-To, en-tête « libre », c'est à nous.
            messageId: $this->messageId,
            references: $this->enReponseA ? [$this->enReponseA] : [],
            text: $this->enReponseA
                ? ['In-Reply-To' => MessageId::enChevrons($this->enReponseA)]
                : [],
        );
    }
}

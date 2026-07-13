<?php

namespace App\Mail;

use App\Models\Cas;
use App\Support\MessageId;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * « Votre dossier est parti chez Lift » — envoyé au client au moment où le
 * dossier entre chez Lift, quel que soit le chemin emprunté (bouton « Envoyer à
 * Lift », statut changé à la main, ou accusé de Lift capté par la relève).
 *
 * Le déclenchement est centralisé dans App\Observers\CasObserver ; l'envoi passe
 * par l'Expediteur, donc par le garde-fou SAV_ENVOI_ACTIF.
 */
class TransmisLiftMail extends Mailable
{
    public function __construct(
        public readonly Cas $cas,
        protected readonly string $messageId,
        protected readonly ?string $enReponseA = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre dossier SAV a été transmis au support Lift Foils',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.transmis-lift',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            messageId: $this->messageId,
            references: $this->enReponseA ? [$this->enReponseA] : [],
            text: $this->enReponseA
                ? ['In-Reply-To' => MessageId::enChevrons($this->enReponseA)]
                : [],
        );
    }
}

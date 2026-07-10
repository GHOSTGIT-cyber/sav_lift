<?php

namespace App\Mail;

use App\Models\Cas;
use App\Support\MessageId;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Le seul mail que l'outil envoie sans validation humaine (voir CLAUDE.md).
 *
 * Envoyé en ligne, pas en file d'attente : au Bloc 1 la relève IMAP est déjà
 * une tâche de fond, et un accusé qui part deux minutes plus tard n'apporte
 * rien. La file arrivera au Bloc 2, quand l'appel à l'IA rendra l'asynchrone
 * utile.
 */
class AccuseReceptionMail extends Mailable
{
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
    ) {}

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

<?php

namespace App\Services\Mail;

use App\Enums\DirectionMessage;
use App\Mail\AccuseReceptionMail;
use App\Mail\TransmisLiftMail;
use App\Models\Cas;
use App\Models\Message;
use App\Support\MessageId;
use App\Support\Partenaires;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tout ce que l'outil écrit **au client** passe par ici.
 *
 * Deux mails, et deux seulement :
 *   - l'accusé de réception, qui réclame d'emblée les pièces manquantes
 *     (le seul mail envoyé sans qu'un humain clique — voir CLAUDE.md) ;
 *   - « votre dossier est parti chez Lift ».
 *
 * Cette classe ne décide jamais si le mail part vraiment : c'est l'Expediteur
 * (garde-fou SAV_ENVOI_ACTIF). Elle décide de quoi on parle, à qui, dans quel
 * fil — et n'enregistre un message sortant que si quelque chose est réellement
 * parti. Un dossier ne doit jamais porter la trace d'un mail que le client n'a
 * pas reçu.
 */
class NotificateurClient
{
    public function __construct(private readonly Expediteur $expediteur) {}

    /**
     * Accusé de réception + demande des pièces manquantes.
     *
     * Sert aussi de relance : c'est le même mail, recalculé sur l'état courant
     * du dossier, donc il ne réclame que ce qui manque encore.
     *
     * @param  string|null  $enReponseA  Le mail du client auquel se greffer ; à défaut,
     *                                   le dernier entrant du dossier.
     * @return bool true si le mail est réellement parti.
     */
    public function accuserReception(Cas $cas, ?string $enReponseA = null): bool
    {
        $enReponseA ??= $this->dernierEntrant($cas)?->message_id;
        $messageId = $this->messageId();

        $parti = $this->expedier(
            $cas,
            new AccuseReceptionMail($cas, $messageId, $enReponseA),
            $messageId,
            $enReponseA,
            "Accusé de réception du dossier {$cas->reference}.",
        );

        // La date de relance date le dernier « il nous manque ceci » : un dossier
        // déjà complet n'a rien réclamé, il n'y a donc rien à dater.
        if ($parti && ! $cas->complet) {
            $cas->forceFill(['relance_client_le' => now()])->save();
        }

        return $parti;
    }

    /** « Votre dossier a été transmis au support Lift Foils. » */
    public function informerTransmissionLift(Cas $cas): bool
    {
        $enReponseA = $this->dernierEntrant($cas)?->message_id;
        $messageId = $this->messageId();

        $parti = $this->expedier(
            $cas,
            new TransmisLiftMail($cas, $messageId, $enReponseA),
            $messageId,
            $enReponseA,
            "Dossier {$cas->reference} transmis au support Lift Foils.",
        );

        if ($parti) {
            $cas->forceFill(['client_avise_lift_le' => now()])->save();
        }

        return $parti;
    }

    /**
     * Envoie, puis trace — dans cet ordre, et jamais l'inverse.
     *
     * Un SMTP en panne ne doit pas coûter un dossier : on journalise et on rend
     * la main. Le dossier reste, c'est le mail qui saute.
     */
    private function expedier(Cas $cas, Mailable $mail, string $messageId, ?string $enReponseA, string $resume): bool
    {
        $destinataire = (string) $cas->client_email;

        if ($destinataire === '') {
            Log::warning("Aucun e-mail client sur {$cas->reference} : mail non envoyé.", [
                'mail' => class_basename($mail),
            ]);

            return false;
        }

        // Un mail de Lift qu'on n'a pas su rattacher ouvre un dossier dont le
        // « client » est… Lift. Lui envoyer un accusé lui ouvrirait un ticket, et
        // un « votre dossier a été transmis » n'aurait aucun sens. Le garde-fou
        // est ici, au point de sortie : aucun appelant ne peut le contourner.
        if (Partenaires::est($destinataire)) {
            Log::warning("{$cas->reference} : destinataire partenaire, mail client non envoyé.", [
                'mail' => class_basename($mail),
                'destinataire' => $destinataire,
            ]);

            return false;
        }

        try {
            $parti = $this->expediteur->envoyer($destinataire, $mail);
        } catch (Throwable $e) {
            Log::error("Mail client non envoyé pour {$cas->reference}", [
                'mail' => class_basename($mail),
                'destinataire' => $destinataire,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }

        // Envoi simulé (SAV_ENVOI_ACTIF=false) : rien n'est parti, rien à tracer.
        if (! $parti) {
            return false;
        }

        $cas->messages()->create([
            'message_id' => $messageId,
            'in_reply_to' => $enReponseA,
            'email_references' => $enReponseA,
            'direction' => DirectionMessage::Outbound,
            'from_email' => (string) config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'to_email' => $destinataire,
            'subject' => $mail->envelope()->subject,
            'body_text' => $resume,
            'body_html' => rescue(fn () => $mail->render(), report: false),
            'received_at' => now(),
        ]);

        return true;
    }

    private function dernierEntrant(Cas $cas): ?Message
    {
        return $cas->messages()
            ->where('direction', DirectionMessage::Inbound)
            ->latest('received_at')
            ->first();
    }

    private function messageId(): string
    {
        $adresse = (string) (config('mail.from.address') ?: config('sav.mailbox'));
        $domaine = str_contains($adresse, '@') ? Str::after($adresse, '@') : 'liftfoils.fr';

        return MessageId::genererPourSortant($domaine);
    }
}

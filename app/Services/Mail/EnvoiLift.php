<?php

namespace App\Services\Mail;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Mail\BrouillonLiftMail;
use App\Models\Cas;
use App\Services\Dossier\RegleCompletude;
use App\Support\MessageId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Le seul endroit d'où un dossier part chez Lift.
 *
 * Trois verrous, dans cet ordre :
 *   1. **la règle de complétude** — un dossier auquel il manque une pièce
 *      BLOQUANTE ne part pas, quoi qu'on clique (RegleCompletude) ;
 *   2. **la validation humaine** — cette méthode n'est appelée que par le bouton
 *      « Envoyer à Lift », jamais par la relève ni par le scheduler ;
 *   3. **SAV_ENVOI_ACTIF** — le cran de sûreté général, porté par l'Expediteur.
 *
 * Si l'envoi est simulé (garde-fou fermé), le dossier ne bouge pas d'un pouce :
 * pas de statut « envoyé à Lift », pas de message sortant. Un dossier ne doit
 * jamais prétendre être parti quand rien n'est parti.
 */
class EnvoiLift
{
    public function __construct(private readonly Expediteur $expediteur) {}

    /**
     * Ce qui empêche ce dossier de partir chez Lift, ou null s'il peut partir.
     *
     * Renvoie une phrase montrable telle quelle : c'est ce que Nico lira sous le
     * bouton grisé.
     */
    public function empechement(Cas $cas): ?string
    {
        if (blank($cas->brouillon_lift)) {
            return 'Aucun brouillon. Générez-le d\'abord.';
        }

        $manquants = RegleCompletude::libellesBloquants($cas);

        if ($manquants !== []) {
            return 'Pièces manquantes : '.implode(', ', $manquants).'.';
        }

        return null;
    }

    /**
     * Expédie le brouillon (relu par un humain) à Lift, puis fait entrer le
     * dossier en « Chez Lift ».
     *
     * Le changement de statut déclenche, via CasObserver, le mail FR au client
     * (« votre dossier a été transmis »).
     *
     * @return bool true si le mail est parti, false s'il a été simulé
     *              (SAV_ENVOI_ACTIF=false) — le dossier reste alors intact.
     *
     * @throws RuntimeException si le dossier n'a rien à faire chez Lift (verrou 1).
     */
    public function envoyer(Cas $cas): bool
    {
        if ($empechement = $this->empechement($cas)) {
            throw new RuntimeException($empechement);
        }

        $messageId = $this->messageId();
        $destinataire = (string) config('sav.lift.email');

        $parti = $this->expediteur->envoyer($destinataire, new BrouillonLiftMail($cas, $messageId));

        if (! $parti) {
            Log::info("[SAV_ENVOI_ACTIF=false] {$cas->reference} n'est PAS parti chez Lift : le dossier reste en l'état.");

            return false;
        }

        // Le message sortant, avec NOTRE Message-ID : c'est lui que citera le
        // In-Reply-To de l'accusé Zendesk qui porte le n° de ticket, et c'est
        // ainsi que la réponse de Lift retrouve son dossier toute seule.
        $cas->messages()->create([
            'message_id' => $messageId,
            'direction' => DirectionMessage::Outbound,
            'from_email' => (string) config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'to_email' => $destinataire,
            'subject' => $cas->sujetBrouillonLift(),
            'body_text' => $cas->corpsBrouillonLift(),
            'received_at' => now(),
        ]);

        $cas->forceFill([
            'statut' => StatutCas::EnvoyeLift,
            'envoye_lift_le' => now(),
        ])->save();

        Log::info("Dossier {$cas->reference} envoyé à Lift", ['destinataire' => $destinataire]);

        return true;
    }

    private function messageId(): string
    {
        $adresse = (string) (config('mail.from.address') ?: config('sav.mailbox'));
        $domaine = str_contains($adresse, '@') ? Str::after($adresse, '@') : 'liftfoils.fr';

        return MessageId::genererPourSortant($domaine);
    }
}

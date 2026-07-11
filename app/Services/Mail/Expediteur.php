<?php

namespace App\Services\Mail;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Le seul point par lequel un mail sort de l'outil.
 *
 * Tout envoi — accusé de réception, demande d'infos, futur brouillon Lift —
 * passe ici, et ici seulement se décide s'il part vraiment. À `SAV_ENVOI_ACTIF=false`,
 * l'envoi est simulé (journalisé) : aucune sortie mail, quel que soit l'appelant.
 * C'est le garde-fou qui garantit qu'on n'écrit jamais à un client par accident.
 */
class Expediteur
{
    public function envoiActif(): bool
    {
        return (bool) config('sav.envoi_actif', false);
    }

    /**
     * Envoie le mail au destinataire, ou le simule si l'envoi est désactivé.
     *
     * @return bool true si le mail est réellement parti, false s'il a été simulé.
     */
    public function envoyer(string $destinataire, Mailable $mail): bool
    {
        if (! $this->envoiActif()) {
            Log::info("[SAV_ENVOI_ACTIF=false] envoi simulé vers {$destinataire}", [
                'mail' => class_basename($mail),
            ]);

            return false;
        }

        Mail::to($destinataire)->send($mail);

        return true;
    }
}

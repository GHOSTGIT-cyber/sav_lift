<?php

namespace App\Observers;

use App\Enums\VueDossier;
use App\Models\Cas;
use App\Services\Mail\NotificateurClient;

/**
 * Ce qui doit arriver au client quand un dossier bouge.
 *
 * On écoute le **résultat** (le dossier est entré chez Lift), pas le chemin :
 * qu'il y soit entré par le bouton « Envoyer à Lift », par un statut changé à la
 * main dans le formulaire, ou parce que la relève a capté l'accusé de Lift, le
 * client est prévenu une fois et une seule.
 */
class CasObserver
{
    public function __construct(private readonly NotificateurClient $notificateur) {}

    /** Dater l'entrée chez Lift, même quand le statut est changé à la main. */
    public function updating(Cas $cas): void
    {
        if ($cas->envoye_lift_le === null && VueDossier::de($cas) === VueDossier::ChezLift) {
            $cas->envoye_lift_le = now();
        }
    }

    public function updated(Cas $cas): void
    {
        // `client_avise_lift_le` est le verrou anti-doublon : il fait aussi que
        // l'écriture déclenchée ci-dessous ne se rappelle pas elle-même.
        if ($cas->client_avise_lift_le !== null || VueDossier::de($cas) !== VueDossier::ChezLift) {
            return;
        }

        $this->notificateur->informerTransmissionLift($cas);
    }
}

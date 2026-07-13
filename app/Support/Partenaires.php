<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Lift et son Zendesk : ils nourrissent les dossiers, ils n'en sont jamais les
 * clients.
 *
 * La distinction est ici, en un seul point, parce qu'elle porte un invariant :
 * **on ne leur écrit jamais un mail destiné à un client.** Un accusé de
 * réception envoyé à help@liftfoils.com leur ouvrirait un ticket ; un « votre
 * dossier a été transmis » leur serait absurde. Le cas se présente pour de bon :
 * un mail de Lift qu'on n'a pas su rattacher ouvre un dossier dont le
 * « client_email » est… Lift.
 */
final class Partenaires
{
    public static function est(?string $email): bool
    {
        $email = Str::lower(trim((string) $email));

        if ($email === '') {
            return false;
        }

        foreach ((array) config('sav.expediteurs_partenaires', []) as $domaine) {
            if (str_contains($email, Str::lower((string) $domaine))) {
                return true;
            }
        }

        return false;
    }
}

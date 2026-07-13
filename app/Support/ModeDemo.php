<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Le mode démonstration : une instance publique, sans mot de passe, remplie de
 * dossiers fictifs, que le patron peut faire cliquer à qui il veut.
 *
 * Le danger est évident : ce code vit dans `main`, donc dans **la même image
 * Docker que la production**. Une variable d'env mal placée, et le panneau de
 * prod — 30 dossiers de vrais clients — s'ouvre sans mot de passe sur Internet.
 *
 * D'où la règle : `SAV_DEMO=true` ne suffit PAS. Le mode démo exige en plus que
 * l'instance soit **incapable de toucher au monde réel** :
 *
 *   - aucun mot de passe IMAP → elle ne peut pas relever la boîte sav@, donc
 *     aucun mail de vrai client ne peut y entrer ;
 *   - `SAV_ENVOI_ACTIF=false` → elle ne peut écrire à personne.
 *
 * Ces deux conditions sont exactement ce qui distingue une démo d'une prod. Si
 * l'une manque, le mode démo se **refuse** et hurle dans les journaux, plutôt
 * que d'ouvrir la porte. Fail closed : le défaut de configuration ferme l'accès,
 * il ne l'ouvre jamais.
 */
final class ModeDemo
{
    /** L'instance est-elle une démo publique ? */
    public static function actif(): bool
    {
        if (! config('sav.demo.actif', false)) {
            return false;
        }

        $empechements = self::empechements();

        if ($empechements !== []) {
            Log::critical(
                'SAV_DEMO=true sur une instance qui touche au monde réel : mode démo REFUSÉ.',
                ['empechements' => $empechements],
            );

            return false;
        }

        return true;
    }

    /**
     * Ce qui interdit à cette instance d'être une démo, malgré `SAV_DEMO=true`.
     *
     * @return list<string>
     */
    public static function empechements(): array
    {
        $empechements = [];

        if (filled(config('imap.accounts.default.password'))) {
            $empechements[] = 'un mot de passe IMAP est configuré : cette instance peut relever de vrais mails clients';
        }

        if (config('sav.envoi_actif', false)) {
            $empechements[] = 'SAV_ENVOI_ACTIF=true : cette instance peut écrire à de vraies personnes';
        }

        return $empechements;
    }

    /** Le compte sous lequel le visiteur de la démo est connecté d'office. */
    public static function visiteur(): ?User
    {
        return User::where('email', self::email())->first();
    }

    public static function email(): string
    {
        return (string) config('sav.demo.email', 'demo@liftfoils.fr');
    }
}

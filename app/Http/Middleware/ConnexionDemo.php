<?php

namespace App\Http\Middleware;

use App\Support\ModeDemo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sur l'instance de démonstration, le visiteur est connecté d'office : pas de
 * mot de passe à retenir, pas de compte à créer, on clique et on voit l'outil.
 *
 * Tout tient à ModeDemo::actif(), qui refuse le mode démo sur toute instance
 * capable de relever de vrais mails ou d'en envoyer. Ici, on ne fait que lui
 * obéir : si elle dit non, ce middleware ne fait strictement rien et le panneau
 * réclame un mot de passe, comme en production.
 *
 * On pose aussi un `X-Robots-Tag: noindex` : une démo publique n'a rien à faire
 * dans Google, où elle finirait par apparaître avant le vrai site.
 */
class ConnexionDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ModeDemo::actif()) {
            return $next($request);
        }

        // `visiteur()` peut être null si le seeder n'a pas encore tourné : on
        // laisse alors la page de connexion faire son travail plutôt que de
        // planter. La démo se sème au démarrage du conteneur, c'est transitoire.
        if (! Auth::check() && $visiteur = ModeDemo::visiteur()) {
            Auth::login($visiteur);
        }

        $reponse = $next($request);
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $reponse;
    }
}

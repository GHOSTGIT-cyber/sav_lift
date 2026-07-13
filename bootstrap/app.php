<?php

use App\Http\Middleware\ConnexionDemo;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel TRIE les middlewares d'une route selon une liste de priorité :
        // l'ordre dans lequel on les déclare dans le panneau ne fait pas foi. Le
        // middleware d'authentification est remonté d'office (la liste s'ancre sur
        // l'interface AuthenticatesRequests, que celui de Filament implémente) et
        // renverrait le visiteur vers l'écran de connexion AVANT que ConnexionDemo
        // n'ait eu la main : l'instance de démo réclamerait un mot de passe, alors
        // qu'elle est faite pour ne pas en avoir. On l'insère donc explicitement
        // juste avant le cran d'authentification.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ConnexionDemo::class,
        );

        // Le conteneur n'est joignable qu'à travers le proxy de Coolify.
        // Sans cette ligne, Laravel ignore `X-Forwarded-Proto: https` : il se
        // croit en clair et génère des URLs `http://` dans une page servie en
        // `https://`. Le navigateur bloque alors les scripts (mixed content),
        // Livewire ne démarre pas, et le formulaire de connexion reste inerte.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

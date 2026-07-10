<?php

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

<?php

namespace App\Providers;

use App\Services\Ia\MailExtractor;
use App\Services\Ia\OpenAiMailExtractor;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Le fournisseur IA est isolé derrière l'interface : c'est ici, et nulle
        // part ailleurs, qu'on choisit l'implémentation (API compatible OpenAI :
        // OpenRouter / xAI Grok, pilotée par la config).
        $this->app->bind(MailExtractor::class, OpenAiMailExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

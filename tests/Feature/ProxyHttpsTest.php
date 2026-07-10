<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Derrière le proxy de Coolify, la requête arrive en clair sur le conteneur,
 * avec `X-Forwarded-Proto: https`. Si Laravel ne fait pas confiance au proxy,
 * il génère des URLs `http://` dans une page servie en `https://` : le
 * navigateur bloque les scripts (mixed content) et Filament/Livewire ne
 * démarrent jamais — la page s'affiche, mais rien ne réagit.
 */
class ProxyHttpsTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY_HEADERS = [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'sav.efoilcotedazur.fr',
        'X-Forwarded-Port' => '443',
    ];

    public function test_la_requete_derriere_le_proxy_est_vue_comme_securisee(): void
    {
        $this->withHeaders(self::PROXY_HEADERS)->get('/up')->assertOk();

        $this->assertTrue(
            request()->isSecure(),
            'Laravel ne fait pas confiance au proxy : voir trustProxies() dans bootstrap/app.php.',
        );
    }

    public function test_la_redirection_vers_la_connexion_reste_en_https(): void
    {
        $this->withHeaders(self::PROXY_HEADERS)
            ->get('/admin')
            ->assertRedirect('https://sav.efoilcotedazur.fr/admin/login');
    }

    public function test_la_page_de_connexion_ne_charge_aucun_script_en_clair(): void
    {
        $reponse = $this->withHeaders(self::PROXY_HEADERS)->get('/admin/login')->assertOk();

        $this->assertStringNotContainsString(
            'src="http://',
            $reponse->getContent(),
            'Script chargé en http:// sur une page https:// : le navigateur le bloquera (mixed content).',
        );
    }
}

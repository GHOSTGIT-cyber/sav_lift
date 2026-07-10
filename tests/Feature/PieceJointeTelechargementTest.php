<?php

namespace Tests\Feature;

use App\Models\Cas;
use App\Models\PieceJointe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Les pièces jointes sont des données clients sur un disque privé. Elles ne
 * doivent sortir que pour un utilisateur connecté, et jamais s'exécuter dans
 * le navigateur du technicien.
 */
class PieceJointeTelechargementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function pieceJointe(string $mime = 'image/jpeg', string $contenu = 'contenu-binaire'): PieceJointe
    {
        $cas = Cas::create(['reference' => 'SAV-2026-0001', 'client_nom' => 'Camille']);

        $chemin = "sav/{$cas->id}/abcd1234-photo.jpg";
        Storage::disk('local')->put($chemin, $contenu);

        return PieceJointe::create([
            'cas_id' => $cas->id,
            'path' => $chemin,
            'filename' => 'photo.jpg',
            'mime' => $mime,
            'taille' => strlen($contenu),
        ]);
    }

    public function test_un_visiteur_anonyme_ne_telecharge_rien(): void
    {
        $piece = $this->pieceJointe();

        $this->get(route('filament.admin.pieces-jointes.telecharger', $piece))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->get(route('filament.admin.pieces-jointes.apercu', $piece))
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_un_utilisateur_connecte_telecharge_la_piece_jointe(): void
    {
        $piece = $this->pieceJointe();

        $reponse = $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.pieces-jointes.telecharger', $piece))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('attachment', $reponse->headers->get('content-disposition'));
        $this->assertSame('contenu-binaire', $reponse->streamedContent());
    }

    public function test_l_apercu_d_une_image_est_servi_en_ligne(): void
    {
        $piece = $this->pieceJointe();

        $reponse = $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.pieces-jointes.apercu', $piece))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('inline', $reponse->headers->get('content-disposition'));
    }

    /**
     * Un SVG est un document XML : servi inline, son <script> s'exécuterait
     * sur le domaine du panneau, dans la session du technicien.
     */
    public function test_un_svg_n_est_jamais_affiche_en_ligne(): void
    {
        $piece = $this->pieceJointe(mime: 'image/svg+xml');

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.pieces-jointes.apercu', $piece))
            ->assertNotFound();
    }

    public function test_un_html_n_est_jamais_affiche_en_ligne(): void
    {
        $piece = $this->pieceJointe(mime: 'text/html');

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.pieces-jointes.apercu', $piece))
            ->assertNotFound();
    }

    public function test_un_svg_reste_telechargeable(): void
    {
        $piece = $this->pieceJointe(mime: 'image/svg+xml');

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.pieces-jointes.telecharger', $piece))
            ->assertOk();
    }

    public function test_un_fichier_absent_du_disque_donne_un_404(): void
    {
        $piece = $this->pieceJointe();
        Storage::disk('local')->delete($piece->path);

        $this->actingAs(User::factory()->create())
            ->get(route('filament.admin.pieces-jointes.telecharger', $piece))
            ->assertNotFound();
    }
}

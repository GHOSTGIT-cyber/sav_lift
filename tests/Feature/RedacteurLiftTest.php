<?php

namespace Tests\Feature;

use App\Models\Cas;
use App\Services\Ia\ExtractionException;
use App\Services\Ia\RedacteurLift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RedacteurLiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sav.ia.cle', 'cle-de-test');
        config()->set('sav.ia.url', 'https://openrouter.test/api/v1/chat/completions');
        config()->set('sav.lift.email', 'help@liftfoils.com');
        Mail::fake();
    }

    private function fakeDraft(string $texte): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => $texte]]],
            ]),
        ]);
    }

    private function dossier(): Cas
    {
        return Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-123456',
            'sales_order' => 'SO-99',
            'contexte' => 'ne charge plus après un choc',
            'description' => 'Ma batterie ne charge plus.',
        ]);
    }

    public function test_le_brouillon_est_genere_et_renvoye(): void
    {
        $this->fakeDraft("Subject: Battery not charging — MHS-123456\n\nHello Lift team, ...");

        $brouillon = app(RedacteurLift::class)->rediger($this->dossier());

        $this->assertStringContainsString('MHS-123456', $brouillon);
        Mail::assertNothingSent(); // un brouillon ne part jamais
    }

    public function test_les_donnees_du_dossier_partent_dans_la_requete(): void
    {
        $this->fakeDraft('Subject: x');

        app(RedacteurLift::class)->rediger($this->dossier());

        Http::assertSent(function ($request) {
            $body = $request->data();
            $systeme = $body['messages'][0]['content'];
            $user = $body['messages'][1]['content'];

            return str_contains($systeme, 'ENGLISH')
                && str_contains($systeme, 'help@liftfoils.com')
                && str_contains($user, 'MHS-123456')   // MHS transmis verbatim
                && str_contains($user, 'SO-99');
        });
    }

    public function test_echec_ia_leve_une_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'down'], 500)]);

        $this->expectException(ExtractionException::class);
        app(RedacteurLift::class)->rediger($this->dossier());
    }

    public function test_desactive_sans_cle(): void
    {
        config()->set('sav.ia.cle', '');

        $this->assertFalse(app(RedacteurLift::class)->estConfigure());
    }
}

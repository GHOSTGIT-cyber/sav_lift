<?php

namespace Tests\Feature;

use App\Models\Cas;
use App\Services\Mail\IngesteurMail;
use App\Services\Mail\MailEntrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Eml;
use Tests\TestCase;

/**
 * Vérifie que l'extraction IA est bien branchée dans la relève : un nouveau
 * dossier est enrichi à la volée. L'appel IA reste mocké.
 */
class IngestionExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        config()->set('sav.mailbox', 'sav@liftfoils.fr');
        config()->set('mail.from.address', 'sav@liftfoils.fr');
    }

    private function ingerer(Eml $eml): void
    {
        app(IngesteurMail::class)->ingerer(MailEntrant::depuis($eml->parser()));
    }

    public function test_un_nouveau_dossier_est_enrichi_pendant_la_releve(): void
    {
        config()->set('sav.ia.cle', 'cle-de-test');
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode([
                    'produit' => 'batterie', 'modele' => 'Lift4', 'mhs' => 'MHS-42',
                    'sales_order' => null, 'contexte' => 'ne charge plus', 'urgent' => false,
                ], JSON_UNESCAPED_UNICODE)]]],
            ]),
        ]);

        $this->ingerer(Eml::make()->texte('Ma batterie Lift4 (MHS-42) ne charge plus.'));

        $cas = Cas::sole();
        $this->assertSame('batterie', $cas->produit);
        $this->assertSame('MHS-42', $cas->numero_serie);
        $this->assertTrue($cas->complet);
        Http::assertSentCount(1);
    }

    public function test_sans_cle_le_dossier_est_cree_mais_non_enrichi(): void
    {
        config()->set('sav.ia.cle', '');
        Http::fake();

        $this->ingerer(Eml::make()->texte('Batterie MHS-42 morte.'));

        $cas = Cas::sole();
        $this->assertNull($cas->produit);
        $this->assertFalse($cas->complet);
        $this->assertNull($cas->extrait_le);
        Http::assertNothingSent();
    }

    public function test_un_echec_d_extraction_ne_bloque_pas_la_creation_du_dossier(): void
    {
        config()->set('sav.ia.cle', 'cle-de-test');
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $this->ingerer(Eml::make()->texte('Batterie morte.'));

        // Dossier créé malgré l'échec IA, et l'erreur est tracée.
        $cas = Cas::sole();
        $this->assertNotNull($cas->extraction_erreur);
        $this->assertNull($cas->produit);
    }
}

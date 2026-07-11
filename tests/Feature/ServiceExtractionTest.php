<?php

namespace Tests\Feature;

use App\Models\Cas;
use App\Services\Ia\ExtractionException;
use App\Services\Ia\MailExtractor;
use App\Services\Ia\ResultatExtraction;
use App\Services\Ia\ServiceExtraction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ServiceExtractionTest extends TestCase
{
    use RefreshDatabase;

    private function service(MailExtractor $extractor): ServiceExtraction
    {
        return new ServiceExtraction($extractor);
    }

    private function extracteurRendant(array $donnees): MailExtractor
    {
        $mock = Mockery::mock(MailExtractor::class);
        $mock->shouldReceive('extract')->andReturn($donnees);

        return $mock;
    }

    public function test_l_extraction_remplit_le_dossier_et_calcule_complet(): void
    {
        config()->set('sav.ia.cle', 'x');
        $cas = Cas::create(['reference' => 'SAV-2026-0001', 'description' => 'demande']);

        $resultat = $this->service($this->extracteurRendant([
            'produit' => 'batterie', 'modele' => 'Lift4', 'mhs' => 'MHS-1',
            'sales_order' => 'SO-1', 'contexte' => 'choc', 'urgent' => true,
        ]))->pourCas($cas);

        $this->assertSame(ResultatExtraction::Enrichi, $resultat);
        $cas->refresh();
        $this->assertSame('batterie', $cas->produit);
        $this->assertSame('MHS-1', $cas->numero_serie);
        $this->assertSame('choc', $cas->contexte);
        $this->assertTrue($cas->urgent);
        $this->assertTrue($cas->complet); // produit + MHS présents
        $this->assertNotNull($cas->extrait_le);
    }

    public function test_incomplet_si_le_mhs_manque(): void
    {
        config()->set('sav.ia.cle', 'x');
        $cas = Cas::create(['reference' => 'SAV-2026-0002']);

        $this->service($this->extracteurRendant([
            'produit' => 'moteur', 'modele' => null, 'mhs' => null,
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ]))->pourCas($cas);

        $this->assertFalse($cas->refresh()->complet);
    }

    /** Un champ déjà rempli (saisi par un humain) n'est jamais écrasé. */
    public function test_l_extraction_n_ecrase_pas_un_champ_deja_rempli(): void
    {
        config()->set('sav.ia.cle', 'x');
        $cas = Cas::create([
            'reference' => 'SAV-2026-0003',
            'numero_serie' => 'MHS-HUMAIN',
            'produit' => 'batterie',
        ]);

        $this->service($this->extracteurRendant([
            'produit' => 'moteur', 'modele' => 'X', 'mhs' => 'MHS-IA',
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ]))->pourCas($cas);

        $cas->refresh();
        $this->assertSame('MHS-HUMAIN', $cas->numero_serie);   // conservé
        $this->assertSame('batterie', $cas->produit);           // conservé
        $this->assertSame('X', $cas->modele);                   // complété (était vide)
    }

    public function test_sans_cle_l_extraction_est_desactivee(): void
    {
        config()->set('sav.ia.cle', '');
        $cas = Cas::create(['reference' => 'SAV-2026-0004']);

        $extractor = Mockery::mock(MailExtractor::class);
        $extractor->shouldNotReceive('extract');

        $resultat = $this->service($extractor)->pourCas($cas);

        $this->assertSame(ResultatExtraction::Desactivee, $resultat);
        $this->assertNull($cas->refresh()->extrait_le);
    }

    public function test_un_echec_marque_le_dossier_sans_lever(): void
    {
        config()->set('sav.ia.cle', 'x');
        $cas = Cas::create(['reference' => 'SAV-2026-0005']);

        $extractor = Mockery::mock(MailExtractor::class);
        $extractor->shouldReceive('extract')->andThrow(new ExtractionException('API HS'));

        $resultat = $this->service($extractor)->pourCas($cas);

        $this->assertSame(ResultatExtraction::Echec, $resultat);
        $cas->refresh();
        $this->assertStringContainsString('API HS', (string) $cas->extraction_erreur);
        $this->assertFalse($cas->complet);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

<?php

namespace Tests\Feature;

use App\Mail\AccuseReceptionMail;
use App\Models\Cas;
use App\Services\Dossier\RegleCompletude;
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
        Http::assertSentCount(1);

        // Enrichi, mais pas encore actionnable : il manque les pièces que seul le
        // client peut fournir (photo de l'étiquette, photos du défaut, facture).
        $this->assertFalse($cas->complet);
        $this->assertSame(
            ['Photo de l\'étiquette MHS', 'Facture ou Sales Order', 'Photos / vidéos du défaut'],
            RegleCompletude::libellesBloquants($cas),
        );
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

    /**
     * Le correctif « flux Nico », bout en bout : l'accusé part APRÈS l'extraction,
     * et ne réclame donc QUE ce que le client n'a pas déjà donné.
     *
     * Ici le mail du client porte le modèle, le MHS et le Sales Order : l'accusé ne
     * doit plus les redemander. Il réclame en revanche les pièces qui manquent
     * vraiment — la photo de l'étiquette, les photos du défaut.
     */
    public function test_l_accuse_ne_reclame_que_ce_qui_manque_apres_extraction(): void
    {
        config()->set('sav.ia.cle', 'cle-de-test');
        config()->set('sav.envoi_actif', true);
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'produit' => 'batterie', 'modele' => 'Lift4', 'mhs' => 'MHS-42',
                    'sales_order' => 'SO-99', 'date_achat' => null,
                    'contexte' => 'ne charge plus', 'urgent' => false,
                ], JSON_UNESCAPED_UNICODE)]]],
            ]),
        ]);

        $this->ingerer(Eml::make()->texte('Ma batterie Lift4 (MHS-42, commande SO-99) ne charge plus.'));

        Mail::assertSent(AccuseReceptionMail::class, function (AccuseReceptionMail $accuse): bool {
            // Déjà fourni : on ne le redemande pas.
            $this->assertNotContains('le numéro MHS / numéro de série, situé sur le flanc arrière droit de la planche ou sur l\'étiquette du produit concerné', $accuse->demandes);
            $this->assertNotContains('la facture d\'achat ou le numéro de Sales Order si vous l\'avez', $accuse->demandes);

            // Toujours manquant : on le réclame.
            $this->assertContains('une photo lisible de l\'étiquette du numéro de série', $accuse->demandes);
            $this->assertContains('des photos et/ou vidéos montrant clairement le défaut', $accuse->demandes);

            return true;
        });
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

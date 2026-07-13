<?php

namespace Tests\Feature;

use App\Enums\DirectionMessage;
use App\Models\Cas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExtractionCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sav.ia.cle', 'cle-de-test');
        config()->set('sav.ia.url', 'https://api.anthropic.test/v1/messages');
        Mail::fake();
    }

    private function fakeExtraction(array $input): void
    {
        // Réponse compatible OpenAI (OpenRouter / Grok) : le JSON extrait est
        // dans choices[0].message.content.
        Http::fake([
            '*' => Http::response([
                'choices' => [
                    ['message' => ['role' => 'assistant', 'content' => json_encode($input, JSON_UNESCAPED_UNICODE)]],
                ],
            ]),
        ]);
    }

    private function dossierAvecMail(string $ref, string $corps): Cas
    {
        $cas = Cas::create(['reference' => $ref, 'description' => $corps]);
        $cas->messages()->create([
            'message_id' => "m-{$ref}@x.test",
            'direction' => DirectionMessage::Inbound,
            'from_email' => 'client@example.test',
            'subject' => 'SAV',
            'body_text' => $corps,
            'received_at' => now(),
        ]);

        return $cas;
    }

    public function test_backfill_enrichit_les_dossiers_sans_envoyer_de_mail(): void
    {
        $this->dossierAvecMail('SAV-2026-0001', 'Batterie MHS-1 ne charge plus');
        $this->dossierAvecMail('SAV-2026-0002', 'Télécommande cassée');

        $this->fakeExtraction([
            'produit' => 'batterie', 'modele' => null, 'mhs' => 'MHS-1',
            'sales_order' => null, 'contexte' => 'ne charge plus', 'urgent' => false,
        ]);

        $this->artisan('sav:extract-backfill')
            ->assertSuccessful()
            ->expectsOutputToContain('Enrichis');

        $this->assertSame('batterie', Cas::where('reference', 'SAV-2026-0001')->sole()->produit);
        $this->assertSame(2, Cas::whereNotNull('extrait_le')->count());
        Mail::assertNothingSent();
    }

    public function test_backfill_ne_retraite_pas_les_deja_extraits_par_defaut(): void
    {
        $cas = $this->dossierAvecMail('SAV-2026-0001', 'Batterie MHS-1');
        $cas->forceFill(['extrait_le' => now()->subDay()])->save();

        $this->fakeExtraction([
            'produit' => 'batterie', 'modele' => null, 'mhs' => 'MHS-1',
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ]);

        $this->artisan('sav:extract-backfill')->assertSuccessful();

        // Déjà extrait → ignoré → aucun appel IA.
        Http::assertNothingSent();
    }

    /**
     * Un quota gratuit épuisé (429) ne doit pas enterrer le dossier : le
     * backfill suivant doit le reprendre.
     */
    public function test_backfill_reprend_un_dossier_en_echec(): void
    {
        $this->dossierAvecMail('SAV-2026-0001', 'Batterie MHS-1');

        // Un SEUL fake, piloté par un état : deux Http::fake() successifs
        // s'empilent (c'est le premier stub qui gagne), ils ne se remplacent pas.
        $quotaEpuise = true;
        Http::fake(function () use (&$quotaEpuise) {
            return $quotaEpuise
                ? Http::response(['error' => 'rate limit exceeded'], 429)
                : Http::response(['choices' => [['message' => ['content' => json_encode([
                    'produit' => 'batterie', 'modele' => null, 'mhs' => 'MHS-1',
                    'sales_order' => null, 'contexte' => null, 'urgent' => false,
                ])]]]]);
        });

        // 1er passage : quota dépassé → échec, mais le dossier reste « à extraire ».
        $this->artisan('sav:extract-backfill')->assertSuccessful();

        $cas = Cas::sole();
        $this->assertNotNull($cas->extraction_erreur);
        $this->assertNull($cas->extrait_le);

        // Un 429 ne doit PAS être re-tenté : une seule requête consommée.
        Http::assertSentCount(1);

        // 2e passage, quota revenu : le dossier est repris sans --tous.
        $quotaEpuise = false;
        $this->artisan('sav:extract-backfill')->assertSuccessful();

        $cas->refresh();
        $this->assertSame('batterie', $cas->produit);
        $this->assertNotNull($cas->extrait_le);
        $this->assertNull($cas->extraction_erreur);
    }

    public function test_backfill_desactive_sans_cle(): void
    {
        config()->set('sav.ia.cle', '');
        $this->dossierAvecMail('SAV-2026-0001', 'x');
        Http::fake();

        $this->artisan('sav:extract-backfill')->assertFailed();
        Http::assertNothingSent();
    }

    public function test_extract_un_dossier_par_reference(): void
    {
        $this->dossierAvecMail('SAV-2026-0007', 'Moteur MHS-9 fait du bruit');

        $this->fakeExtraction([
            'produit' => 'moteur', 'modele' => null, 'mhs' => 'MHS-9',
            'sales_order' => null, 'contexte' => 'bruit', 'urgent' => false,
        ]);

        $this->artisan('sav:extract SAV-2026-0007')
            ->assertSuccessful()
            ->expectsOutputToContain('MHS-9');

        $cas = Cas::where('reference', 'SAV-2026-0007')->sole();
        $this->assertSame('moteur', $cas->produit);
        $this->assertTrue($cas->complet);
        Mail::assertNothingSent();
    }
}

<?php

namespace Tests\Feature;

use App\Services\Ia\ExtractionException;
use App\Services\Ia\MailExtractor;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * L'appel IA (compatible OpenAI : OpenRouter / Grok) est mocké : on vérifie la
 * requête (Bearer, response_format, rôles) et le décodage tolérant de la réponse.
 */
class OpenAiMailExtractorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sav.ia.cle', 'cle-de-test');
        config()->set('sav.ia.url', 'https://openrouter.test/api/v1/chat/completions');
        config()->set('sav.ia.modele', 'x-ai/grok-4-fast:free');
        config()->set('sav.ia.json_mode', true);
    }

    /** Réponse au format chat/completions, `content` = chaîne JSON. */
    private function reponse(string $contenu): array
    {
        return [
            'id' => 'chatcmpl-test',
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $contenu], 'finish_reason' => 'stop'],
            ],
        ];
    }

    private function jsonExtraction(array $champs): string
    {
        return json_encode($champs, JSON_UNESCAPED_UNICODE);
    }

    private function extractor(): MailExtractor
    {
        return app(MailExtractor::class);
    }

    public function test_les_champs_sont_extraits_verbatim(): void
    {
        Http::fake(['*' => Http::response($this->reponse($this->jsonExtraction([
            'produit' => 'batterie', 'modele' => 'Lift4', 'mhs' => 'MHS-123456',
            'sales_order' => 'SO-98765', 'date_achat' => '12/03/2025',
            'contexte' => 'choc puis plus de charge', 'urgent' => true,
        ])))]);

        $this->assertSame([
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'mhs' => 'MHS-123456',
            'sales_order' => 'SO-98765',
            'date_achat' => '12/03/2025',
            'contexte' => 'choc puis plus de charge',
            'urgent' => true,
        ], $this->extractor()->extract('Ma batterie Lift4, MHS-123456, achetée le 12/03/2025, ne charge plus après un choc.'));
    }

    public function test_les_champs_absents_reviennent_null(): void
    {
        Http::fake(['*' => Http::response($this->reponse($this->jsonExtraction([
            'produit' => null, 'modele' => null, 'mhs' => null,
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ])))]);

        $d = $this->extractor()->extract('Bonjour, un souci.');

        $this->assertNull($d['mhs']);
        $this->assertFalse($d['urgent']);
    }

    public function test_le_json_entoure_de_fences_markdown_est_decode(): void
    {
        $bloc = "Voici le résultat :\n```json\n".$this->jsonExtraction([
            'produit' => 'moteur', 'modele' => null, 'mhs' => 'MHS-9',
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ])."\n```";

        Http::fake(['*' => Http::response($this->reponse($bloc))]);

        $d = $this->extractor()->extract('texte');

        $this->assertSame('moteur', $d['produit']);
        $this->assertSame('MHS-9', $d['mhs']);
    }

    public function test_les_valeurs_parasites_sont_normalisees(): void
    {
        Http::fake(['*' => Http::response($this->reponse($this->jsonExtraction([
            'produit' => 'gadget', 'modele' => '  Lift5  ', 'mhs' => 'null',
            'sales_order' => 'N/A', 'contexte' => '', 'urgent' => 'true',
        ])))]);

        $d = $this->extractor()->extract('texte');

        $this->assertSame('autre', $d['produit']);
        $this->assertSame('Lift5', $d['modele']);
        $this->assertNull($d['mhs']);
        $this->assertNull($d['sales_order']);
        $this->assertNull($d['contexte']);
        $this->assertTrue($d['urgent']);
    }

    public function test_la_requete_est_bien_formee(): void
    {
        Http::fake(['*' => Http::response($this->reponse($this->jsonExtraction([
            'produit' => null, 'modele' => null, 'mhs' => null,
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ])))]);

        $this->extractor()->extract('un mail');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->hasHeader('Authorization', 'Bearer cle-de-test')
                && $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && $body['model'] === 'x-ai/grok-4-fast:free'
                && $body['temperature'] === 0
                && $body['response_format'] === ['type' => 'json_object']
                && $body['messages'][0]['role'] === 'system'
                && $body['messages'][1]['role'] === 'user';
        });
    }

    public function test_json_mode_desactivable(): void
    {
        config()->set('sav.ia.json_mode', false);
        Http::fake(['*' => Http::response($this->reponse($this->jsonExtraction([
            'produit' => null, 'modele' => null, 'mhs' => null,
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ])))]);

        $this->extractor()->extract('un mail');

        Http::assertSent(fn ($request) => ! array_key_exists('response_format', $request->data()));
    }

    public function test_une_erreur_http_leve_une_exception(): void
    {
        Http::fake(['*' => Http::response(['error' => 'rate limited'], 429)]);

        $this->expectException(ExtractionException::class);
        $this->extractor()->extract('texte');
    }

    public function test_une_erreur_dans_un_corps_200_leve_une_exception(): void
    {
        // OpenRouter renvoie parfois HTTP 200 avec un objet error.
        Http::fake(['*' => Http::response(['error' => ['message' => 'no free credits']], 200)]);

        $this->expectException(ExtractionException::class);
        $this->extractor()->extract('texte');
    }

    public function test_un_contenu_non_json_leve_une_exception(): void
    {
        Http::fake(['*' => Http::response($this->reponse('Je ne peux pas répondre.'))]);

        $this->expectException(ExtractionException::class);
        $this->extractor()->extract('texte');
    }

    public function test_sans_cle_l_extraction_leve_une_exception(): void
    {
        config()->set('sav.ia.cle', '');
        Http::fake();

        $this->expectException(ExtractionException::class);
        $this->extractor()->extract('texte');

        Http::assertNothingSent();
    }

    public function test_un_contenu_vide_ne_declenche_aucun_appel(): void
    {
        Http::fake();

        $d = $this->extractor()->extract('   ');

        $this->assertNull($d['mhs']);
        Http::assertNothingSent();
    }
}

<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Extraction via une API **compatible OpenAI** (chat/completions) : OpenRouter,
 * xAI Grok, ou tout autre fournisseur parlant le même protocole. C'est la seule
 * classe qui touche le fournisseur (voir CLAUDE.md) ; en changer = fournir une
 * autre implémentation de MailExtractor.
 *
 * On ne dépend PAS du function-calling (support inégal sur les modèles gratuits) :
 * on demande un objet JSON au format imposé (avec `response_format` quand le
 * modèle le supporte), et on parse défensivement. Règle cardinale portée par le
 * prompt : chaque champ **verbatim ou null**, MHS et Sales Order jamais inventés.
 */
final class OpenAiMailExtractor implements MailExtractor
{
    /** Catégories produit connues chez Lift ; le reste tombe sur « autre » ou null. */
    private const PRODUITS = [
        'batterie', 'telecommande', 'ebox_esc', 'moteur',
        'mat', 'chargeur', 'planche', 'foil', 'autre',
    ];

    private const SYSTEME = <<<'TXT'
        Tu assistes un SAV de matériel eFoil Lift Foils. À partir d'un e-mail client
        (français ou anglais), tu extrais des informations pour remplir un dossier.

        Réponds UNIQUEMENT par un objet JSON valide, sans texte autour, sans
        Markdown, avec EXACTEMENT ces clés :
          "produit"     : catégorie parmi ["batterie","telecommande","ebox_esc",
                          "moteur","mat","chargeur","planche","foil","autre"], ou null
          "modele"      : modèle exact cité (ex. "Lift4", "MY H3"), verbatim, ou null
          "mhs"         : numéro de série MHS, verbatim caractère pour caractère, ou null
          "sales_order" : numéro de commande / Sales Order, verbatim, ou null
          "contexte"    : court résumé EN FRANÇAIS du problème et du contexte
                          (choc, immersion, transport, depuis quand), ou null
          "urgent"      : true si le mail exprime une urgence, sinon false

        Règles absolues :
        - Chaque champ est rempli VERBATIM depuis l'e-mail, ou null s'il est absent.
          N'invente JAMAIS, ne déduis pas, ne complète pas.
        - Le MHS et le Sales Order sont recopiés à l'identique, ou null. En cas de
          doute : null.
        TXT;

    public function __construct(private readonly HttpFactory $http) {}

    public function extract(string $contenu): array
    {
        $cle = trim((string) config('sav.ia.cle'));

        if ($cle === '') {
            throw new ExtractionException('Clé IA absente (SAV_IA_CLE / OPENROUTER_API_KEY / XAI_API_KEY).');
        }

        $contenu = trim($contenu);

        if ($contenu === '') {
            return $this->vide();
        }

        $charge = [
            'model' => (string) config('sav.ia.modele'),
            'max_tokens' => (int) config('sav.ia.max_tokens', 1024),
            // Extraction déterministe : pas de créativité.
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEME],
                ['role' => 'user', 'content' => Str::limit($contenu, 12000, '')],
            ],
        ];

        if ((bool) config('sav.ia.json_mode', true)) {
            $charge['response_format'] = ['type' => 'json_object'];
        }

        try {
            $reponse = $this->http
                ->withHeaders($this->entetes($cle))
                ->timeout((int) config('sav.ia.timeout', 30))
                ->retry(2, 500, throw: false)
                ->post((string) config('sav.ia.url'), $charge);
        } catch (Throwable $e) {
            throw new ExtractionException('Appel IA impossible : '.$e->getMessage(), previous: $e);
        }

        if ($reponse->failed()) {
            throw new ExtractionException(
                "L'IA a répondu HTTP {$reponse->status()} : ".Str::limit((string) $reponse->body(), 300),
            );
        }

        return $this->normaliser($this->decoderJson($this->contenuReponse($reponse->json())));
    }

    /** @return array<string, string> */
    private function entetes(string $cle): array
    {
        return [
            'Authorization' => 'Bearer '.$cle,
            'content-type' => 'application/json',
            // Attribution OpenRouter (ignorée par les autres fournisseurs).
            'HTTP-Referer' => (string) config('sav.ia.app_url'),
            'X-Title' => (string) config('sav.ia.app_titre'),
        ];
    }

    /**
     * Extrait le texte de la réponse. Certains fournisseurs (OpenRouter) renvoient
     * un HTTP 200 avec un objet `error` : on le traite comme un échec.
     */
    private function contenuReponse(mixed $json): string
    {
        if (! is_array($json)) {
            throw new ExtractionException('Réponse IA illisible.');
        }

        if (isset($json['error'])) {
            $message = is_array($json['error']) ? ($json['error']['message'] ?? 'erreur') : (string) $json['error'];
            throw new ExtractionException('Erreur IA : '.Str::limit((string) $message, 200));
        }

        $contenu = $json['choices'][0]['message']['content'] ?? null;

        if (! is_string($contenu) || trim($contenu) === '') {
            throw new ExtractionException('Réponse IA sans contenu (refus, ou modèle sans réponse).');
        }

        return $contenu;
    }

    /**
     * Parse défensif : un modèle gratuit peut entourer le JSON de fences Markdown
     * ou d'une phrase. On isole le premier objet `{…}` et on le décode.
     *
     * @return array<string, mixed>
     */
    private function decoderJson(string $contenu): array
    {
        $contenu = trim($contenu);

        // Retire les fences ```json … ```
        if (str_starts_with($contenu, '```')) {
            $contenu = (string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $contenu);
            $contenu = trim($contenu);
        }

        // Isole le premier objet JSON si du texte l'entoure.
        if (! str_starts_with($contenu, '{') && preg_match('/\{.*\}/s', $contenu, $m) === 1) {
            $contenu = $m[0];
        }

        $data = json_decode($contenu, true);

        if (! is_array($data)) {
            throw new ExtractionException('Réponse IA non-JSON : '.Str::limit($contenu, 200));
        }

        return $data;
    }

    /**
     * Ceinture et bretelles côté PHP : on retype, on borne aux catégories
     * connues, et on ramène toute valeur vide à null.
     *
     * @param  array<string, mixed>  $data
     * @return array{produit: ?string, modele: ?string, mhs: ?string, sales_order: ?string, contexte: ?string, urgent: bool}
     */
    private function normaliser(array $data): array
    {
        $produit = $this->texteOuNull($data['produit'] ?? null);

        if ($produit !== null && ! in_array($produit, self::PRODUITS, true)) {
            $produit = 'autre';
        }

        return [
            'produit' => $produit,
            'modele' => $this->texteOuNull($data['modele'] ?? null),
            'mhs' => $this->texteOuNull($data['mhs'] ?? null),
            'sales_order' => $this->texteOuNull($data['sales_order'] ?? null),
            'contexte' => $this->texteOuNull($data['contexte'] ?? null),
            'urgent' => filter_var($data['urgent'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }

    private function texteOuNull(mixed $valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }

        $valeur = trim($valeur);

        return in_array(Str::lower($valeur), ['', 'null', 'n/a', 'na', 'inconnu', 'unknown'], true)
            ? null
            : $valeur;
    }

    /** @return array{produit: null, modele: null, mhs: null, sales_order: null, contexte: null, urgent: false} */
    private function vide(): array
    {
        return [
            'produit' => null, 'modele' => null, 'mhs' => null,
            'sales_order' => null, 'contexte' => null, 'urgent' => false,
        ];
    }
}

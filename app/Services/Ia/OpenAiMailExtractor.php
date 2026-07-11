<?php

namespace App\Services\Ia;

use Illuminate\Support\Str;

/**
 * Extraction des champs SAV via le fournisseur IA (ClientIa).
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

    public function __construct(private readonly ClientIa $client) {}

    public function extract(string $contenu): array
    {
        $contenu = trim($contenu);

        if ($contenu === '') {
            return $this->vide();
        }

        // response_format seulement si activé (certains modèles gratuits ne le
        // supportent pas) ; le prompt demande déjà du JSON, le parse est tolérant.
        $extra = (bool) config('sav.ia.json_mode', true)
            ? ['response_format' => ['type' => 'json_object']]
            : [];

        $texte = $this->client->completion([
            ['role' => 'system', 'content' => self::SYSTEME],
            ['role' => 'user', 'content' => Str::limit($contenu, 12000, '')],
        ], $extra);

        return $this->normaliser($this->decoderJson($texte));
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

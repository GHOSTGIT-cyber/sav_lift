<?php

namespace App\Services\Ia;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Le seul point qui parle au fournisseur IA (voir CLAUDE.md).
 *
 * API **compatible OpenAI** (chat/completions) : OpenRouter, xAI Grok, ou tout
 * autre fournisseur du même protocole. L'extraction (OpenAiMailExtractor) et la
 * rédaction du brouillon Lift (RedacteurLift) passent toutes deux par ici : en
 * changer de fournisseur, c'est ne toucher que cette classe et la config.
 */
final class ClientIa
{
    public function __construct(private readonly HttpFactory $http) {}

    /** Une clé est-elle configurée ? (sinon l'IA est désactivée). */
    public function estConfigure(): bool
    {
        return filled(config('sav.ia.cle'));
    }

    /**
     * Envoie une conversation au modèle et renvoie le texte de sa réponse.
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $extra  Champs supplémentaires du payload (ex. response_format).
     *
     * @throws ExtractionException en cas de clé absente, réseau, HTTP non-2xx, ou réponse vide.
     */
    public function completion(array $messages, array $extra = []): string
    {
        $cle = trim((string) config('sav.ia.cle'));

        if ($cle === '') {
            throw new ExtractionException('Clé IA absente (SAV_IA_CLE / OPENROUTER_API_KEY / XAI_API_KEY).');
        }

        $charge = array_merge([
            'model' => (string) config('sav.ia.modele'),
            'max_tokens' => (int) config('sav.ia.max_tokens', 1024),
            // Déterministe : ni extraction ni brouillon n'ont besoin de créativité.
            'temperature' => 0,
            'messages' => $messages,
        ], $extra);

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

        return $this->contenu($reponse->json());
    }

    /** @return array<string, string> */
    private function entetes(string $cle): array
    {
        return [
            'Authorization' => 'Bearer '.$cle,
            'content-type' => 'application/json',
            // Attribution OpenRouter (ignorée ailleurs).
            'HTTP-Referer' => (string) config('sav.ia.app_url'),
            'X-Title' => (string) config('sav.ia.app_titre'),
        ];
    }

    /**
     * Extrait le texte de la réponse. Certains fournisseurs (OpenRouter) renvoient
     * un HTTP 200 avec un objet `error` : on le traite comme un échec.
     */
    private function contenu(mixed $json): string
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
}

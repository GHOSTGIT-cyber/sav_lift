<?php

namespace App\Services\Ia;

use App\Models\Cas;
use Illuminate\Support\Str;

/**
 * Rédige le brouillon d'e-mail EN anglais vers Lift Foils à partir d'un dossier,
 * via le même fournisseur IA que l'extraction (ClientIa).
 *
 * Le brouillon est **toujours un brouillon** : cette classe ne fait que produire
 * le texte. Il n'est jamais envoyé automatiquement — un humain le relit, puis
 * l'envoi (le jour venu) passe par l'Expediteur et son garde-fou SAV_ENVOI_ACTIF.
 *
 * Règle héritée du SAV : le MHS et le Sales Order sont recopiés verbatim ou omis,
 * jamais inventés.
 */
final class RedacteurLift
{
    public function __construct(private readonly ClientIa $client) {}

    /** Le fournisseur IA est-il branché ? */
    public function estConfigure(): bool
    {
        return $this->client->estConfigure();
    }

    /**
     * Génère le brouillon (sujet + corps, en anglais) et le renvoie tel quel.
     *
     * @throws ExtractionException si l'appel IA échoue.
     */
    public function rediger(Cas $cas): string
    {
        return trim($this->client->completion([
            ['role' => 'system', 'content' => $this->systeme()],
            ['role' => 'user', 'content' => $this->donneesCas($cas)],
        ], ['max_tokens' => 900]));
    }

    private function systeme(): string
    {
        $issueTypes = (array) config('sav.lift.issue_types', []);
        $ligneIssue = $issueTypes === []
            ? "Lift's Zendesk Issue Types are not provided: describe the problem plainly."
            : 'Map the problem to one of Lift\'s Zendesk Issue Types when it clearly fits: '.implode(', ', $issueTypes).'.';

        $email = (string) config('sav.lift.email', 'help@liftfoils.com');

        return <<<TXT
            You write a concise, professional support e-mail IN ENGLISH to Lift Foils
            ({$email}) on behalf of a French Lift Foils dealer's after-sales service
            (SAV). One case per e-mail.

            Output format — plain text only, no Markdown:
              Subject: [<dealer case reference>] <short, specific subject line>
              <blank line>
              <body: a short greeting, the problem, the key facts, and a clear ask>

            Rules:
            - The subject MUST start with the dealer case reference in square
              brackets, e.g. "Subject: [SAV-2026-0001] Battery not charging".
              Lift's replies quote the subject: that bracket is how we route their
              answer back to the right case.
            - Recopy the serial number (MHS), the Sales Order and the purchase date
              VERBATIM, or omit them entirely. NEVER invent or guess a serial, an
              order number or a date.
            - State only what the case data supports. Do not fabricate diagnostics
              or warranty conclusions — the warranty call is Lift's to make.
            - Use Lift vocabulary: HC = handcontroller (remote), eBox/ESC, mast,
              board, foil, battery, charger; models look like "MY H3", "Lift4", "Lift5".
            - {$ligneIssue}
            - Keep it factual and brief. Ask Lift how to proceed (RMA, parts, etc.).
            TXT;
    }

    private function donneesCas(Cas $cas): string
    {
        $champs = array_filter([
            'Dealer case reference' => $cas->reference,
            'Customer' => $cas->client_nom,
            'Product category' => $cas->produit,
            'Model' => $cas->modele,
            'Serial number (MHS)' => $cas->numero_serie,
            'Sales Order' => $cas->sales_order,
            'Purchase date (verbatim)' => $cas->date_achat,
            'Context (FR)' => $cas->contexte,
            'Urgent' => $cas->urgent ? 'yes' : null,
        ], fn ($v) => filled($v));

        $lignes = [];
        foreach ($champs as $cle => $valeur) {
            $lignes[] = "{$cle}: {$valeur}";
        }

        $lignes[] = '';
        $lignes[] = 'Original request (verbatim, may be in French):';
        $lignes[] = Str::limit((string) $cas->description, 4000, '');

        return implode("\n", $lignes);
    }
}

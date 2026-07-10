<?php

namespace Tests\Support;

use Webklex\PHPIMAP\Message as MailImap;

/**
 * Fabrique des emails bruts (RFC 5322) pour les tests.
 *
 * Les tests d'ingestion partent d'un .eml et le font parser par la vraie
 * librairie IMAP, plutôt que de simuler un objet Message. Ça coûte trois
 * lignes de plus et ça couvre ce qui casse pour de bon : le décodage des
 * en-têtes, les chevrons des Message-ID, le base64 des pièces jointes.
 */
final class Eml
{
    private const FRONTIERE = 'FRONTIERE-DE-TEST';

    /** @var array<string, string> */
    private array $entetes = [];

    private string $texte = '';

    private ?string $html = null;

    /** @var list<array{nom: string, contenu: string, mime: string}> */
    private array $piecesJointes = [];

    public static function make(): self
    {
        $eml = new self;

        return $eml
            ->entete('From', 'Camille Dupont <camille@example.test>')
            ->entete('To', 'sav@liftfoils.fr')
            ->entete('Subject', 'Batterie qui ne charge plus')
            ->entete('Date', 'Fri, 10 Jul 2026 10:00:00 +0200')
            ->entete('Message-ID', '<demande-1@example.test>')
            ->texte("Bonjour,\n\nMa batterie ne charge plus depuis hier.\n\nCamille");
    }

    public function entete(string $nom, ?string $valeur): self
    {
        if ($valeur === null) {
            unset($this->entetes[$nom]);

            return $this;
        }

        $this->entetes[$nom] = $valeur;

        return $this;
    }

    public function sansEntete(string $nom): self
    {
        return $this->entete($nom, null);
    }

    public function texte(string $texte): self
    {
        $this->texte = $texte;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function pieceJointe(string $nom, string $contenu, string $mime = 'application/octet-stream'): self
    {
        $this->piecesJointes[] = ['nom' => $nom, 'contenu' => $contenu, 'mime' => $mime];

        return $this;
    }

    /** Parse le message avec la vraie librairie, comme le ferait la relève. */
    public function parser(): MailImap
    {
        return MailImap::fromString($this->brut());
    }

    public function brut(): string
    {
        $entetes = $this->entetes;
        $entetes['MIME-Version'] = '1.0';

        $multipart = $this->piecesJointes !== [] || $this->html !== null;

        $entetes['Content-Type'] = $multipart
            ? 'multipart/mixed; boundary="'.self::FRONTIERE.'"'
            : 'text/plain; charset=UTF-8';

        $lignes = [];

        foreach ($entetes as $nom => $valeur) {
            $lignes[] = "{$nom}: {$valeur}";
        }

        $lignes[] = '';

        if (! $multipart) {
            $lignes[] = $this->texte;

            return implode("\r\n", $lignes);
        }

        $lignes[] = '--'.self::FRONTIERE;
        $lignes[] = 'Content-Type: text/plain; charset=UTF-8';
        $lignes[] = 'Content-Transfer-Encoding: 8bit';
        $lignes[] = '';
        $lignes[] = $this->texte;

        if ($this->html !== null) {
            $lignes[] = '--'.self::FRONTIERE;
            $lignes[] = 'Content-Type: text/html; charset=UTF-8';
            $lignes[] = 'Content-Transfer-Encoding: 8bit';
            $lignes[] = '';
            $lignes[] = $this->html;
        }

        foreach ($this->piecesJointes as $piece) {
            $lignes[] = '--'.self::FRONTIERE;
            $lignes[] = "Content-Type: {$piece['mime']}; name=\"{$piece['nom']}\"";
            $lignes[] = "Content-Disposition: attachment; filename=\"{$piece['nom']}\"";
            $lignes[] = 'Content-Transfer-Encoding: base64';
            $lignes[] = '';
            $lignes[] = chunk_split(base64_encode($piece['contenu']), 76, "\r\n");
        }

        $lignes[] = '--'.self::FRONTIERE.'--';

        return implode("\r\n", $lignes);
    }
}

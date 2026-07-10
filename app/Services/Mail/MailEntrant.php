<?php

namespace App\Services\Mail;

use App\Support\EnteteMime;
use App\Support\MessageId;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message as MailImap;

/**
 * Un email relevé, réduit à ce dont l'outil a besoin.
 *
 * Tout ce qui sait lire un message Webklex est ici ; l'ingesteur, lui, ne fait
 * que décider. C'est aussi ce qui rend l'ingestion testable : un test fabrique
 * un `MailEntrant` à partir d'un fichier .eml, sans serveur IMAP.
 *
 * @phpstan-type PieceJointeImap Attachment
 */
final readonly class MailEntrant
{
    /**
     * @param  list<string>  $references
     * @param  list<Attachment>  $piecesJointes
     */
    public function __construct(
        public string $messageId,
        public ?string $inReplyTo,
        public array $references,
        public string $fromEmail,
        public ?string $fromName,
        public ?string $toEmail,
        public ?string $subject,
        public ?string $bodyText,
        public ?string $bodyHtml,
        public CarbonImmutable $receivedAt,
        public ?string $autoSubmitted,
        public ?string $precedence,
        public array $piecesJointes,
    ) {}

    public static function depuis(MailImap $mail): self
    {
        $de = self::adresse($mail, 'from');

        return new self(
            messageId: self::identifiant($mail),
            inReplyTo: MessageId::normaliser(self::entete($mail, 'in_reply_to')),
            references: MessageId::liste(self::enteteListe($mail, 'references')),
            fromEmail: Str::lower($de?->mail ?? ''),
            // Sujet et nom d'expéditeur passent par EnteteMime : sans
            // l'extension PHP `imap`, la librairie les laisse encodés.
            fromName: EnteteMime::decoder($de?->personal),
            toEmail: Str::lower(self::adresse($mail, 'to')?->mail ?? '') ?: null,
            subject: EnteteMime::decoder(self::entete($mail, 'subject')),
            bodyText: $mail->hasTextBody() ? $mail->getTextBody() : null,
            bodyHtml: $mail->hasHTMLBody() ? $mail->getHTMLBody() : null,
            receivedAt: self::date($mail),
            autoSubmitted: self::nettoyer(self::entete($mail, 'auto_submitted')),
            precedence: self::nettoyer(self::entete($mail, 'precedence')),
            piecesJointes: array_values($mail->getAttachments()->all()),
        );
    }

    /** Le corps texte, ou à défaut le HTML dépouillé de ses balises. */
    public function texteLisible(): string
    {
        if (filled($this->bodyText)) {
            return trim($this->bodyText);
        }

        // html_entity_decode : sinon les « &eacute; » d'un mail Outlook
        // atterrissent tels quels dans la description du dossier.
        return trim(html_entity_decode(strip_tags((string) $this->bodyHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Les identifiants du fil, du plus probable au moins probable. Une réponse
     * pointe son parent direct dans `In-Reply-To` ; `References` porte la
     * chaîne complète des ancêtres, utile quand un client mail escamote le
     * premier.
     *
     * @return list<string>
     */
    public function ancetres(): array
    {
        return array_values(array_unique(array_filter(
            [$this->inReplyTo, ...array_reverse($this->references)],
        )));
    }

    /**
     * Un Message-ID est obligatoire d'après la RFC, mais des passerelles et de
     * vieux clients en émettent sans. Plutôt que de refuser le mail, on en
     * dérive un, stable : deux relèves du même message retombent sur la même
     * empreinte, donc la déduplication tient toujours.
     */
    private static function identifiant(MailImap $mail): string
    {
        if ($id = MessageId::normaliser(self::entete($mail, 'message_id'))) {
            return $id;
        }

        // L'empreinte porte sur les en-têtes bruts et le corps : le même
        // message, relevé deux fois, retombe sur le même identifiant.
        $empreinte = sha1(implode("\n", [
            $mail->getHeader()?->raw ?? '',
            $mail->hasTextBody() ? $mail->getTextBody() : '',
        ]));

        return "sans-message-id-{$empreinte}@sav.local";
    }

    private static function adresse(MailImap $mail, string $entete): ?Address
    {
        $valeur = $mail->getHeader()?->get($entete)->first();

        return $valeur instanceof Address && $valeur->mail !== '' ? $valeur : null;
    }

    private static function entete(MailImap $mail, string $nom): ?string
    {
        $entete = $mail->getHeader();

        if ($entete === null || ! $entete->has($nom)) {
            return null;
        }

        $valeur = $entete->get($nom)->first();

        return is_string($valeur) ? $valeur : null;
    }

    /** @return list<string> */
    private static function enteteListe(MailImap $mail, string $nom): array
    {
        $entete = $mail->getHeader();

        if ($entete === null || ! $entete->has($nom)) {
            return [];
        }

        return array_values(array_filter($entete->get($nom)->all(), is_string(...)));
    }

    /**
     * La date d'envoi, ramenée au fuseau de l'application.
     *
     * Le `->utc()` n'est pas cosmétique : Eloquent formate un Carbon tel quel,
     * sans conversion. Sans lui, « 10:00 +02:00 » et « 10:00 -05:00 » seraient
     * enregistrés à la même heure, et la timeline du dossier mélangerait
     * l'ordre des messages d'un client en voyage.
     */
    private static function date(MailImap $mail): CarbonImmutable
    {
        $entete = $mail->getHeader();

        if ($entete === null || ! $entete->has('date')) {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::instance($entete->get('date')->toDate())
                ->setTimezone(config('app.timezone', 'UTC'));
        } catch (Throwable) {
            // Une date illisible ne doit pas coûter un dossier.
            return CarbonImmutable::now();
        }
    }

    private static function nettoyer(?string $valeur): ?string
    {
        $valeur = trim((string) $valeur);

        return $valeur === '' ? null : $valeur;
    }
}

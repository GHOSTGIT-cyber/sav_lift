<?php

namespace App\Services\Mail;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Mail\AccuseReceptionMail;
use App\Models\Cas;
use App\Models\Message;
use App\Models\PieceJointe;
use App\Services\Ia\ServiceExtraction;
use App\Support\MessageId;
use App\Support\NomFichier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Attachment;

/**
 * Transforme un mail relevé en dossier — ou le rattache à celui qui existe.
 *
 * Deux invariants gouvernent cette classe :
 *
 *  1. **Idempotence.** Un mail déjà vu (même Message-ID) ne produit rien. La
 *     relève peut donc repasser sur la même fenêtre, être relancée après un
 *     crash, ou tourner deux fois de suite, sans créer de doublon.
 *  2. **Pas de boucle d'auto-réponses.** Le seul mail que l'outil envoie sans
 *     validation humaine est l'accusé de réception ; il ne part qu'à l'ouverture
 *     d'un dossier, jamais vers un robot, jamais vers la boîte SAV elle-même.
 */
class IngesteurMail
{
    public function __construct(
        private readonly ServiceExtraction $extraction,
        private readonly Expediteur $expediteur,
    ) {}

    /**
     * Sujets d'auto-réponse, en français comme en anglais.
     *
     * Le cahier des charges disait « sujet commençant par Auto ». On est plus
     * précis à dessein : dans un SAV d'eFoils, « Autonomie batterie très
     * faible » est un sujet de dossier parfaitement légitime, qu'une simple
     * comparaison de préfixe jetterait à la poubelle. On exige donc le mot
     * qui suit (reply / response / automatique…).
     */
    private const SUJETS_AUTOMATIQUES = '/^\s*(?:(?:re|fw|fwd|tr)\s*:\s*)*(?:'
        .'auto(?:matic)?[\s\-_]*(?:reply|response|answer)'
        .'|auto\s*:'
        .'|out\s+of\s+(?:the\s+)?office'
        .'|r[ée]ponse\s+automatique'
        .'|message\s+d[\'’]absence'
        .'|absence\s+du\s+bureau'
        .')/iu';

    /** Comparé en sous-chaîne sur l'adresse complète de l'expéditeur. */
    private const EXPEDITEURS_ROBOTS = ['noreply', 'no-reply', 'mailer-daemon', 'postmaster'];

    /** Valeurs de l'en-tête `Precedence` qui signalent un envoi non sollicité. */
    private const PRECEDENCES_AUTOMATIQUES = ['bulk', 'auto_reply', 'list', 'junk'];

    public function ingerer(MailEntrant $mail): ResultatIngestion
    {
        if (Message::where('message_id', $mail->messageId)->exists()) {
            return ResultatIngestion::Doublon;
        }

        if ($raison = $this->raisonDIgnorer($mail)) {
            Log::info('Mail ignoré par la relève SAV', [
                'message_id' => $mail->messageId,
                'from' => $mail->fromEmail,
                'raison' => $raison,
            ]);

            return ResultatIngestion::Ignore;
        }

        if ($cas = $this->casDuFil($mail)) {
            $this->enregistrer($mail, $cas);

            Log::info('Mail rattaché à un dossier existant', [
                'cas' => $cas->reference,
                'message_id' => $mail->messageId,
            ]);

            // Une réponse peut apporter l'info qui manquait (le MHS, la facture) :
            // on ré-extrait pour compléter le dossier (fusion sans écrasement).
            $this->extraction->pourCas($cas->refresh());

            return ResultatIngestion::Rattache;
        }

        $cas = $this->enregistrer($mail, null);

        Log::info('Dossier SAV ouvert par mail', [
            'cas' => $cas->reference,
            'from' => $mail->fromEmail,
        ]);

        // Extraction hors de la transaction d'écriture (appel réseau lent) et
        // non bloquante : un échec marque le dossier sans empêcher l'accusé.
        $this->extraction->pourCas($cas);

        if (! $this->estPartenaire($mail->fromEmail)) {
            $this->accuserReception($cas, $mail);
        }

        return ResultatIngestion::NouveauDossier;
    }

    /**
     * La raison de ne pas traiter ce mail, ou null s'il faut le traiter.
     *
     * Les mails de Lift et de son Zendesk passent ces gardes : ils alimentent
     * bien un dossier. C'est plus loin, au moment d'accuser réception, qu'on
     * les met de côté (voir estPartenaire).
     */
    private function raisonDIgnorer(MailEntrant $mail): ?string
    {
        if ($mail->fromEmail === '') {
            return 'expéditeur illisible';
        }

        if ($mail->fromEmail === Str::lower((string) config('sav.mailbox'))) {
            return 'expéditeur = la boîte SAV elle-même';
        }

        foreach (self::EXPEDITEURS_ROBOTS as $motif) {
            if (str_contains($mail->fromEmail, $motif)) {
                return "expéditeur automatique ({$motif})";
            }
        }

        // RFC 3834 : seule la valeur « no » désigne un message écrit par un
        // humain. Le champ peut porter des paramètres (« auto-replied; owner=… »).
        if ($mail->autoSubmitted !== null && Str::lower(trim(Str::before($mail->autoSubmitted, ';'))) !== 'no') {
            return "en-tête Auto-Submitted: {$mail->autoSubmitted}";
        }

        $precedence = Str::lower(str_replace('-', '_', trim((string) $mail->precedence)));

        if (in_array($precedence, self::PRECEDENCES_AUTOMATIQUES, true)) {
            return "en-tête Precedence: {$mail->precedence}";
        }

        if ($mail->subject !== null && preg_match(self::SUJETS_AUTOMATIQUES, $mail->subject) === 1) {
            return "sujet d'auto-réponse : « {$mail->subject} »";
        }

        return null;
    }

    /** Le dossier auquel ce mail répond, s'il répond à un mail que l'on connaît. */
    private function casDuFil(MailEntrant $mail): ?Cas
    {
        $ancetres = $mail->ancetres();

        if ($ancetres === []) {
            return null;
        }

        return Message::whereIn('message_id', $ancetres)
            ->latest('received_at')
            ->first()
            ?->cas;
    }

    /**
     * Écrit le mail, ses pièces jointes, et le dossier s'il est nouveau.
     *
     * Les fichiers sont écrits *dans* la transaction : si l'insertion échoue,
     * on les efface à la main — un ROLLBACK ne défait pas le disque. L'inverse
     * (écrire après le COMMIT) laisserait des lignes pointant vers le vide.
     */
    private function enregistrer(MailEntrant $mail, ?Cas $cas): Cas
    {
        /** @var list<string> $cheminsEcrits */
        $cheminsEcrits = [];

        try {
            return DB::transaction(function () use ($mail, $cas, &$cheminsEcrits): Cas {
                $cas ??= $this->ouvrirDossier($mail);

                $message = $cas->messages()->create([
                    'message_id' => $mail->messageId,
                    'in_reply_to' => $mail->inReplyTo,
                    'email_references' => $mail->references === [] ? null : implode(' ', $mail->references),
                    'direction' => DirectionMessage::Inbound,
                    'from_email' => $mail->fromEmail,
                    'from_name' => $mail->fromName,
                    'to_email' => $mail->toEmail,
                    'subject' => $mail->subject,
                    'body_text' => $mail->bodyText,
                    'body_html' => $mail->bodyHtml,
                    'received_at' => $mail->receivedAt,
                ]);

                $this->enregistrerPiecesJointes($mail, $cas, $message, $cheminsEcrits);

                return $cas;
            });
        } catch (Throwable $e) {
            Storage::disk('local')->delete($cheminsEcrits);

            throw $e;
        }
    }

    private function ouvrirDossier(MailEntrant $mail): Cas
    {
        return Cas::create([
            'reference' => Cas::prochaineReference(),
            'client_nom' => $mail->fromName,
            'client_email' => $mail->fromEmail,
            'description' => $this->description($mail),
            'statut' => StatutCas::Nouveau,
            'source' => 'email',
        ]);
    }

    private function description(MailEntrant $mail): string
    {
        return trim(implode("\n\n", array_filter([
            $mail->subject,
            Str::limit($mail->texteLisible(), 4000),
        ])));
    }

    /**
     * @param  list<string>  $cheminsEcrits  Rempli au fil de l'eau, pour pouvoir
     *                                       nettoyer le disque si la transaction échoue.
     */
    private function enregistrerPiecesJointes(MailEntrant $mail, Cas $cas, Message $message, array &$cheminsEcrits): void
    {
        $maxOctets = max(1, (int) config('sav.max_attachment_mb', 40)) * 1024 * 1024;
        $disque = Storage::disk('local');

        foreach ($mail->piecesJointes as $piece) {
            $taille = $this->taille($piece);

            if ($taille !== null && $taille > $maxOctets) {
                Log::warning('Pièce jointe ignorée : trop volumineuse', [
                    'cas' => $cas->reference,
                    'filename' => $piece->getFilename(),
                    'octets' => $taille,
                ]);

                continue;
            }

            $contenu = (string) $piece->getContent();

            // Le préfixe aléatoire évite qu'une deuxième « IMG_0001.jpg » du
            // même dossier n'écrase la première ; le nom d'origine reste en base.
            $nom = NomFichier::securiser($piece->getFilename() ?: $piece->getName());
            $chemin = sprintf('sav/%d/%s-%s', $cas->id, Str::random(8), $nom);

            $disque->put($chemin, $contenu);
            $cheminsEcrits[] = $chemin;

            PieceJointe::create([
                'cas_id' => $cas->id,
                'message_id' => $message->id,
                'path' => $chemin,
                'filename' => $piece->getFilename() ?: $nom,
                'mime' => $this->mime($piece),
                'taille' => $taille ?? strlen($contenu),
            ]);
        }
    }

    /**
     * La taille annoncée par la structure MIME.
     *
     * Attention : Webklex décode déjà le contenu en mémoire au moment où il
     * construit l'Attachment. Ce garde-fou évite donc d'écrire un fichier
     * énorme sur le disque, mais pas de le charger. La vraie protection contre
     * l'OOM est en amont, dans la commande, qui refuse de parser le corps d'un
     * message dont la taille IMAP dépasse `sav.max_message_mb`.
     */
    private function taille(Attachment $piece): ?int
    {
        $taille = $piece->getSize();

        return is_numeric($taille) ? (int) $taille : null;
    }

    private function mime(Attachment $piece): ?string
    {
        try {
            // Deviné sur le contenu réel : un client mail qui annonce
            // « application/octet-stream » ne doit pas nous priver de l'aperçu
            // d'une photo d'étiquette MHS.
            return $piece->getMimeType() ?: $piece->getContentType();
        } catch (Throwable) {
            return $piece->getContentType();
        }
    }

    /** Lift et son Zendesk nourrissent les dossiers, mais ne reçoivent pas d'accusé. */
    private function estPartenaire(string $email): bool
    {
        foreach ((array) config('sav.expediteurs_partenaires', []) as $domaine) {
            if (str_contains($email, Str::lower((string) $domaine))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Envoi en ligne, hors transaction : un mail parti ne se rembobine pas.
     *
     * Passe par l'Expediteur, seul juge de si le mail part réellement (garde-fou
     * SAV_ENVOI_ACTIF). Tant que l'envoi est désactivé, l'accusé est simulé et
     * on n'enregistre AUCUN message sortant : le dossier reste « vierge d'accusé »,
     * de sorte qu'au go-live le client reçoive bien son premier accusé.
     *
     * Si le SMTP est en panne, le dossier reste — c'est l'accusé qui saute. On
     * le journalise : mieux vaut un client sans accusé qu'un dossier perdu.
     */
    private function accuserReception(Cas $cas, MailEntrant $mail): void
    {
        $messageId = MessageId::genererPourSortant($this->domaineExpediteur());
        $accuse = new AccuseReceptionMail($cas, $messageId, $mail->messageId);

        try {
            $parti = $this->expediteur->envoyer((string) $cas->client_email, $accuse);
        } catch (Throwable $e) {
            Log::error("Accusé de réception non envoyé pour {$cas->reference}", [
                'destinataire' => $cas->client_email,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        // Envoi simulé (SAV_ENVOI_ACTIF=false) : rien n'est parti, rien à tracer.
        if (! $parti) {
            return;
        }

        $cas->messages()->create([
            'message_id' => $messageId,
            'in_reply_to' => $mail->messageId,
            'email_references' => $mail->messageId,
            'direction' => DirectionMessage::Outbound,
            'from_email' => (string) config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'to_email' => $cas->client_email,
            'subject' => $accuse->envelope()->subject,
            'body_text' => "Accusé de réception automatique du dossier {$cas->reference}.",
            'body_html' => rescue(fn () => $accuse->render(), report: false),
            'received_at' => now(),
        ]);
    }

    private function domaineExpediteur(): string
    {
        $adresse = (string) (config('mail.from.address') ?: config('sav.mailbox'));

        return str_contains($adresse, '@') ? Str::after($adresse, '@') : 'liftfoils.fr';
    }
}

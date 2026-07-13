<?php

namespace App\Services\Mail;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Models\Cas;
use App\Models\Message;
use App\Models\PieceJointe;
use App\Services\Ia\ServiceExtraction;
use App\Support\NomFichier;
use App\Support\Partenaires;
use App\Support\SujetMail;
use App\Support\TicketLift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Attachment;

/**
 * Transforme un mail relevé en dossier — ou le rattache à celui qui existe.
 *
 * Trois invariants gouvernent cette classe :
 *
 *  1. **Idempotence.** Un mail déjà vu (même Message-ID) ne produit rien. La
 *     relève peut donc repasser sur la même fenêtre, être relancée après un
 *     crash, ou tourner deux fois de suite, sans créer de doublon.
 *  2. **Pas de boucle d'auto-réponses.** Le seul mail que l'outil envoie sans
 *     validation humaine est l'accusé de réception ; il ne part qu'à l'ouverture
 *     d'un dossier, jamais vers un robot, jamais vers la boîte SAV elle-même.
 *  3. **Les mails de Lift font avancer le dossier.** Leur Zendesk nous écrit :
 *     son accusé porte le n° de ticket, ses réponses appellent une action. C'est
 *     là toute la « synchro » avec Lift — pas de connexion à leur API (fermée,
 *     401 confirmé au Bloc 3-D), juste leurs mails, lus.
 */
class IngesteurMail
{
    public function __construct(
        private readonly ServiceExtraction $extraction,
        private readonly NotificateurClient $notificateur,
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

        $partenaire = $this->estPartenaire($mail->fromEmail);

        if ($cas = $this->casCorrespondant($mail)) {
            $this->enregistrer($mail, $cas);
            $cas->refresh();

            Log::info('Mail rattaché à un dossier existant', [
                'cas' => $cas->reference,
                'message_id' => $mail->messageId,
            ]);

            if ($partenaire) {
                // Lift qui écrit, c'est le dossier qui avance : n° de ticket capté,
                // statut mis à jour. On ne ré-extrait pas — leurs mails parlent de
                // RMA et de logistique, pas du matériel du client.
                $this->suivreLift($cas, $mail);

                return ResultatIngestion::Rattache;
            }

            // Une réponse du client peut apporter l'info qui manquait (le MHS, la
            // facture) : on ré-extrait pour compléter le dossier (sans écrasement).
            $this->extraction->pourCas($cas);

            return ResultatIngestion::Rattache;
        }

        $cas = $this->enregistrer($mail, null);

        Log::info('Dossier SAV ouvert par mail', [
            'cas' => $cas->reference,
            'from' => $mail->fromEmail,
        ]);

        // Un mail de Lift qu'on n'a pas su rattacher ouvre quand même un dossier
        // (mieux vaut un dossier à recoller qu'un mail perdu), mais il ne reçoit ni
        // extraction ni accusé : ce n'est pas une demande client.
        if ($partenaire) {
            $this->suivreLift($cas, $mail);

            return ResultatIngestion::NouveauDossier;
        }

        // Extraction hors de la transaction d'écriture (appel réseau lent) et non
        // bloquante : un échec marque le dossier sans empêcher l'accusé.
        //
        // L'ordre compte : l'accusé part APRÈS l'extraction, car c'est elle qui
        // détermine ce qu'il reste à réclamer au client. Un accusé envoyé avant
        // redemanderait des pièces déjà fournies dans le mail qu'on vient de lire.
        $this->extraction->pourCas($cas);

        $this->notificateur->accuserReception($cas->refresh(), $mail->messageId);

        return ResultatIngestion::NouveauDossier;
    }

    /**
     * Ce qu'un mail de Lift nous apprend : le n° de leur ticket, et le fait
     * qu'ils ont répondu. C'est là toute la « synchro » : le dossier avance
     * parce que Lift a écrit, pas parce qu'on a interrogé une API.
     *
     * **Le n° de ticket est le seul signal qui fait avancer le statut.** Il est
     * la preuve que le dossier est bel et bien ouvert chez eux — y compris si
     * Nico leur a écrit à la main, sans passer par le bouton. Tout autre mail de
     * Lift ne fait que dater `reponse_lift_le` : « ils ont répondu, à traiter ».
     * Sans cette règle, n'importe quelle notification Zendesk égarée pousserait
     * un dossier « Chez Lift » alors qu'il n'y est jamais allé.
     */
    private function suivreLift(Cas $cas, MailEntrant $mail): void
    {
        $ticket = TicketLift::numero($mail->subject, $mail->bodyText);
        $ouverture = $ticket !== null && blank($cas->ticket_lift);

        $maj = [];

        if ($ouverture) {
            $maj['ticket_lift'] = $ticket;
            $maj['statut'] = StatutCas::AttenteLift;
        } else {
            $maj['reponse_lift_le'] = $mail->receivedAt;

            // Leur accusé nous était déjà parvenu : ce mail-ci est une réponse.
            if ($cas->statut === StatutCas::EnvoyeLift) {
                $maj['statut'] = StatutCas::AttenteLift;
            }
        }

        $cas->forceFill($maj)->save();

        Log::info('Dossier mis à jour par un mail de Lift', [
            'cas' => $cas->reference,
            'ticket_lift' => $cas->ticket_lift,
            'statut' => $cas->statut->value,
        ]);
    }

    /**
     * La raison de ne pas traiter ce mail, ou null s'il faut le traiter.
     *
     * Les mails de Lift et de son Zendesk traversent ces gardes **sans être
     * inspectés** : leur accusé est précisément une auto-réponse (il peut porter
     * `Auto-Submitted`, partir d'un `noreply@`, s'intituler « Automatic
     * reply »…), et c'est lui qui contient le n° de ticket. Le jeter, c'est
     * perdre le seul canal de synchro qui nous reste depuis que leur API est
     * fermée. Aucun risque de boucle : on ne répond jamais à un partenaire.
     */
    private function raisonDIgnorer(MailEntrant $mail): ?string
    {
        if ($mail->fromEmail === '') {
            return 'expéditeur illisible';
        }

        if ($mail->fromEmail === Str::lower((string) config('sav.mailbox'))) {
            return 'expéditeur = la boîte SAV elle-même';
        }

        if ($this->estPartenaire($mail->fromEmail)) {
            return null;
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

    /**
     * Le dossier auquel ce mail se rattache — par ordre de fiabilité décroissante.
     *
     * Le threading (1) suffit pour le client, qui répond à nos mails. Il suffit
     * même pour Lift, maintenant que le dossier part de l'outil et porte donc
     * notre Message-ID. Mais Zendesk réécrit parfois les en-têtes, et le mail
     * peut aussi avoir été envoyé à la main depuis la boîte de Nico : d'où les
     * repères (2), (3) et (4), qui ne s'appliquent qu'aux mails de Lift. Sur un
     * mail client, un « #12345 » ne voudrait rien dire.
     */
    private function casCorrespondant(MailEntrant $mail): ?Cas
    {
        if ($cas = $this->casDuFil($mail)) {
            return $cas;
        }

        if (! $this->estPartenaire($mail->fromEmail)) {
            return null;
        }

        // (2) Le n° de ticket : Lift le répète dans chacun de ses mails.
        if ($ticket = TicketLift::numero($mail->subject, $mail->bodyText)) {
            if ($cas = Cas::where('ticket_lift', $ticket)->first()) {
                return $cas;
            }
        }

        // (3) Notre référence, que le brouillon met entre crochets dans l'objet
        // et que Zendesk recopie dans ses réponses.
        if ($reference = SujetMail::reference($mail->subject, $mail->bodyText)) {
            if ($cas = Cas::where('reference', $reference)->first()) {
                return $cas;
            }
        }

        // (4) Le noyau de l'objet, dépouillé des « Re: », des crochets et du
        // n° de ticket. Dernier repli, borné aux dossiers encore vivants.
        return $this->casParSujet($mail->subject);
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
     * Le dossier dont un message porte le même objet, au bruit près.
     *
     * Comparé en PHP et non en SQL : la normalisation (préfixes, crochets, n° de
     * ticket) n'a pas d'équivalent portable entre SQLite et PostgreSQL. À
     * quelques dizaines de dossiers par mois, la centaine de sujets qu'on relit
     * ici ne coûte rien.
     */
    private function casParSujet(?string $sujet): ?Cas
    {
        $noyau = SujetMail::noyau($sujet);

        if ($noyau === '') {
            return null;
        }

        $messages = Message::query()
            ->whereHas('cas', fn (Builder $query) => $query->where('statut', '!=', StatutCas::Clos->value))
            ->whereNotNull('subject')
            ->latest('received_at')
            ->limit(200)
            ->get(['id', 'cas_id', 'subject']);

        foreach ($messages as $message) {
            if (SujetMail::noyau($message->subject) === $noyau) {
                return $message->cas;
            }
        }

        return null;
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

    private function estPartenaire(string $email): bool
    {
        return Partenaires::est($email);
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Mail\IngesteurMail;
use App\Services\Mail\MailEntrant;
use App\Services\Mail\ResultatIngestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message as MailImap;

/**
 * Relève la boîte SAV. Lancée toutes les deux minutes par le planificateur
 * (voir routes/console.php), et sans risque à relancer à la main : la
 * déduplication par Message-ID rend chaque passage idempotent.
 */
class FetchMail extends Command
{
    protected $signature = 'sav:fetch-mail
                            {--jours= : Fenêtre de relève, en jours (défaut : sav.imap.jours)}';

    protected $description = 'Relève la boîte SAV en IMAP : ouvre les dossiers, stocke les pièces jointes, accuse réception.';

    public function handle(ClientManager $clients, IngesteurMail $ingesteur): int
    {
        $jours = max(1, (int) ($this->option('jours') ?: config('sav.imap.jours', 14)));
        $dossier = (string) config('sav.imap.dossier', 'INBOX');

        try {
            $client = $clients->account((string) config('imap.default'));
            $client->connect();

            $boite = $client->getFolder($dossier);
        } catch (Throwable $e) {
            $this->components->error("Connexion IMAP impossible : {$e->getMessage()}");
            Log::error('Connexion IMAP impossible', ['exception' => $e->getMessage()]);

            return self::FAILURE;
        }

        if ($boite === null) {
            $this->components->error("Dossier IMAP « {$dossier} » introuvable.");

            return self::FAILURE;
        }

        $this->components->info("Relève de « {$dossier} » sur les {$jours} derniers jours.");

        // On ne filtre pas sur le flag \Seen : des humains lisent la même boîte
        // et marquent les mails comme lus. On repasse donc sur la fenêtre
        // entière, et la déduplication par Message-ID fait le tri.
        //
        // setFetchBody(false) : seuls les en-têtes descendent ici. Le corps
        // n'est chargé qu'après contrôle de la taille (voir estTropGros).
        $messages = $boite->query()
            ->since(now()->subDays($jours))
            ->setFetchBody(false)
            ->get();

        $compteurs = [
            'nouveaux dossiers' => 0,
            'messages rattachés' => 0,
            'déjà connus' => 0,
            'ignorés' => 0,
            'trop volumineux' => 0,
            'en erreur' => 0,
        ];

        foreach ($messages as $mail) {
            try {
                if ($this->estTropGros($mail)) {
                    $compteurs['trop volumineux']++;

                    continue;
                }

                // Le corps n'a pas été téléchargé avec les en-têtes : on le
                // récupère maintenant, une fois la taille jugée raisonnable.
                $mail->parseBody();

                $resultat = $ingesteur->ingerer(MailEntrant::depuis($mail));

                $compteurs[match ($resultat) {
                    ResultatIngestion::NouveauDossier => 'nouveaux dossiers',
                    ResultatIngestion::Rattache => 'messages rattachés',
                    ResultatIngestion::Doublon => 'déjà connus',
                    ResultatIngestion::Ignore => 'ignorés',
                }]++;

                // Confort pour les humains qui ouvrent la boîte ; la source de
                // vérité de la déduplication reste la table `messages`.
                $mail->setFlag('Seen');
            } catch (Throwable $e) {
                // Un mail qui plante ne doit pas emporter la relève entière.
                $compteurs['en erreur']++;

                Log::error('Échec du traitement d\'un mail relevé', [
                    'uid' => $mail->uid ?? null,
                    'subject' => $mail->getHeader()?->get('subject')->toString(),
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        $client->disconnect();

        $this->afficherCompteRendu($compteurs);

        return self::SUCCESS;
    }

    /**
     * Refuse de parser le corps d'un message démesuré.
     *
     * C'est ici — et pas au niveau de la pièce jointe — que se joue la
     * protection contre l'OOM : `parseBody()` décode tout le message en
     * mémoire d'un coup. Un dépassement de `memory_limit` est une erreur
     * fatale, pas une exception : elle tuerait le processus, et la vidéo de
     * 300 Mo d'un client bloquerait indéfiniment tous les mails suivants.
     *
     * Le message reste dans la boîte, non lu : un humain le verra.
     *
     * Si le serveur refuse d'annoncer la taille, on laisse passer : mieux vaut
     * une relève sans garde-fou qu'une relève qui s'arrête sur chaque message.
     */
    private function estTropGros(MailImap $mail): bool
    {
        $maxOctets = max(1, (int) config('sav.max_message_mb', 60)) * 1024 * 1024;

        $taille = rescue(fn (): mixed => $mail->get('size'), report: false);

        if (! is_numeric($taille)) {
            Log::debug('Taille du message inconnue : garde-fou mémoire inopérant.', [
                'uid' => $mail->uid ?? null,
            ]);

            return false;
        }

        $taille = (int) $taille;

        if ($taille <= $maxOctets) {
            return false;
        }

        $megaoctets = round($taille / 1024 / 1024, 1);

        $this->components->warn("Message ignoré : {$megaoctets} Mo (limite : ".config('sav.max_message_mb').' Mo).');

        Log::warning('Message trop volumineux, laissé dans la boîte', [
            'uid' => $mail->uid ?? null,
            'subject' => $mail->getHeader()?->get('subject')->toString(),
            'octets' => $taille,
        ]);

        return true;
    }

    /** @param  array<string, int>  $compteurs */
    private function afficherCompteRendu(array $compteurs): void
    {
        foreach ($compteurs as $libelle => $nombre) {
            if ($nombre > 0) {
                $this->components->twoColumnDetail($libelle, (string) $nombre);
            }
        }

        if (array_sum($compteurs) === 0) {
            $this->components->info('Aucun message dans la fenêtre de relève.');
        }
    }
}

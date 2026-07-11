<?php

namespace Tests\Feature;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Mail\AccuseReceptionMail;
use App\Models\Cas;
use App\Models\Message;
use App\Models\PieceJointe;
use App\Services\Mail\IngesteurMail;
use App\Services\Mail\MailEntrant;
use App\Services\Mail\ResultatIngestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Eml;
use Tests\TestCase;

class IngestionMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        config()->set('sav.mailbox', 'sav@liftfoils.fr');
        config()->set('mail.from.address', 'sav@liftfoils.fr');
        config()->set('mail.from.name', 'SAV Lift Foils France');

        // Ces tests vérifient le comportement d'envoi (accusé, threading) : on
        // active donc l'envoi. Le garde-fou SAV_ENVOI_ACTIF a son propre test.
        config()->set('sav.envoi_actif', true);

        // La référence d'un dossier porte l'année : on fige le calendrier.
        $this->travelTo('2026-07-10 12:00:00');
    }

    private function ingerer(Eml $eml): ResultatIngestion
    {
        return app(IngesteurMail::class)->ingerer(MailEntrant::depuis($eml->parser()));
    }

    // ---------------------------------------------------------------- Ouverture

    public function test_un_mail_client_ouvre_un_dossier(): void
    {
        $resultat = $this->ingerer(Eml::make());

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);

        $cas = Cas::sole();

        $this->assertSame('SAV-2026-0001', $cas->reference);
        $this->assertSame('Camille Dupont', $cas->client_nom);
        $this->assertSame('camille@example.test', $cas->client_email);
        $this->assertSame(StatutCas::Nouveau, $cas->statut);
        $this->assertSame('email', $cas->source);
        $this->assertStringContainsString('Batterie qui ne charge plus', $cas->description);
        $this->assertStringContainsString('Ma batterie ne charge plus', $cas->description);
    }

    public function test_le_message_entrant_est_enregistre_avec_ses_entetes(): void
    {
        $this->ingerer(Eml::make()->entete('References', '<vieux@x.test>'));

        $message = Message::where('direction', DirectionMessage::Inbound)->sole();

        $this->assertSame('demande-1@example.test', $message->message_id);
        $this->assertSame('vieux@x.test', $message->email_references);
        $this->assertSame('camille@example.test', $message->from_email);
        $this->assertSame('Camille Dupont', $message->from_name);
        $this->assertSame('sav@liftfoils.fr', $message->to_email);
        $this->assertSame('Batterie qui ne charge plus', $message->subject);
        $this->assertSame('2026-07-10 08:00:00', $message->received_at->toDateTimeString());
    }

    public function test_les_references_annuelles_s_incrementent(): void
    {
        $this->ingerer(Eml::make());
        $this->ingerer(Eml::make()->entete('Message-ID', '<demande-2@example.test>'));

        $this->assertSame(
            ['SAV-2026-0001', 'SAV-2026-0002'],
            Cas::orderBy('id')->pluck('reference')->all(),
        );
    }

    /**
     * Les clients mail encodent les mots accentués (RFC 2047), et la librairie
     * IMAP ne les décode pas sans l'extension PHP `imap`. Sans EnteteMime, le
     * dossier s'appellerait « Autonomie de la batterie =?utf-8?Q?tr=C3=A8s?= ».
     */
    public function test_un_sujet_encode_est_decode_dans_le_dossier(): void
    {
        $this->ingerer(
            Eml::make()
                ->entete('Subject', 'Autonomie de la batterie =?utf-8?Q?tr=C3=A8s?= faible')
                ->entete('From', '=?utf-8?Q?Camille_Dup=C3=B4nt?= <camille@example.test>'),
        );

        $cas = Cas::sole();
        $message = Message::where('direction', DirectionMessage::Inbound)->sole();

        $this->assertSame('Autonomie de la batterie très faible', $message->subject);
        $this->assertSame('Camille Dupônt', $cas->client_nom);
        $this->assertStringContainsString('Autonomie de la batterie très faible', $cas->description);
    }

    /**
     * Le pire cas : une auto-réponse française dont le sujet est encodé. Si on
     * ne le décode pas avant de le comparer, la garde ne se déclenche pas et
     * l'accusé de réception part — droit dans une boucle avec l'auto-répondeur.
     */
    public function test_un_sujet_d_auto_reponse_encode_est_quand_meme_ignore(): void
    {
        $resultat = $this->ingerer(
            Eml::make()->entete('Subject', '=?UTF-8?B?UsOpcG9uc2UgYXV0b21hdGlxdWU6IGFic2VudA==?='),
        );

        $this->assertSame(ResultatIngestion::Ignore, $resultat);
        $this->assertSame(0, Cas::count());
        Mail::assertNothingSent();
    }

    public function test_un_corps_html_seul_alimente_la_description(): void
    {
        $this->ingerer(
            Eml::make()
                ->texte('')
                ->html('<p>La t&eacute;l&eacute;commande ne <b>s\'appaire</b> plus.</p>'),
        );

        $this->assertStringContainsString("La télécommande ne s'appaire plus.", Cas::sole()->description);
    }

    // -------------------------------------------------------------- Accusé de réception

    public function test_l_accuse_de_reception_part_et_se_greffe_sur_le_fil(): void
    {
        $this->ingerer(Eml::make());

        Mail::assertSentCount(1);
        Mail::assertSent(AccuseReceptionMail::class, function (AccuseReceptionMail $accuse): bool {
            $entetes = $accuse->headers();

            return $accuse->hasTo('camille@example.test')
                && $accuse->envelope()->subject === 'Réception de votre demande SAV Lift Foils'
                && $entetes->references === ['demande-1@example.test']
                && $entetes->text['In-Reply-To'] === '<demande-1@example.test>';
        });
    }

    public function test_l_accuse_est_enregistre_comme_message_sortant(): void
    {
        $this->ingerer(Eml::make());

        $sortant = Message::where('direction', DirectionMessage::Outbound)->sole();

        $this->assertSame('demande-1@example.test', $sortant->in_reply_to);
        $this->assertSame('camille@example.test', $sortant->to_email);
        $this->assertSame('sav@liftfoils.fr', $sortant->from_email);
        $this->assertStringEndsWith('@liftfoils.fr', $sortant->message_id);
    }

    public function test_le_garde_fou_d_envoi_bloque_tout_accuse(): void
    {
        // Le cran de sûreté : rien ne part, et rien n'est enregistré comme
        // sortant — le dossier reste vierge d'accusé pour le go-live.
        config()->set('sav.envoi_actif', false);

        $resultat = $this->ingerer(Eml::make());

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);
        $this->assertSame(1, Cas::count());
        Mail::assertNothingSent();
        $this->assertSame(0, Message::where('direction', DirectionMessage::Outbound)->count());
    }

    public function test_le_dossier_survit_a_un_smtp_en_panne(): void
    {
        Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP indisponible'));

        $resultat = $this->ingerer(Eml::make());

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);
        $this->assertSame(1, Cas::count());
        // Le message sortant n'est pas inventé : rien n'est parti.
        $this->assertSame(0, Message::where('direction', DirectionMessage::Outbound)->count());
    }

    // ------------------------------------------------------------------ Threading

    public function test_une_reponse_au_fil_ne_cree_pas_de_doublon(): void
    {
        $this->ingerer(Eml::make());

        $resultat = $this->ingerer(
            Eml::make()
                ->entete('Message-ID', '<reponse-1@example.test>')
                ->entete('In-Reply-To', '<demande-1@example.test>')
                ->entete('Subject', 'Re: Batterie qui ne charge plus')
                ->texte('Voici la photo demandée.'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame(1, Cas::count());
        $this->assertSame(3, Message::count()); // demande + accusé + réponse
        Mail::assertSentCount(1); // pas de deuxième accusé
    }

    public function test_une_reponse_a_notre_accuse_se_rattache_au_dossier(): void
    {
        $this->ingerer(Eml::make());

        $accuse = Message::where('direction', DirectionMessage::Outbound)->sole();

        $resultat = $this->ingerer(
            Eml::make()
                ->entete('Message-ID', '<reponse-2@example.test>')
                ->entete('In-Reply-To', "<{$accuse->message_id}>")
                ->entete('Subject', 'Re: Réception de votre demande SAV Lift Foils'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame(1, Cas::count());
    }

    public function test_le_fil_est_retrouve_par_les_references_quand_in_reply_to_manque(): void
    {
        $this->ingerer(Eml::make());

        $resultat = $this->ingerer(
            Eml::make()
                ->entete('Message-ID', '<reponse-3@example.test>')
                ->sansEntete('In-Reply-To')
                ->entete('References', '<inconnu@x.test> <demande-1@example.test>'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame(1, Cas::count());
    }

    public function test_un_mail_sans_lien_de_fil_ouvre_un_second_dossier(): void
    {
        $this->ingerer(Eml::make());
        $this->ingerer(Eml::make()->entete('Message-ID', '<autre@example.test>'));

        $this->assertSame(2, Cas::count());
        Mail::assertSentCount(2);
    }

    // ---------------------------------------------------------------- Idempotence

    public function test_relever_deux_fois_le_meme_mail_ne_cree_rien(): void
    {
        $this->assertSame(ResultatIngestion::NouveauDossier, $this->ingerer(Eml::make()));
        $this->assertSame(ResultatIngestion::Doublon, $this->ingerer(Eml::make()));

        $this->assertSame(1, Cas::count());
        $this->assertSame(2, Message::count());
        Mail::assertSentCount(1);
    }

    /**
     * Un mail sans Message-ID reçoit une empreinte dérivée de son contenu :
     * la déduplication doit tenir d'une relève à l'autre.
     */
    public function test_un_mail_sans_message_id_reste_deduplique(): void
    {
        $sansId = fn (): Eml => Eml::make()->sansEntete('Message-ID');

        $this->assertSame(ResultatIngestion::NouveauDossier, $this->ingerer($sansId()));
        $this->assertSame(ResultatIngestion::Doublon, $this->ingerer($sansId()));

        $this->assertSame(1, Cas::count());
    }

    // ------------------------------------------------------------ Gardes anti-boucle

    public function test_un_mail_de_la_boite_sav_elle_meme_est_ignore(): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('From', 'SAV <sav@liftfoils.fr>'));

        $this->assertSame(ResultatIngestion::Ignore, $resultat);
        $this->assertSame(0, Cas::count());
        Mail::assertNothingSent();
    }

    /** @param non-empty-string $expediteur */
    #[DataProvider('expediteursRobots')]
    public function test_un_expediteur_robot_est_ignore(string $expediteur): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('From', $expediteur));

        $this->assertSame(ResultatIngestion::Ignore, $resultat);
        $this->assertSame(0, Cas::count());
        Mail::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function expediteursRobots(): array
    {
        return [
            'noreply' => ['noreply@boutique.test'],
            'no-reply' => ['no-reply@boutique.test'],
            'mailer-daemon' => ['mailer-daemon@example.test'],
            'postmaster' => ['postmaster@example.test'],
        ];
    }

    public function test_un_auto_submitted_est_ignore(): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('Auto-Submitted', 'auto-replied'));

        $this->assertSame(ResultatIngestion::Ignore, $resultat);
        $this->assertSame(0, Cas::count());
        Mail::assertNothingSent();
    }

    public function test_un_auto_submitted_no_designe_un_humain(): void
    {
        // RFC 3834 : « no » est la valeur que portent les messages écrits à la main.
        $resultat = $this->ingerer(Eml::make()->entete('Auto-Submitted', 'no'));

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);
    }

    #[DataProvider('precedencesAutomatiques')]
    public function test_une_precedence_automatique_est_ignoree(string $precedence): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('Precedence', $precedence));

        $this->assertSame(ResultatIngestion::Ignore, $resultat);
        $this->assertSame(0, Cas::count());
    }

    /** @return array<string, array{string}> */
    public static function precedencesAutomatiques(): array
    {
        return [
            'bulk' => ['bulk'],
            'list' => ['list'],
            'auto_reply' => ['auto_reply'],
            'auto-reply' => ['auto-reply'],
        ];
    }

    #[DataProvider('sujetsAutomatiques')]
    public function test_un_sujet_d_auto_reponse_est_ignore(string $sujet): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('Subject', $sujet));

        $this->assertSame(ResultatIngestion::Ignore, $resultat);
        $this->assertSame(0, Cas::count());
        Mail::assertNothingSent();
    }

    /** @return array<string, array{string}> */
    public static function sujetsAutomatiques(): array
    {
        return [
            'Automatic reply' => ['Automatic reply: Batterie'],
            'Auto-Reply' => ['Auto-Reply: votre message'],
            'Autoreply' => ['Autoreply'],
            'Out of office' => ['Out of Office: back on Monday'],
            'Réponse automatique' => ['Réponse automatique : je suis absent'],
            'Absence du bureau' => ['Absence du bureau'],
            'Re: devant une auto-réponse' => ['Re: Automatic reply: Batterie'],
        ];
    }

    /**
     * Le piège du préfixe « Auto ». Dans un SAV d'eFoils, l'autonomie de la
     * batterie est le motif de dossier numéro un : une garde qui jetterait ce
     * mail perdrait des clients, en silence.
     */
    #[DataProvider('sujetsLegitimes')]
    public function test_un_sujet_legitime_qui_commence_par_auto_est_traite(string $sujet): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('Subject', $sujet));

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);
        $this->assertSame(1, Cas::count());
    }

    /** @return array<string, array{string}> */
    public static function sujetsLegitimes(): array
    {
        return [
            'autonomie' => ['Autonomie de la batterie très faible'],
            'autonomie en réponse' => ['Re: Autonomie batterie'],
            'auto au milieu' => ['Problème automatique de coupure'],
        ];
    }

    // ------------------------------------------------------------------- Lift / Zendesk

    public function test_un_mail_de_lift_ouvre_un_dossier_sans_accuse(): void
    {
        $resultat = $this->ingerer(Eml::make()->entete('From', 'Lift Support <help@liftfoils.com>'));

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);
        $this->assertSame(1, Cas::count());
        Mail::assertNothingSent();
    }

    public function test_un_mail_zendesk_se_rattache_sans_accuse(): void
    {
        $this->ingerer(Eml::make());
        Mail::assertSentCount(1);

        $resultat = $this->ingerer(
            Eml::make()
                ->entete('From', 'Lift <support@liftsupport.zendesk.com>')
                ->entete('Message-ID', '<zendesk-1@zendesk.com>')
                ->entete('In-Reply-To', '<demande-1@example.test>'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame(1, Cas::count());
        Mail::assertSentCount(1);
    }

    // ---------------------------------------------------------------- Pièces jointes

    public function test_les_pieces_jointes_sont_stockees_sur_le_disque_prive(): void
    {
        $this->ingerer(
            Eml::make()->pieceJointe('photo étiquette.jpg', "\xFF\xD8\xFF\xE0JFIF-contenu", 'image/jpeg'),
        );

        $cas = Cas::sole();
        $piece = PieceJointe::sole();

        $this->assertSame($cas->id, $piece->cas_id);
        $this->assertSame(Message::where('direction', DirectionMessage::Inbound)->sole()->id, $piece->message_id);
        $this->assertSame('photo étiquette.jpg', $piece->filename);
        $this->assertSame('image/jpeg', $piece->mime);
        $this->assertGreaterThan(0, $piece->taille);

        $this->assertStringStartsWith("sav/{$cas->id}/", $piece->path);
        $this->assertStringEndsWith('photo-etiquette.jpg', $piece->path);
        Storage::disk('local')->assertExists($piece->path);
    }

    public function test_deux_pieces_jointes_homonymes_ne_s_ecrasent_pas(): void
    {
        $this->ingerer(
            Eml::make()
                ->pieceJointe('IMG_0001.jpg', 'premiere', 'image/jpeg')
                ->pieceJointe('IMG_0001.jpg', 'seconde', 'image/jpeg'),
        );

        $chemins = PieceJointe::pluck('path');

        $this->assertCount(2, $chemins->unique());
        foreach ($chemins as $chemin) {
            Storage::disk('local')->assertExists($chemin);
        }
    }

    public function test_un_nom_de_fichier_hostile_ne_sort_pas_du_dossier(): void
    {
        $this->ingerer(Eml::make()->pieceJointe('../../../evil.php', '<?php echo 1;'));

        $cas = Cas::sole();
        $piece = PieceJointe::sole();

        $this->assertStringStartsWith("sav/{$cas->id}/", $piece->path);
        $this->assertStringNotContainsString('..', $piece->path);
    }

    public function test_une_piece_jointe_trop_volumineuse_est_ignoree(): void
    {
        config()->set('sav.max_attachment_mb', 1);

        $this->ingerer(
            Eml::make()
                ->pieceJointe('video.mp4', str_repeat('x', 1_500_000), 'video/mp4')
                ->pieceJointe('petite.txt', 'ok', 'text/plain'),
        );

        // Le dossier est bien ouvert : seule la vidéo est laissée de côté.
        $this->assertSame(1, Cas::count());
        $this->assertSame(['petite.txt'], PieceJointe::pluck('filename')->all());
    }
}

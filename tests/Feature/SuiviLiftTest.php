<?php

namespace Tests\Feature;

use App\Enums\StatutCas;
use App\Mail\TransmisLiftMail;
use App\Models\Cas;
use App\Services\Mail\IngesteurMail;
use App\Services\Mail\MailEntrant;
use App\Services\Mail\ResultatIngestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Eml;
use Tests\TestCase;

/**
 * La « synchro » avec Lift, telle qu'elle existe vraiment : par leurs mails.
 *
 * Leur API est fermée (401, testé au Bloc 3-D). Ce sont donc leurs mails qui
 * font avancer le dossier — l'accusé de leur Zendesk apporte le n° de ticket,
 * leurs réponses appellent une action. Rien à interroger, tout à lire.
 */
class SuiviLiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        config()->set('sav.mailbox', 'sav@liftfoils.fr');
        config()->set('mail.from.address', 'sav@liftfoils.fr');
        config()->set('sav.envoi_actif', true);
        config()->set('sav.ia.cle', '');   // pas d'IA dans ces tests
    }

    private function ingerer(Eml $eml): ResultatIngestion
    {
        return app(IngesteurMail::class)->ingerer(MailEntrant::depuis($eml->parser()));
    }

    /** Un dossier déjà parti chez Lift, en attente de leur accusé. */
    private function dossierEnvoye(): Cas
    {
        $cas = Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
            'statut' => StatutCas::EnvoyeLift,
        ]);

        // Le mail qu'on a envoyé à Lift : c'est son Message-ID que citera leur réponse.
        $cas->messages()->create([
            'message_id' => 'sav-vers-lift@liftfoils.fr',
            'direction' => 'outbound',
            'from_email' => 'sav@liftfoils.fr',
            'to_email' => 'help@liftfoils.com',
            'subject' => '[SAV-2026-0001] Battery not charging',
            'received_at' => now(),
        ]);

        return $cas->refresh();
    }

    private function mailDeLift(string $sujet, string $corps = 'Hello,'): Eml
    {
        return Eml::make()
            ->entete('From', 'Lift Support <support@liftsupport.zendesk.com>')
            ->entete('Message-ID', '<zendesk-'.md5($sujet).'@zendesk.com>')
            ->entete('In-Reply-To', '<sav-vers-lift@liftfoils.fr>')
            ->entete('Subject', $sujet)
            ->texte($corps);
    }

    // ------------------------------------------------------------ Capture du ticket

    public function test_l_accuse_de_lift_donne_le_numero_de_ticket_et_fait_avancer_le_dossier(): void
    {
        $cas = $this->dossierEnvoye();

        $resultat = $this->ingerer($this->mailDeLift(
            'Re: [SAV-2026-0001] Battery not charging',
            'Your request has been received and assigned Ticket #90907.',
        ));

        $this->assertSame(ResultatIngestion::Rattache, $resultat);

        $cas->refresh();
        $this->assertSame('90907', $cas->ticket_lift);
        $this->assertSame(StatutCas::AttenteLift, $cas->statut);
        $this->assertSame(
            'https://liftsupport.zendesk.com/hc/requests/90907',
            $cas->lienPortailZendesk(),
        );
    }

    /** Le client apprend que son dossier est bien arrivé chez Lift. */
    public function test_le_client_est_prevenu_quand_le_dossier_entre_chez_lift(): void
    {
        $cas = Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
        ]);

        $this->ingerer(
            $this->mailDeLift('Re: [SAV-2026-0001] Battery', 'assigned Ticket #90907')
                ->sansEntete('In-Reply-To'),  // rattaché par la référence, pas par le fil
        );

        $cas->refresh();
        $this->assertSame('90907', $cas->ticket_lift);
        $this->assertNotNull($cas->client_avise_lift_le);

        Mail::assertSent(TransmisLiftMail::class, fn (TransmisLiftMail $m): bool => $m->hasTo('camille@example.test'));
    }

    /** Une deuxième nouvelle de Lift ne redéclenche pas le mail au client. */
    public function test_le_client_n_est_prevenu_qu_une_fois(): void
    {
        $cas = $this->dossierEnvoye();

        $this->ingerer($this->mailDeLift('Ticket received', 'assigned Ticket #90907'));
        $this->ingerer($this->mailDeLift('Re: Ticket #90907', 'We need more photos.'));

        Mail::assertSentCount(1);
        $this->assertSame(1, $cas->messages()->where('subject', 'like', '%transmis%')->count());
    }

    // ------------------------------------------------------------ Réponses de Lift

    public function test_une_reponse_de_lift_est_datee_et_devient_la_prochaine_action(): void
    {
        $cas = $this->dossierEnvoye();
        $this->ingerer($this->mailDeLift('Ticket received', 'assigned Ticket #90907'));

        $this->ingerer($this->mailDeLift('Re: Ticket #90907', 'Please ship the battery to our RMA center.'));

        $cas->refresh();
        $this->assertNotNull($cas->reponse_lift_le);
        $this->assertStringContainsString('Lift a répondu', $cas->prochaineAction());
    }

    /**
     * Le garde-fou du Bloc 4 : sans n° de ticket, un mail de Lift ne pousse PAS le
     * dossier « chez Lift ». Sinon une notification Zendesk égarée y enverrait un
     * dossier qui n'y est jamais allé — et le client recevrait un mensonge.
     */
    public function test_un_mail_de_lift_sans_ticket_ne_fait_pas_avancer_le_statut(): void
    {
        $cas = Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille',
            'client_email' => 'camille@example.test',
        ]);
        $cas->messages()->create([
            'message_id' => 'demande-1@example.test',
            'direction' => 'inbound',
            'from_email' => 'camille@example.test',
            'subject' => 'Batterie',
            'received_at' => now(),
        ]);

        $this->ingerer(
            $this->mailDeLift('Newsletter dealers', 'Nothing to do with a ticket.')
                ->entete('In-Reply-To', '<demande-1@example.test>'),
        );

        $cas->refresh();
        $this->assertSame(StatutCas::Nouveau, $cas->statut);
        $this->assertNull($cas->ticket_lift);
        Mail::assertNotSent(TransmisLiftMail::class);
    }

    // ------------------------------------------------------------ Gardes de l'ingestion

    /**
     * L'accusé de Zendesk EST une auto-réponse : il part souvent d'un `noreply@` et
     * porte `Auto-Submitted`. Les gardes anti-boucle, qui jettent ces mails-là,
     * doivent laisser passer les partenaires — sinon on perd le n° de ticket, donc
     * tout le suivi.
     */
    public function test_les_gardes_anti_robot_ne_jettent_pas_les_mails_de_lift(): void
    {
        $cas = $this->dossierEnvoye();

        $resultat = $this->ingerer(
            $this->mailDeLift('Automatic reply: your request', 'assigned Ticket #90907')
                ->entete('From', 'Lift <noreply@liftsupport.zendesk.com>')
                ->entete('Auto-Submitted', 'auto-replied')
                ->entete('Precedence', 'bulk'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame('90907', $cas->refresh()->ticket_lift);
    }

    /**
     * Un mail de Lift qu'on ne sait pas rattacher ouvre un dossier — mieux vaut un
     * dossier à recoller qu'un mail perdu. Mais son « client », c'est Lift : on ne
     * lui écrit surtout pas, sous peine de lui ouvrir un ticket.
     */
    public function test_un_mail_de_lift_orphelin_n_entraine_aucun_mail_vers_lift(): void
    {
        $resultat = $this->ingerer($this->mailDeLift('Ticket #55555 opened')->sansEntete('In-Reply-To'));

        $this->assertSame(ResultatIngestion::NouveauDossier, $resultat);
        $this->assertSame('55555', Cas::sole()->ticket_lift);
        Mail::assertNothingSent();
    }

    /** Le repli quand le threading a sauté : le n° de ticket suffit à retrouver le dossier. */
    public function test_le_numero_de_ticket_rattache_un_mail_sans_fil(): void
    {
        $cas = $this->dossierEnvoye();
        $cas->forceFill(['ticket_lift' => '90907'])->save();

        $resultat = $this->ingerer(
            $this->mailDeLift('Re: Ticket #90907', 'Ship it back.')
                ->sansEntete('In-Reply-To'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame(1, Cas::count());
    }

    /** Dernier repli : le noyau du sujet, dépouillé des « Re: » et des crochets. */
    public function test_le_sujet_rattache_un_mail_sans_fil_ni_ticket(): void
    {
        $cas = $this->dossierEnvoye();

        $resultat = $this->ingerer(
            $this->mailDeLift('Re: [Lift Foils] [SAV-2026-0001] Battery not charging')
                ->sansEntete('In-Reply-To'),
        );

        $this->assertSame(ResultatIngestion::Rattache, $resultat);
        $this->assertSame(1, Cas::count());
    }
}

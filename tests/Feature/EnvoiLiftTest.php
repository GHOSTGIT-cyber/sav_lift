<?php

namespace Tests\Feature;

use App\Enums\DirectionMessage;
use App\Enums\StatutCas;
use App\Mail\BrouillonLiftMail;
use App\Mail\TransmisLiftMail;
use App\Models\Cas;
use App\Models\Message;
use App\Services\Mail\EnvoiLift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Le seul chemin par lequel un dossier part chez Lift — et ses trois verrous :
 * la règle de complétude, la validation humaine, SAV_ENVOI_ACTIF.
 */
class EnvoiLiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config()->set('mail.from.address', 'sav@liftfoils.fr');
        config()->set('mail.from.name', 'SAV Lift Foils France');
        config()->set('sav.lift.email', 'help@liftfoils.com');
        config()->set('sav.envoi_actif', true);
    }

    private function dossierPret(array $ecrasements = []): Cas
    {
        return Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-123456',
            'sales_order' => 'SO-99',
            'description' => 'Ne charge plus.',
            'photo_etiquette' => true,
            'photos_defaut' => true,
            'brouillon_lift' => "Subject: [SAV-2026-0001] Battery not charging\n\nHello Lift team,\n\nMHS-123456 won't charge.\n\nBest regards,",
            ...$ecrasements,
        ]);
    }

    public function test_le_dossier_part_chez_lift_et_le_client_est_prevenu(): void
    {
        $cas = $this->dossierPret();

        $this->assertNull(app(EnvoiLift::class)->empechement($cas));
        $this->assertTrue(app(EnvoiLift::class)->envoyer($cas));

        // Le brouillon (anglais) est parti à Lift…
        Mail::assertSent(BrouillonLiftMail::class, function (BrouillonLiftMail $mail): bool {
            return $mail->hasTo('help@liftfoils.com')
                && $mail->envelope()->subject === '[SAV-2026-0001] Battery not charging';
        });

        // … et le client (français) a été prévenu.
        Mail::assertSent(TransmisLiftMail::class, fn (TransmisLiftMail $m): bool => $m->hasTo('camille@example.test'));

        $cas->refresh();
        $this->assertSame(StatutCas::EnvoyeLift, $cas->statut);
        $this->assertNotNull($cas->envoye_lift_le);
        $this->assertNotNull($cas->client_avise_lift_le);
    }

    /**
     * Le mail vers Lift porte NOTRE Message-ID, et il est tracé : c'est ce qui
     * permettra à leur accusé (celui qui porte le n° de ticket) de retrouver le
     * dossier tout seul, par threading.
     */
    public function test_le_mail_vers_lift_est_trace_avec_son_message_id(): void
    {
        app(EnvoiLift::class)->envoyer($this->dossierPret());

        $sortant = Message::where('to_email', 'help@liftfoils.com')->sole();

        $this->assertSame(DirectionMessage::Outbound, $sortant->direction);
        $this->assertStringEndsWith('@liftfoils.fr', $sortant->message_id);
        $this->assertStringContainsString('MHS-123456', (string) $sortant->body_text);
        // La ligne « Subject: » est devenue l'objet : elle ne traîne pas dans le corps.
        $this->assertStringNotContainsString('Subject:', (string) $sortant->body_text);
    }

    // ------------------------------------------------------------------- Verrous

    public function test_un_dossier_incomplet_ne_part_pas(): void
    {
        $cas = $this->dossierPret(['numero_serie' => null]);

        $this->assertStringContainsString('Numéro de série', (string) app(EnvoiLift::class)->empechement($cas));

        $this->expectException(RuntimeException::class);

        try {
            app(EnvoiLift::class)->envoyer($cas);
        } finally {
            Mail::assertNothingSent();
            $this->assertSame(StatutCas::Nouveau, $cas->refresh()->statut);
        }
    }

    public function test_un_dossier_sans_brouillon_ne_part_pas(): void
    {
        $cas = $this->dossierPret(['brouillon_lift' => null]);

        $this->assertStringContainsString('Aucun brouillon', (string) app(EnvoiLift::class)->empechement($cas));

        $this->expectException(RuntimeException::class);
        app(EnvoiLift::class)->envoyer($cas);
    }

    /**
     * Garde-fou général fermé : rien ne part, et surtout le dossier ne MENT pas.
     * Il ne se dit pas « envoyé à Lift » alors que rien n'est parti.
     */
    public function test_le_garde_fou_d_envoi_laisse_le_dossier_intact(): void
    {
        config()->set('sav.envoi_actif', false);

        $cas = $this->dossierPret();

        $this->assertFalse(app(EnvoiLift::class)->envoyer($cas));

        Mail::assertNothingSent();

        $cas->refresh();
        $this->assertSame(StatutCas::Nouveau, $cas->statut);
        $this->assertNull($cas->envoye_lift_le);
        $this->assertSame(0, $cas->messages()->count());
    }

    // -------------------------------------------------------- Objet du brouillon

    /** L'objet porte toujours la référence : c'est elle qui ramènera leur réponse. */
    public function test_la_reference_est_forcee_dans_l_objet(): void
    {
        $cas = $this->dossierPret([
            'brouillon_lift' => "Subject: Battery not charging\n\nHello,",
        ]);

        $this->assertSame('[SAV-2026-0001] Battery not charging', $cas->sujetBrouillonLift());
        $this->assertSame('Hello,', $cas->corpsBrouillonLift());
    }

    public function test_un_brouillon_sans_ligne_subject_recoit_un_objet_de_repli(): void
    {
        $cas = $this->dossierPret(['brouillon_lift' => 'Hello Lift team, the battery is dead.']);

        $this->assertSame('[SAV-2026-0001] batterie Lift4', $cas->sujetBrouillonLift());
        $this->assertSame('Hello Lift team, the battery is dead.', $cas->corpsBrouillonLift());
    }
}

<?php

namespace Tests\Feature;

use App\Enums\VueDossier;
use App\Filament\Resources\Cas\CasResource;
use App\Models\Cas;
use App\Models\Message;
use App\Models\PieceJointe;
use App\Models\User;
use App\Support\ModeDemo;
use Database\Seeders\DemoSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * L'instance de démonstration s'ouvre SANS MOT DE PASSE, sur Internet — et son
 * code voyage dans la même image Docker que la production, où dorment les
 * dossiers de vrais clients.
 *
 * Tout ce fichier ne teste qu'une chose : que cette porte ne peut pas s'ouvrir
 * là où il ne faut pas.
 */
class ModeDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Filament::setCurrentPanel('admin');

        // La configuration d'une vraie démo : le drapeau, et aucune prise sur le
        // monde réel.
        config()->set('sav.demo.actif', true);
        config()->set('imap.accounts.default.password', '');
        config()->set('sav.envoi_actif', false);
    }

    // --------------------------------------------------------------- Le garde-fou

    public function test_le_mode_demo_est_inactif_par_defaut(): void
    {
        config()->set('sav.demo.actif', false);

        $this->assertFalse(ModeDemo::actif());
    }

    /**
     * LE test qui compte. Une instance qui sait relever la boîte sav@ est une
     * instance qui peut afficher de vrais clients : elle ne sera JAMAIS une démo,
     * quoi que dise SAV_DEMO.
     */
    public function test_un_mot_de_passe_imap_interdit_le_mode_demo(): void
    {
        config()->set('imap.accounts.default.password', 'le-mot-de-passe-de-la-vraie-boite');

        $this->assertFalse(ModeDemo::actif());
        $this->assertNotEmpty(ModeDemo::empechements());
    }

    /** Une instance qui peut écrire à de vraies personnes n'est pas une démo non plus. */
    public function test_un_envoi_actif_interdit_le_mode_demo(): void
    {
        config()->set('sav.envoi_actif', true);

        $this->assertFalse(ModeDemo::actif());
    }

    public function test_le_mode_demo_s_active_quand_l_instance_est_inoffensive(): void
    {
        $this->assertTrue(ModeDemo::actif());
        $this->assertSame([], ModeDemo::empechements());
    }

    // ------------------------------------------------------- L'accès sans mot de passe

    public function test_hors_demo_le_panneau_reclame_toujours_un_mot_de_passe(): void
    {
        config()->set('sav.demo.actif', false);
        User::factory()->create(['email' => ModeDemo::email()]);

        $this->get(CasResource::getUrl('index'))->assertRedirect();
    }

    /**
     * Le cas le plus dangereux, et celui qu'on ne verrait pas sans test : la prod,
     * avec SAV_DEMO=true posé par erreur. Le panneau doit rester fermé.
     */
    public function test_sav_demo_seul_n_ouvre_pas_le_panneau_d_une_instance_reelle(): void
    {
        config()->set('imap.accounts.default.password', 'le-mot-de-passe-de-la-vraie-boite');
        User::factory()->create(['email' => ModeDemo::email()]);

        $this->get(CasResource::getUrl('index'))->assertRedirect();
        $this->assertGuest();
    }

    public function test_en_demo_le_visiteur_est_connecte_d_office(): void
    {
        $visiteur = User::factory()->create(['email' => ModeDemo::email()]);

        $this->get(CasResource::getUrl('index'))
            ->assertOk()
            ->assertSee('DÉMONSTRATION');

        $this->assertAuthenticatedAs($visiteur);
    }

    /** Une démo publique n'a rien à faire dans Google. */
    public function test_la_demo_n_est_pas_indexable(): void
    {
        User::factory()->create(['email' => ModeDemo::email()]);

        $this->get(CasResource::getUrl('index'))
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Sans compte visiteur (seeder pas encore passé), on ne plante pas. */
    public function test_sans_compte_visiteur_la_page_de_connexion_reste_accessible(): void
    {
        $this->get(CasResource::getUrl('index'))->assertRedirect();
    }

    // --------------------------------------------------------------------- Le seeder

    public function test_le_seeder_refuse_de_tourner_hors_demo(): void
    {
        config()->set('sav.demo.actif', false);

        $this->seed(DemoSeeder::class);

        $this->assertSame(0, Cas::count());
    }

    public function test_le_seeder_remplit_les_cinq_vues(): void
    {
        $this->seed(DemoSeeder::class);

        foreach (VueDossier::cases() as $vue) {
            $this->assertGreaterThan(
                0,
                $vue->filtrer(Cas::query())->count(),
                "La vue « {$vue->getLabel()} » est vide : la démo ne la montrerait pas.",
            );
        }
    }

    /**
     * Le conteneur rejoue `migrate --seed` à CHAQUE déploiement : un seeder qui
     * duplique ses dossiers noierait la démo en quelques mises à jour.
     */
    public function test_le_seeder_est_rejouable_sans_dupliquer(): void
    {
        $this->seed(DemoSeeder::class);

        $avant = [Cas::count(), Message::count(), PieceJointe::count(), User::count()];
        $this->assertGreaterThan(0, $avant[0]);

        $this->seed(DemoSeeder::class);

        $this->assertSame($avant, [Cas::count(), Message::count(), PieceJointe::count(), User::count()]);
    }

    /**
     * Sans pièces jointes, on ne peut pas montrer le mécanisme central : un
     * dossier qui bascule en « À valider » parce que le client a envoyé la photo
     * de son étiquette.
     */
    public function test_la_demo_porte_de_vraies_pieces_jointes(): void
    {
        $this->seed(DemoSeeder::class);

        $cas = Cas::where('reference', 'SAV-2026-0103')->sole();

        $this->assertCount(3, $cas->pieceJointes);
        $this->assertTrue($cas->complet);
        $this->assertSame(VueDossier::AValider, $cas->vue());

        foreach ($cas->pieceJointes as $piece) {
            $this->assertTrue($piece->existeSurLeDisque(), "Le fichier {$piece->filename} manque sur le disque.");
        }
    }

    /** La démo ne doit jamais essayer d'écrire à ses faux clients. */
    public function test_le_seeder_n_envoie_aucun_mail(): void
    {
        Mail::fake();

        $this->seed(DemoSeeder::class);

        Mail::assertNothingSent();
    }
}

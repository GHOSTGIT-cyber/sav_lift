<?php

namespace Tests\Feature;

use App\Enums\StatutCas;
use App\Enums\VueDossier;
use App\Filament\Resources\Cas\CasResource;
use App\Filament\Resources\Cas\Pages\ListCas;
use App\Filament\Widgets\FilesDattente;
use App\Models\Cas;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * L'écran, du point de vue de Nico : cinq files, un compteur chacune, et
 * l'action attendue posée sur la ligne — sans avoir à ouvrir le dossier.
 */
class TableauDeBordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
        $this->actingAs(User::factory()->create());
        config()->set('sav.ia.cle', '');   // pas d'appel IA depuis l'écran
    }

    /** Un dossier complet, donc « À valider ». */
    private function dossierComplet(string $reference): Cas
    {
        return Cas::create([
            'reference' => $reference,
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-123456',
            'sales_order' => 'SO-99',
            'description' => 'Ne charge plus.',
            'photo_etiquette' => true,
            'photos_defaut' => true,
        ]);
    }

    public function test_chaque_onglet_ne_montre_que_sa_vue(): void
    {
        $aCompleter = Cas::create(['reference' => 'SAV-2026-0001', 'client_nom' => 'Incomplet']);
        $aValider = $this->dossierComplet('SAV-2026-0002');
        $chezLift = Cas::create(['reference' => 'SAV-2026-0003', 'statut' => StatutCas::AttenteLift]);
        $atelier = Cas::create(['reference' => 'SAV-2026-0004', 'statut' => StatutCas::Atelier]);
        $clos = Cas::create(['reference' => 'SAV-2026-0005', 'statut' => StatutCas::Clos]);

        $attendus = [
            VueDossier::AComplete->value => $aCompleter,
            VueDossier::AValider->value => $aValider,
            VueDossier::ChezLift->value => $chezLift,
            VueDossier::Atelier->value => $atelier,
            VueDossier::Clos->value => $clos,
        ];

        foreach ($attendus as $onglet => $dedans) {
            $dehors = array_values(array_filter($attendus, fn (Cas $cas): bool => ! $cas->is($dedans)));

            Livewire::test(ListCas::class)
                ->set('activeTab', $onglet)
                ->assertCanSeeTableRecords([$dedans])
                ->assertCanNotSeeTableRecords($dehors);
        }
    }

    public function test_les_compteurs_des_onglets_comptent_juste(): void
    {
        Cas::create(['reference' => 'SAV-2026-0001', 'client_nom' => 'A']);
        Cas::create(['reference' => 'SAV-2026-0002', 'client_nom' => 'B']);
        $this->dossierComplet('SAV-2026-0003');

        $onglets = Livewire::test(ListCas::class)->instance()->getTabs();

        // Filament rend le badge en chaîne : on compare ce qui s'affiche.
        $this->assertEquals(2, $onglets[VueDossier::AComplete->value]->getBadge());
        $this->assertEquals(1, $onglets[VueDossier::AValider->value]->getBadge());
        // Un onglet vide n'affiche pas « 0 » : il n'affiche rien.
        $this->assertNull($onglets[VueDossier::Clos->value]->getBadge());
    }

    /**
     * « Ce qui manque » est une liste, pas un badge « incomplet » : Nico doit
     * savoir quoi aller chercher sans ouvrir le dossier.
     */
    public function test_la_liste_affiche_ce_qui_manque(): void
    {
        Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille',
            'client_email' => 'camille@example.test',
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'description' => 'Ne charge plus.',
        ]);

        Livewire::test(ListCas::class)
            ->assertSee('Numéro de série (MHS)')
            ->assertSee('Photo de l\'étiquette MHS');
    }

    /** L'action attendue est sur la ligne — et seulement quand le dossier est prêt. */
    public function test_le_bouton_d_envoi_n_apparait_que_sur_un_dossier_a_valider(): void
    {
        $aValider = $this->dossierComplet('SAV-2026-0001');
        $aCompleter = Cas::create(['reference' => 'SAV-2026-0002', 'client_nom' => 'Incomplet']);

        Livewire::test(ListCas::class)
            ->set('activeTab', VueDossier::AValider->value)
            ->assertTableActionVisible('preparer_envoi_lift', $aValider);

        Livewire::test(ListCas::class)
            ->set('activeTab', VueDossier::AComplete->value)
            ->assertTableActionHidden('preparer_envoi_lift', $aCompleter);
    }

    public function test_le_widget_d_accueil_compte_les_memes_dossiers_que_les_onglets(): void
    {
        Cas::create(['reference' => 'SAV-2026-0001', 'client_nom' => 'A']);
        $this->dossierComplet('SAV-2026-0002');

        Livewire::test(FilesDattente::class)
            ->assertSee('À compléter')
            ->assertSee('À valider')
            ->assertSee('Relire et envoyer à Lift');
    }

    /** La fiche ouvre sur l'instruction, pas sur un formulaire nu. */
    public function test_la_fiche_ouvre_sur_la_prochaine_action(): void
    {
        $cas = Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille',
            'client_email' => 'camille@example.test',
        ]);

        $this->get(CasResource::getUrl('edit', ['record' => $cas]))
            ->assertOk()
            ->assertSee('Prochaine action')
            ->assertSee('Ce qui manque')
            ->assertSee('Relancer le client', escape: false);
    }
}

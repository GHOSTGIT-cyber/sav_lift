<?php

namespace Tests\Feature;

use App\Enums\StatutCas;
use App\Filament\Resources\Cas\CasResource;
use App\Filament\Resources\Cas\Pages\CreateCas;
use App\Filament\Resources\Cas\Pages\ListCas;
use App\Models\Cas;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CasResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->actingAs(User::factory()->create());
    }

    public function test_la_liste_des_dossiers_repond(): void
    {
        $this->get(CasResource::getUrl('index'))->assertOk();
    }

    public function test_un_dossier_cree_dans_l_admin_apparait_dans_la_table(): void
    {
        Livewire::test(CreateCas::class)
            ->fillForm([
                'reference' => 'SAV-2026-001',
                'client_nom' => 'Camille Dupont',
                'client_email' => 'camille@example.test',
                'client_telephone' => '0612345678',
                'produit' => 'Batterie',
                'modele' => 'Lift4',
                'numero_serie' => 'MHS-123456',
                'sales_order' => 'SO-98765',
                'description' => "N'a plus d'autonomie après 10 minutes.",
                'statut' => StatutCas::AttenteClient->value,
                'source' => 'manuel',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cas = Cas::sole();

        $this->assertSame('SAV-2026-001', $cas->reference);
        $this->assertSame('MHS-123456', $cas->numero_serie);
        $this->assertSame(StatutCas::AttenteClient, $cas->statut);

        Livewire::test(ListCas::class)
            ->assertCanSeeTableRecords([$cas]);
    }

    public function test_les_valeurs_par_defaut_sont_appliquees(): void
    {
        $cas = Cas::create(['client_nom' => 'Jean Test'])->refresh();

        $this->assertSame(StatutCas::Nouveau, $cas->statut);
        $this->assertSame('email', $cas->source);
    }

    public function test_deux_dossiers_sans_reference_peuvent_coexister(): void
    {
        foreach (['Alice', 'Bob'] as $nom) {
            Livewire::test(CreateCas::class)
                ->fillForm([
                    'client_nom' => $nom,
                    'statut' => StatutCas::Nouveau->value,
                    'source' => 'email',
                ])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame(2, Cas::whereNull('reference')->count());
    }

    public function test_une_reference_en_double_est_refusee(): void
    {
        Cas::create(['reference' => 'SAV-2026-001']);

        Livewire::test(CreateCas::class)
            ->fillForm([
                'reference' => 'SAV-2026-001',
                'statut' => StatutCas::Nouveau->value,
                'source' => 'email',
            ])
            ->call('create')
            ->assertHasFormErrors(['reference']);
    }

    public function test_le_filtre_par_statut_fonctionne(): void
    {
        $nouveau = Cas::create(['client_nom' => 'A', 'statut' => StatutCas::Nouveau]);
        $clos = Cas::create(['client_nom' => 'B', 'statut' => StatutCas::Clos]);

        Livewire::test(ListCas::class)
            ->filterTable('statut', StatutCas::Clos->value)
            ->assertCanSeeTableRecords([$clos])
            ->assertCanNotSeeTableRecords([$nouveau]);
    }

    public function test_chaque_statut_affiche_son_libelle_francais_dans_la_table(): void
    {
        foreach (StatutCas::cases() as $statut) {
            Cas::create(['client_nom' => $statut->name, 'statut' => $statut]);
        }

        $reponse = Livewire::test(ListCas::class);

        foreach (StatutCas::cases() as $statut) {
            $reponse->assertSee($statut->getLabel());
        }
    }

    /**
     * Un badge dont la couleur n'est pas enregistrée dans le panneau rend des
     * variables CSS indéfinies : le badge s'affiche alors sans couleur.
     */
    public function test_les_couleurs_des_badges_de_statut_sont_enregistrees(): void
    {
        Filament::bootCurrentPanel();

        $enregistrees = array_keys(FilamentColor::getColors());

        foreach (StatutCas::cases() as $statut) {
            $this->assertContains(
                $statut->getColor(),
                $enregistrees,
                "La couleur « {$statut->getColor()} » du statut « {$statut->value} » n'est pas enregistrée dans AdminPanelProvider.",
            );
        }
    }

    public function test_la_page_expose_bien_les_variables_css_des_couleurs_personnalisees(): void
    {
        $reponse = $this->get(CasResource::getUrl('index'))->assertOk();

        foreach (['orange', 'indigo', 'violet', 'cyan'] as $couleur) {
            $reponse->assertSee("--{$couleur}-500", escape: false);
        }
    }

    public function test_la_table_est_triee_par_date_de_creation_decroissante(): void
    {
        $ancien = Cas::create(['client_nom' => 'Ancien', 'created_at' => now()->subDay()]);
        $recent = Cas::create(['client_nom' => 'Récent', 'created_at' => now()]);

        Livewire::test(ListCas::class)
            ->assertCanSeeTableRecords([$recent, $ancien], inOrder: true);
    }
}

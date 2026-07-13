<?php

namespace Tests\Feature;

use App\Enums\StatutCas;
use App\Enums\VueDossier;
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

    public function test_la_page_edition_repond_avec_les_actions_ia(): void
    {
        // Rend la page d'édition (form Bloc 2/3 + actions Ré-extraire / Brouillon Lift).
        $cas = Cas::create(['reference' => 'SAV-2026-0001', 'client_nom' => 'Test']);

        $this->get(CasResource::getUrl('edit', ['record' => $cas]))->assertOk();
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

    /**
     * La liste est désormais découpée en cinq onglets (VueDossier) : le filtre
     * par statut interne travaille DANS l'onglet actif, il ne le contourne pas.
     * On se place donc dans « Atelier », qui porte les deux statuts fins.
     */
    public function test_le_filtre_par_statut_fonctionne_dans_l_onglet(): void
    {
        $atelier = Cas::create(['client_nom' => 'A', 'statut' => StatutCas::Atelier]);
        $pret = Cas::create(['client_nom' => 'B', 'statut' => StatutCas::Pret]);

        Livewire::test(ListCas::class)
            ->set('activeTab', VueDossier::Atelier->value)
            ->assertCanSeeTableRecords([$atelier, $pret])
            ->filterTable('statut', StatutCas::Pret->value)
            ->assertCanSeeTableRecords([$pret])
            ->assertCanNotSeeTableRecords([$atelier]);
    }

    public function test_les_cinq_vues_sont_les_onglets_de_la_liste(): void
    {
        $reponse = Livewire::test(ListCas::class);

        foreach (VueDossier::cases() as $vue) {
            $reponse->assertSee($vue->getLabel());
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

        foreach (VueDossier::cases() as $vue) {
            $this->assertContains(
                $vue->getColor(),
                $enregistrees,
                "La couleur « {$vue->getColor()} » de la vue « {$vue->value} » n'est pas enregistrée dans AdminPanelProvider.",
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

        // Tous deux incomplets → onglet « À compléter », l'onglet par défaut.
        Livewire::test(ListCas::class)
            ->assertCanSeeTableRecords([$recent, $ancien], inOrder: true);
    }
}

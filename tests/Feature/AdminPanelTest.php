<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_panneau_admin_exige_une_authentification(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_la_page_de_connexion_repond(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_le_seeder_cree_l_administrateur_depuis_la_config(): void
    {
        config([
            'sav.admin.nom' => 'Patron',
            'sav.admin.email' => 'patron@liftfoils.fr',
            'sav.admin.password' => 'secret-du-patron',
        ]);

        $this->seed(AdminUserSeeder::class);

        $admin = User::whereEmail('patron@liftfoils.fr')->sole();

        $this->assertSame('Patron', $admin->name);
        $this->assertTrue(Hash::check('secret-du-patron', $admin->password));
    }

    public function test_le_seeder_est_idempotent_et_met_a_jour_le_mot_de_passe(): void
    {
        config(['sav.admin.email' => 'patron@liftfoils.fr', 'sav.admin.password' => 'v1']);
        $this->seed(AdminUserSeeder::class);

        config(['sav.admin.password' => 'v2']);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, User::whereEmail('patron@liftfoils.fr')->count());
        $this->assertTrue(Hash::check('v2', User::whereEmail('patron@liftfoils.fr')->sole()->password));
    }

    public function test_l_administrateur_seede_peut_se_connecter_sur_le_panneau(): void
    {
        config([
            'sav.admin.email' => 'patron@liftfoils.fr',
            'sav.admin.password' => 'secret-du-patron',
        ]);
        $this->seed(AdminUserSeeder::class);

        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'patron@liftfoils.fr',
                'password' => 'secret-du-patron',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs(User::whereEmail('patron@liftfoils.fr')->sole());
    }

    public function test_un_mauvais_mot_de_passe_est_refuse(): void
    {
        config(['sav.admin.email' => 'patron@liftfoils.fr', 'sav.admin.password' => 'bon']);
        $this->seed(AdminUserSeeder::class);

        Filament::setCurrentPanel('admin');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'patron@liftfoils.fr', 'password' => 'mauvais'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_un_administrateur_peut_ouvrir_le_panneau(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk();
    }

    /**
     * Filament renvoie 403 hors environnement « local » tant que le modèle
     * User n'implémente pas FilamentUser. Ce test garde le déploiement.
     */
    public function test_le_panneau_reste_accessible_en_production(): void
    {
        config(['app.env' => 'production']);

        $this->actingAs(User::factory()->create())
            ->get('/admin')
            ->assertOk();
    }
}

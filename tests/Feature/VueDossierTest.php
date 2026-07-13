<?php

namespace Tests\Feature;

use App\Enums\StatutCas;
use App\Enums\VueDossier;
use App\Models\Cas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les cinq vues sont la seule chose que l'utilisateur voit. Deux propriétés les
 * rendent fiables, et sont testées ici :
 *
 *  - **exhaustivité** : chaque statut interne tombe dans une vue. Aucun dossier
 *    ne peut disparaître de l'écran ;
 *  - **cohérence** : le calcul en PHP (VueDossier::de) et le filtre SQL
 *    (VueDossier::filtrer) répondent la même chose. Sinon les compteurs des
 *    onglets mentiraient sur leur propre contenu.
 */
class VueDossierTest extends TestCase
{
    use RefreshDatabase;

    private function dossier(StatutCas $statut, bool $complet): Cas
    {
        $cas = Cas::create(['client_nom' => $statut->name, 'statut' => $statut]);

        // `complet` est dérivé (hook `saving`) : on le force en base pour balayer
        // les deux cas sans avoir à fabriquer un dossier complet à chaque fois.
        Cas::query()->whereKey($cas->id)->update(['complet' => $complet]);

        return $cas->refresh();
    }

    public function test_chaque_statut_tombe_dans_une_vue(): void
    {
        foreach (StatutCas::cases() as $statut) {
            foreach ([true, false] as $complet) {
                $cas = $this->dossier($statut, $complet);

                $this->assertInstanceOf(VueDossier::class, VueDossier::de($cas));
            }
        }
    }

    /** Le PHP et le SQL doivent voir le même dossier dans la même vue. */
    public function test_le_calcul_php_et_le_filtre_sql_concordent(): void
    {
        foreach (StatutCas::cases() as $statut) {
            foreach ([true, false] as $complet) {
                $cas = $this->dossier($statut, $complet);
                $attendue = VueDossier::de($cas);

                foreach (VueDossier::cases() as $vue) {
                    $trouve = $vue->filtrer(Cas::query()->whereKey($cas->id))->exists();

                    $this->assertSame(
                        $vue === $attendue,
                        $trouve,
                        "Statut {$statut->value} (complet: ".var_export($complet, true).') : '
                        ."PHP dit « {$attendue->getLabel()} », le SQL de « {$vue->getLabel()} » dit "
                        .var_export($trouve, true).'.',
                    );
                }

                $cas->delete();
            }
        }
    }

    public function test_un_dossier_incomplet_est_a_completer_et_complet_a_valider(): void
    {
        $this->assertSame(VueDossier::AComplete, VueDossier::de($this->dossier(StatutCas::Nouveau, false)));
        $this->assertSame(VueDossier::AValider, VueDossier::de($this->dossier(StatutCas::Nouveau, true)));
    }

    /**
     * Un dossier parti chez Lift n'est plus « à valider », même incomplet : la
     * complétude ne pilote que les dossiers encore à la maison.
     */
    public function test_le_cycle_de_vie_prime_sur_la_completude(): void
    {
        $this->assertSame(VueDossier::ChezLift, VueDossier::de($this->dossier(StatutCas::EnvoyeLift, false)));
        $this->assertSame(VueDossier::Atelier, VueDossier::de($this->dossier(StatutCas::Pret, false)));
        $this->assertSame(VueDossier::Clos, VueDossier::de($this->dossier(StatutCas::Clos, true)));
    }

    // ------------------------------------------------------- Prochaine action

    public function test_la_prochaine_action_dit_ce_qui_manque(): void
    {
        $cas = Cas::create(['client_nom' => 'Camille', 'client_email' => 'c@example.test']);

        $action = $cas->prochaineAction();

        $this->assertStringContainsString('Relancer le client', $action);
        $this->assertStringContainsString('Numéro de série (MHS)', $action);
    }

    public function test_la_prochaine_action_signale_une_relance_deja_partie(): void
    {
        $cas = Cas::create(['client_nom' => 'Camille']);
        $cas->forceFill(['relance_client_le' => now()])->save();

        $this->assertStringContainsString('Relance envoyée le', $cas->prochaineAction());
    }

    public function test_la_prochaine_action_signale_une_reponse_de_lift(): void
    {
        $cas = Cas::create(['client_nom' => 'Camille', 'statut' => StatutCas::AttenteLift]);
        $cas->forceFill(['ticket_lift' => '90907', 'reponse_lift_le' => now()])->save();

        $this->assertStringContainsString('Lift a répondu', $cas->prochaineAction());
    }

    public function test_la_prochaine_action_dit_d_attendre_lift_sinon(): void
    {
        $cas = Cas::create(['client_nom' => 'Camille', 'statut' => StatutCas::AttenteLift]);
        $cas->forceFill(['ticket_lift' => '90907'])->save();

        $this->assertStringContainsString('#90907', $cas->prochaineAction());
        $this->assertStringContainsString('attente', $cas->prochaineAction());
    }
}

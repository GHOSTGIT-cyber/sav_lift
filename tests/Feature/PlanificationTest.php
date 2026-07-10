<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * La relève ne tourne que si le planificateur la connaît. Une faute de frappe
 * dans routes/console.php ne casserait rien de visible : la boîte sav@ se
 * remplirait en silence.
 */
class PlanificationTest extends TestCase
{
    public function test_la_releve_est_planifiee(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('sav:fetch-mail')
            ->assertSuccessful();
    }

    public function test_la_releve_tourne_toutes_les_deux_minutes_sans_chevauchement(): void
    {
        $evenement = collect(app(Schedule::class)->events())
            ->first(fn ($evenement): bool => str_contains($evenement->command ?? '', 'sav:fetch-mail'));

        $this->assertNotNull($evenement, 'La commande sav:fetch-mail n\'est pas planifiée.');
        $this->assertSame('*/2 * * * *', $evenement->expression);

        // Deux relèves concurrentes ouvriraient le même dossier deux fois, avant
        // que la première n'ait écrit son Message-ID.
        $this->assertTrue($evenement->withoutOverlapping);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Cas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class BrouillonLiftCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sav.ia.cle', 'cle-de-test');
        Mail::fake();
    }

    public function test_la_commande_genere_et_stocke_le_brouillon_sans_envoyer(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => "Subject: Repair request\n\nHello Lift, ..."]]],
            ]),
        ]);

        $cas = Cas::create(['reference' => 'SAV-2026-0001', 'produit' => 'moteur', 'numero_serie' => 'MHS-9']);

        $this->artisan('sav:brouillon-lift SAV-2026-0001')
            ->assertSuccessful()
            ->expectsOutputToContain('non envoyé');

        $cas->refresh();
        $this->assertStringContainsString('Subject: Repair request', (string) $cas->brouillon_lift);
        $this->assertNotNull($cas->brouillon_lift_le);
        Mail::assertNothingSent();
    }

    public function test_la_commande_echoue_sans_cle(): void
    {
        config()->set('sav.ia.cle', '');
        Http::fake();
        Cas::create(['reference' => 'SAV-2026-0001']);

        $this->artisan('sav:brouillon-lift SAV-2026-0001')->assertFailed();
        Http::assertNothingSent();
    }
}

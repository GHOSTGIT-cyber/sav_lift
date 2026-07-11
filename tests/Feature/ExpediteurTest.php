<?php

namespace Tests\Feature;

use App\Services\Mail\Expediteur;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExpediteurTest extends TestCase
{
    private function mail(): Mailable
    {
        return new class extends Mailable
        {
            public function build()
            {
                return $this->html('coucou');
            }
        };
    }

    public function test_envoie_reellement_quand_actif(): void
    {
        Mail::fake();
        config()->set('sav.envoi_actif', true);

        $parti = app(Expediteur::class)->envoyer('client@example.test', $this->mail());

        $this->assertTrue($parti);
        Mail::assertSentCount(1);
    }

    public function test_simule_et_n_envoie_rien_quand_inactif(): void
    {
        Mail::fake();
        config()->set('sav.envoi_actif', false);

        $parti = app(Expediteur::class)->envoyer('client@example.test', $this->mail());

        $this->assertFalse($parti);
        Mail::assertNothingSent();
    }
}

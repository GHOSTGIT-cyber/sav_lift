<?php

namespace Tests\Feature;

use App\Models\Cas;
use Tests\TestCase;

class LienPortailZendeskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('sav.zendesk.portail_url', 'https://liftsupport.zendesk.com');
    }

    private function casAvecTicket(?string $ticket): Cas
    {
        return (new Cas)->forceFill(['ticket_lift' => $ticket]);
    }

    public function test_construit_le_lien_a_partir_du_numero(): void
    {
        $this->assertSame(
            'https://liftsupport.zendesk.com/hc/requests/90907',
            $this->casAvecTicket('90907')->lienPortailZendesk(),
        );
    }

    public function test_extrait_les_chiffres_d_un_ticket_decore(): void
    {
        $this->assertSame(
            'https://liftsupport.zendesk.com/hc/requests/90907',
            $this->casAvecTicket('#90907')->lienPortailZendesk(),
        );
        $this->assertSame(
            'https://liftsupport.zendesk.com/hc/requests/90673',
            $this->casAvecTicket('Ticket 90673')->lienPortailZendesk(),
        );
    }

    public function test_null_sans_ticket(): void
    {
        $this->assertNull($this->casAvecTicket(null)->lienPortailZendesk());
        $this->assertNull($this->casAvecTicket('')->lienPortailZendesk());
        $this->assertNull($this->casAvecTicket('aucun-numero')->lienPortailZendesk());
    }
}

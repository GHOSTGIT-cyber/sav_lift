<?php

namespace Tests\Unit;

use App\Support\TicketLift;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TicketLiftTest extends TestCase
{
    #[DataProvider('mailsDeLift')]
    public function test_le_numero_de_ticket_est_lu(?string $attendu, string $sujet, ?string $corps = null): void
    {
        $this->assertSame($attendu, TicketLift::numero($sujet, $corps));
    }

    /** @return array<string, array{?string, string, 2?: ?string}> */
    public static function mailsDeLift(): array
    {
        return [
            'accusé Zendesk' => [
                '90907',
                'Re: Battery not charging',
                'Your request has been received and assigned Ticket #90907. We will get back to you shortly.',
            ],
            'objet Zendesk' => ['90907', '[Lift Foils] Re: Battery not charging (#90907)'],
            'ticket dans l\'objet' => ['12345', 'Ticket #12345 — Battery not charging'],
            'request entre parenthèses' => ['77777', 'Your request (#77777) has been solved'],
            'sans dièse' => ['4242', 'Ticket 4242 updated'],
            'rien à lire' => [null, 'Re: Battery not charging'],
        ];
    }

    /**
     * L'objet prime sur le corps : le pied de page d'un mail Zendesk cite volontiers
     * d'autres numéros (commande, RMA). Celui de l'objet est le bon.
     */
    public function test_l_objet_prime_sur_le_corps(): void
    {
        $this->assertSame(
            '90907',
            TicketLift::numero('Ticket #90907 — battery', 'See also ticket #11111 for the previous case.'),
        );
    }

    /** Un numéro trop court n'est pas un ticket (« Lift4 », « #12 »). */
    public function test_un_numero_trop_court_est_ignore(): void
    {
        $this->assertNull(TicketLift::numero('Re: Lift4 (#12)'));
    }
}

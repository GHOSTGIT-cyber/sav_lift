<?php

namespace Tests\Unit;

use App\Support\SujetMail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SujetMailTest extends TestCase
{
    #[DataProvider('sujets')]
    public function test_le_noyau_du_sujet_est_isole(string $attendu, string $sujet): void
    {
        $this->assertSame($attendu, SujetMail::noyau($sujet));
    }

    /** @return array<string, array{string, string}> */
    public static function sujets(): array
    {
        return [
            'nu' => ['battery not charging', 'Battery not charging'],
            'préfixe de réponse' => ['battery not charging', 'Re: Battery not charging'],
            'préfixes empilés' => ['battery not charging', 'Re: Fwd: RE: Battery not charging'],
            'étiquette du robot' => ['battery not charging', '[Lift Foils] Battery not charging'],
            'notre référence' => ['battery not charging', '[SAV-2026-0001] Battery not charging'],
            'n° de ticket en queue' => ['battery not charging', 'Battery not charging (#90907)'],
            'le tout mélangé' => [
                'battery not charging',
                'Re: [Lift Foils] Re: [SAV-2026-0001] Battery not charging (#90907)',
            ],
            'espaces multiples' => ['battery not charging', "Battery   not\tcharging "],
            'vide' => ['', 'Re: '],
        ];
    }

    public function test_la_reference_du_dossier_est_retrouvee(): void
    {
        $this->assertSame('SAV-2026-0001', SujetMail::reference('[SAV-2026-0001] Battery not charging'));
        $this->assertSame('SAV-2026-0042', SujetMail::reference(null, 'Dealer case reference: sav-2026-0042'));
        $this->assertNull(SujetMail::reference('Battery not charging', 'no reference here'));
    }
}

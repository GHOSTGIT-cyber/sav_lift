<?php

namespace Tests\Unit;

use App\Support\EnteteMime;
use PHPUnit\Framework\TestCase;

class EnteteMimeTest extends TestCase
{
    public function test_un_encoded_word_quoted_printable_est_decode(): void
    {
        $this->assertSame('très', EnteteMime::decoder('=?utf-8?Q?tr=C3=A8s?='));
    }

    public function test_un_encoded_word_base64_est_decode(): void
    {
        $this->assertSame('Réponse automatique', EnteteMime::decoder('=?UTF-8?B?UsOpcG9uc2UgYXV0b21hdGlxdWU=?='));
    }

    /**
     * Le cas qui casse en vrai : les clients mail n'encodent que les mots qui
     * en ont besoin, au milieu d'un sujet par ailleurs en clair.
     */
    public function test_un_encoded_word_au_milieu_du_sujet_est_decode(): void
    {
        $this->assertSame(
            'Autonomie de la batterie très faible',
            EnteteMime::decoder('Autonomie de la batterie =?utf-8?Q?tr=C3=A8s?= faible'),
        );
    }

    public function test_le_souligne_du_quoted_printable_redevient_une_espace(): void
    {
        $this->assertSame('Camille Dupont', EnteteMime::decoder('=?utf-8?Q?Camille_Dupont?='));
    }

    public function test_un_sujet_iso_8859_1_est_ramene_en_utf8(): void
    {
        $this->assertSame('Batterie défectueuse', EnteteMime::decoder('=?ISO-8859-1?Q?Batterie_d=E9fectueuse?='));
    }

    public function test_un_entete_deja_lisible_traverse_sans_dommage(): void
    {
        $this->assertSame('Batterie qui ne charge plus', EnteteMime::decoder('Batterie qui ne charge plus'));
        $this->assertSame('Autonomie très faible', EnteteMime::decoder('Autonomie très faible'));
    }

    public function test_un_entete_vide_devient_null(): void
    {
        $this->assertNull(EnteteMime::decoder(null));
        $this->assertNull(EnteteMime::decoder('   '));
    }

    /** Un encoded-word malformé ne doit pas effacer le sujet. */
    public function test_un_encoded_word_malforme_retombe_sur_la_valeur_brute(): void
    {
        $this->assertSame('=?charset-inconnu?Q?abc?=', EnteteMime::decoder('=?charset-inconnu?Q?abc?='));
    }
}

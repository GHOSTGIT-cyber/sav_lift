<?php

namespace Tests\Unit;

use App\Support\MessageId;
use PHPUnit\Framework\TestCase;

class MessageIdTest extends TestCase
{
    public function test_les_chevrons_et_les_espaces_sont_retires(): void
    {
        $this->assertSame('abc@example.test', MessageId::normaliser('  <abc@example.test> '));
        $this->assertSame('abc@example.test', MessageId::normaliser('abc@example.test'));
    }

    public function test_un_identifiant_vide_devient_null(): void
    {
        $this->assertNull(MessageId::normaliser(null));
        $this->assertNull(MessageId::normaliser('   '));
        $this->assertNull(MessageId::normaliser('<>'));
    }

    public function test_une_liste_de_references_est_eclatee_et_normalisee(): void
    {
        $this->assertSame(
            ['a@x.test', 'b@x.test', 'c@x.test'],
            MessageId::liste('<a@x.test> <b@x.test>,  <c@x.test>'),
        );
    }

    public function test_une_liste_deja_eclatee_est_acceptee(): void
    {
        // Webklex livre déjà les References sous forme de tableau, sans chevrons.
        $this->assertSame(['a@x.test', 'b@x.test'], MessageId::liste(['a@x.test', '<b@x.test>']));
    }

    public function test_les_doublons_sont_ecartes(): void
    {
        $this->assertSame(['a@x.test'], MessageId::liste('<a@x.test> <a@x.test>'));
    }

    public function test_une_liste_absente_donne_un_tableau_vide(): void
    {
        $this->assertSame([], MessageId::liste(null));
        $this->assertSame([], MessageId::liste(''));
    }

    public function test_un_identifiant_sortant_porte_le_domaine_demande(): void
    {
        $id = MessageId::genererPourSortant('liftfoils.fr');

        $this->assertStringStartsWith('sav-', $id);
        $this->assertStringEndsWith('@liftfoils.fr', $id);
    }

    public function test_deux_identifiants_sortants_different(): void
    {
        $this->assertNotSame(
            MessageId::genererPourSortant('liftfoils.fr'),
            MessageId::genererPourSortant('liftfoils.fr'),
        );
    }

    public function test_les_chevrons_sont_remis_pour_ecrire_un_entete(): void
    {
        $this->assertSame('<abc@x.test>', MessageId::enChevrons('abc@x.test'));
        $this->assertSame('<abc@x.test>', MessageId::enChevrons('<abc@x.test>'));
    }
}

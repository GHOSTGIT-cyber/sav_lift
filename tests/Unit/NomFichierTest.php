<?php

namespace Tests\Unit;

use App\Support\NomFichier;
use PHPUnit\Framework\TestCase;

class NomFichierTest extends TestCase
{
    public function test_un_nom_ordinaire_est_conserve(): void
    {
        $this->assertSame('facture-2026.pdf', NomFichier::securiser('facture-2026.pdf'));
    }

    /**
     * Le nom d'une pièce jointe vient de l'email : c'est une donnée hostile.
     * Aucun segment de chemin ne doit survivre.
     */
    public function test_la_traversee_de_repertoire_est_neutralisee(): void
    {
        // Le point de tête saute aussi au passage : pas de fichier caché.
        $this->assertSame('env', NomFichier::securiser('../../../.env'));
        $this->assertSame('shell.php', NomFichier::securiser('/etc/cron.d/../shell.php'));
        $this->assertSame('evil.php', NomFichier::securiser('..\\..\\windows\\evil.php'));
    }

    public function test_aucun_nom_ne_contient_de_separateur(): void
    {
        foreach (['a/b.txt', 'a\\b.txt', '....//x.txt'] as $hostile) {
            $nom = NomFichier::securiser($hostile);

            $this->assertStringNotContainsString('/', $nom);
            $this->assertStringNotContainsString('\\', $nom);
            $this->assertStringNotContainsString('..', $nom);
        }
    }

    public function test_les_accents_sont_translitteres_et_les_espaces_remplaces(): void
    {
        $this->assertSame('photo-etiquette-MHS.jpg', NomFichier::securiser('photo étiquette MHS.jpg'));
    }

    public function test_les_octets_nuls_et_caracteres_de_controle_disparaissent(): void
    {
        $this->assertSame('photo.jpg', NomFichier::securiser("photo\0.jpg"));
    }

    public function test_un_nom_vide_recoit_un_nom_de_repli(): void
    {
        $this->assertSame('piece-jointe', NomFichier::securiser(null));
        $this->assertSame('piece-jointe', NomFichier::securiser(''));
        $this->assertSame('piece-jointe', NomFichier::securiser('...'));
    }

    public function test_un_nom_sans_extension_reste_intact(): void
    {
        $this->assertSame('rapport', NomFichier::securiser('rapport'));
    }

    public function test_un_nom_tres_long_est_tronque(): void
    {
        $nom = NomFichier::securiser(str_repeat('a', 400).'.jpg');

        $this->assertLessThanOrEqual(140, strlen($nom));
        $this->assertStringEndsWith('.jpg', $nom);
    }
}

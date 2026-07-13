<?php

namespace Tests\Feature;

use App\Models\Cas;
use App\Models\PieceJointe;
use App\Services\Dossier\RegleCompletude;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * LA règle métier de l'outil : ce qui autorise un dossier à partir chez Lift.
 *
 * Elle pilote tout le reste — le mail au client, les cinq vues, le bouton
 * d'envoi. Un changement ici doit être délibéré : c'est une décision de Nico,
 * pas un effet de bord.
 */
class RegleCompletudeTest extends TestCase
{
    use RefreshDatabase;

    /** Un dossier auquel il ne manque RIEN de bloquant. */
    private function dossierComplet(array $ecrasements = []): Cas
    {
        return Cas::create([
            'reference' => 'SAV-2026-0001',
            'client_nom' => 'Camille Dupont',
            'client_email' => 'camille@example.test',
            'produit' => 'batterie',
            'modele' => 'Lift4',
            'numero_serie' => 'MHS-123456',
            'sales_order' => 'SO-99',
            'description' => 'Ne charge plus.',
            'photo_etiquette' => true,
            'photos_defaut' => true,
            ...$ecrasements,
        ]);
    }

    private function joindre(Cas $cas, string $mime, string $nom = 'piece'): PieceJointe
    {
        return PieceJointe::create([
            'cas_id' => $cas->id,
            'path' => "sav/{$cas->id}/{$nom}",
            'filename' => $nom,
            'mime' => $mime,
            'taille' => 1024,
        ]);
    }

    public function test_un_dossier_complet_est_actionnable(): void
    {
        $cas = $this->dossierComplet();

        $this->assertSame([], RegleCompletude::manquantsBloquants($cas));
        $this->assertTrue($cas->complet);
    }

    /**
     * Chaque exigence BLOQUANTE, retirée une par une, doit suffire à bloquer le
     * dossier. C'est la garantie qu'aucune ne s'est mise à ne servir à rien.
     */
    public function test_chaque_exigence_bloquante_bloque_seule(): void
    {
        $cas = $this->dossierComplet();

        foreach ([
            'Nom et e-mail du client' => ['client_email' => null],
            'Produit et modèle' => ['modele' => null],
            'Numéro de série (MHS)' => ['numero_serie' => null],
            'Photo de l\'étiquette MHS' => ['photo_etiquette' => false],
            'Facture ou Sales Order' => ['sales_order' => null, 'preuve_achat' => false],
            'Description du problème' => ['description' => null],
            'Photos / vidéos du défaut' => ['photos_defaut' => false],
        ] as $libelle => $trou) {
            $ampute = $this->dossierComplet(['reference' => null, ...$trou]);

            $this->assertSame(
                [$libelle],
                RegleCompletude::libellesBloquants($ampute),
                "Retirer « {$libelle} » devrait bloquer le dossier, et lui seul.",
            );
            $this->assertFalse($ampute->complet);
        }

        $this->assertTrue($cas->complet);
    }

    /** Le téléphone, la date d'achat et le contexte manquent — et ça ne bloque rien. */
    public function test_les_exigences_souhaitables_ne_bloquent_pas(): void
    {
        $cas = $this->dossierComplet();

        $souhaitables = array_map(
            fn ($exigence) => $exigence->libelle,
            array_filter(RegleCompletude::manquants($cas), fn ($exigence) => ! $exigence->bloquante),
        );

        $this->assertSame(
            ['Téléphone', 'Date d\'achat', 'Contexte d\'apparition'],
            array_values($souhaitables),
        );
        $this->assertTrue($cas->complet);
    }

    // ---------------------------------------------------------------- Présomptions

    /**
     * Le client donne son MHS et joint une photo : on présume qu'il a photographié
     * l'étiquette. Le dossier bascule en « À valider » — la vue où un humain
     * regarde justement les photos.
     */
    public function test_une_image_jointe_et_un_mhs_presument_la_photo_de_l_etiquette(): void
    {
        $cas = $this->dossierComplet(['photo_etiquette' => null, 'photos_defaut' => null]);

        $this->assertFalse($cas->aPhotoEtiquette());

        $this->joindre($cas, 'image/jpeg', 'etiquette.jpg');

        $this->assertTrue($cas->refresh()->aPhotoEtiquette());
        $this->assertTrue($cas->aPhotosDefaut());
        $this->assertTrue($cas->complet);
    }

    /** Sans MHS, une image ne présume rien : on ne devine pas un numéro de série. */
    public function test_une_image_sans_mhs_ne_presume_pas_l_etiquette(): void
    {
        $cas = $this->dossierComplet(['numero_serie' => null, 'photo_etiquette' => null]);
        $this->joindre($cas, 'image/jpeg');

        $this->assertFalse($cas->refresh()->aPhotoEtiquette());
    }

    public function test_une_video_presume_les_photos_du_defaut_mais_pas_l_etiquette(): void
    {
        $cas = $this->dossierComplet(['photo_etiquette' => null, 'photos_defaut' => null]);
        $this->joindre($cas, 'video/mp4', 'defaut.mp4');

        $cas->refresh();
        $this->assertTrue($cas->aPhotosDefaut());
        $this->assertFalse($cas->aPhotoEtiquette());
    }

    public function test_un_pdf_joint_presume_la_preuve_d_achat(): void
    {
        $cas = $this->dossierComplet(['sales_order' => null, 'preuve_achat' => null]);

        $this->assertFalse($cas->aPreuveAchat());

        $this->joindre($cas, 'application/pdf', 'facture.pdf');

        $this->assertTrue($cas->refresh()->aPreuveAchat());
    }

    /** Un Sales Order suffit : la facture n'est pas obligatoire (« facture OU SO »). */
    public function test_un_sales_order_suffit_comme_preuve_d_achat(): void
    {
        $cas = $this->dossierComplet(['preuve_achat' => null]);

        $this->assertTrue($cas->aPreuveAchat());
    }

    /**
     * « Lisible » est un jugement humain : Nico regarde la photo, la trouve floue,
     * et le dossier retourne aussitôt en « À compléter ».
     */
    public function test_l_humain_peut_infirmer_une_presomption(): void
    {
        $cas = $this->dossierComplet(['photo_etiquette' => null, 'photos_defaut' => null]);
        $this->joindre($cas, 'image/jpeg');
        $cas->refresh();

        $this->assertTrue($cas->complet);

        $cas->update(['photo_etiquette' => false]);

        $this->assertFalse($cas->complet);
        $this->assertSame(['Photo de l\'étiquette MHS'], RegleCompletude::libellesBloquants($cas));
    }

    // ------------------------------------------------------------------- Synchro

    /**
     * `complet` est une colonne, mais jamais une vérité indépendante : elle est
     * recalculée à chaque écriture, et à chaque pièce jointe qui entre ou sort.
     * Sans ça, un dossier resterait coincé dans la mauvaise vue.
     */
    public function test_complet_se_recalcule_quand_une_piece_jointe_entre_ou_sort(): void
    {
        $cas = $this->dossierComplet(['photo_etiquette' => null, 'photos_defaut' => null]);
        $this->assertFalse($cas->refresh()->complet);

        $piece = $this->joindre($cas, 'image/jpeg');
        $this->assertTrue($cas->refresh()->complet);

        $piece->delete();
        $this->assertFalse($cas->refresh()->complet);
    }

    public function test_complet_se_recalcule_a_l_ecriture_du_dossier(): void
    {
        $cas = $this->dossierComplet(['numero_serie' => null]);
        $this->assertFalse($cas->complet);

        $cas->update(['numero_serie' => 'MHS-654321']);

        $this->assertTrue($cas->refresh()->complet);
    }
}

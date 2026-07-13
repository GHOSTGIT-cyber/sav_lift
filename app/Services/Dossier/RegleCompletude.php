<?php

namespace App\Services\Dossier;

use App\Models\Cas;

/**
 * LA règle de complétude. Point unique — décision métier, à faire valider par
 * le patron : la changer, c'est modifier `exigences()` ci-dessous, et rien d'autre.
 *
 * Elle pilote tout le reste :
 *   - le mail automatique au client ne réclame QUE les exigences non satisfaites ;
 *   - la colonne « Ce qui manque » et le bandeau « Prochaine action » les listent ;
 *   - `complet` (colonne de `cas`) vaut « aucune exigence BLOQUANTE ne manque »,
 *     et c'est lui qui fait passer un dossier de la vue « À compléter » à « À valider ».
 *
 * BLOQUANT : le dossier ne part pas chez Lift.
 * SOUHAITABLE : on le demande, mais ça n'empêche rien.
 */
final class RegleCompletude
{
    /**
     * Dans l'ordre où les puces apparaissent dans le mail au client.
     *
     * @return list<Exigence>
     */
    public static function exigences(): array
    {
        return [
            new Exigence(
                cle: 'client',
                libelle: 'Nom et e-mail du client',
                demande: 'vos coordonnées : nom, prénom et adresse e-mail',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => filled($cas->client_nom) && filled($cas->client_email),
            ),
            new Exigence(
                cle: 'telephone',
                libelle: 'Téléphone',
                demande: 'votre numéro de téléphone',
                bloquante: false,
                satisfaite: fn (Cas $cas): bool => filled($cas->client_telephone),
            ),
            new Exigence(
                cle: 'materiel',
                libelle: 'Produit et modèle',
                demande: 'le modèle exact concerné : planche, batterie, eBox, télécommande, mât, moteur, chargeur ou accessoire',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => filled($cas->produit) && filled($cas->modele),
            ),
            new Exigence(
                cle: 'numero_serie',
                libelle: 'Numéro de série (MHS)',
                demande: 'le numéro MHS / numéro de série, situé sur le flanc arrière droit de la planche ou sur l\'étiquette du produit concerné',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => filled($cas->numero_serie),
            ),
            new Exigence(
                cle: 'photo_etiquette',
                libelle: 'Photo de l\'étiquette MHS',
                demande: 'une photo lisible de l\'étiquette du numéro de série',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => $cas->aPhotoEtiquette(),
            ),
            new Exigence(
                cle: 'preuve_achat',
                libelle: 'Facture ou Sales Order',
                demande: 'la facture d\'achat ou le numéro de Sales Order si vous l\'avez',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => $cas->aPreuveAchat(),
            ),
            new Exigence(
                cle: 'date_achat',
                libelle: 'Date d\'achat',
                demande: 'la date d\'achat du matériel',
                bloquante: false,
                satisfaite: fn (Cas $cas): bool => filled($cas->date_achat),
            ),
            new Exigence(
                cle: 'description',
                libelle: 'Description du problème',
                demande: 'une description courte et précise du problème rencontré',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => filled($cas->description),
            ),
            new Exigence(
                cle: 'photos_defaut',
                libelle: 'Photos / vidéos du défaut',
                demande: 'des photos et/ou vidéos montrant clairement le défaut',
                bloquante: true,
                satisfaite: fn (Cas $cas): bool => $cas->aPhotosDefaut(),
            ),
            new Exigence(
                cle: 'contexte',
                libelle: 'Contexte d\'apparition',
                demande: 'le contexte d\'apparition du problème : première utilisation, après une mise à jour, après un choc, après transport, après stockage, après contact avec l\'eau, etc.',
                bloquante: false,
                satisfaite: fn (Cas $cas): bool => filled($cas->contexte),
            ),
        ];
    }

    /**
     * Tout ce qui manque au dossier, bloquant ou non — c'est cette liste que
     * le mail au client réclame.
     *
     * @return list<Exigence>
     */
    public static function manquants(Cas $cas): array
    {
        return array_values(array_filter(
            self::exigences(),
            fn (Exigence $exigence): bool => ! $exigence->satisfaitePar($cas),
        ));
    }

    /**
     * Ce qui manque ET qui interdit d'ouvrir le dossier chez Lift.
     *
     * @return list<Exigence>
     */
    public static function manquantsBloquants(Cas $cas): array
    {
        return array_values(array_filter(
            self::manquants($cas),
            fn (Exigence $exigence): bool => $exigence->bloquante,
        ));
    }

    /** Le dossier peut-il partir chez Lift ? */
    public static function estComplet(Cas $cas): bool
    {
        return self::manquantsBloquants($cas) === [];
    }

    /**
     * Les libellés courts de ce qui manque et qui bloque — pour la table et le
     * bandeau de la fiche.
     *
     * @return list<string>
     */
    public static function libellesBloquants(Cas $cas): array
    {
        return array_map(
            fn (Exigence $exigence): string => $exigence->libelle,
            self::manquantsBloquants($cas),
        );
    }
}

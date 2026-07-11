<?php

namespace App\Services\Ia;

/**
 * Extraction des champs SAV depuis le texte d'un mail.
 *
 * UNE seule classe touche le fournisseur IA (voir CLAUDE.md) : c'est
 * l'implémentation de cette interface. Le reste de l'application ne dépend que
 * du contrat ci-dessous — changer de fournisseur, c'est fournir une autre
 * implémentation, sans toucher à l'ingestion ni aux commandes.
 */
interface MailExtractor
{
    /**
     * Extrait les champs d'un mail, chacun **verbatim ou null** — jamais inventé.
     *
     * @return array{
     *     produit: ?string,
     *     modele: ?string,
     *     mhs: ?string,
     *     sales_order: ?string,
     *     contexte: ?string,
     *     urgent: bool,
     * }
     *
     * @throws ExtractionException si l'appel échoue ou renvoie une réponse inexploitable.
     */
    public function extract(string $contenu): array;
}

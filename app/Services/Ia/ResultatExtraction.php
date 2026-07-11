<?php

namespace App\Services\Ia;

/**
 * Issue d'une tentative d'extraction sur un dossier. Sert au compte rendu des
 * commandes et aux assertions des tests.
 */
enum ResultatExtraction
{
    /** Aucune clé IA configurée : extraction sautée, la relève continue. */
    case Desactivee;

    /** Extraction réussie, les champs du dossier ont été (re)calculés. */
    case Enrichi;

    /** L'appel a échoué ; le dossier porte désormais `extraction_erreur`. */
    case Echec;
}

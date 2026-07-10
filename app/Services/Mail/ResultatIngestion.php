<?php

namespace App\Services\Mail;

/**
 * Ce qu'a fait l'ingesteur d'un mail relevé. Sert au compte rendu de la
 * commande `sav:fetch-mail` et aux assertions des tests.
 */
enum ResultatIngestion
{
    /** Message-ID déjà en base : la relève repasse dessus, on ne fait rien. */
    case Doublon;

    /** Garde anti-boucle : auto-reply, robot, ou la boîte SAV elle-même. */
    case Ignore;

    /** Réponse dans un fil connu : rattachée au dossier, sans accusé. */
    case Rattache;

    /** Nouveau dossier ouvert. */
    case NouveauDossier;
}

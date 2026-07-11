<?php

namespace App\Services\Ia;

use RuntimeException;

/**
 * L'extraction IA a échoué (clé absente, réseau, HTTP non-2xx, réponse
 * inexploitable). Toujours rattrapée par ServiceExtraction : une extraction
 * ratée ne doit jamais bloquer la relève ni la création du dossier.
 */
class ExtractionException extends RuntimeException {}

<?php

namespace App\Services\Ia;

use App\Models\Cas;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Point d'entrée unique de l'extraction, partagé par la relève (IngesteurMail)
 * et les commandes (sav:extract, sav:extract-backfill).
 *
 * Deux garanties :
 *  - **Non bloquant** : une extraction ratée n'interrompt jamais la relève ; on
 *    journalise, on marque le dossier, on continue.
 *  - **Désactivable** : sans clé IA configurée, l'extraction est simplement
 *    sautée. L'outil reste utilisable (dossiers créés, non enrichis).
 */
class ServiceExtraction
{
    public function __construct(private readonly MailExtractor $extractor) {}

    /** L'extraction est-elle branchée ? (clé présente). */
    public function estConfiguree(): bool
    {
        return filled(config('sav.ia.cle'));
    }

    /**
     * Extrait les champs du dossier et les applique. Idempotent : relançable sur
     * un dossier déjà extrait sans effet de bord fâcheux (voir Cas::appliquerExtraction,
     * qui ne réécrit pas un MHS/SO déjà présent).
     */
    public function pourCas(Cas $cas): ResultatExtraction
    {
        if (! $this->estConfiguree()) {
            return ResultatExtraction::Desactivee;
        }

        try {
            $donnees = $this->extractor->extract($cas->contenuPourExtraction());
        } catch (ExtractionException $e) {
            $cas->forceFill([
                'extraction_erreur' => $e->getMessage(),
                'extrait_le' => now(),
            ])->save();

            Log::warning('Extraction IA échouée', [
                'cas' => $cas->reference,
                'erreur' => $e->getMessage(),
            ]);

            return ResultatExtraction::Echec;
        } catch (Throwable $e) {
            // Filet de dernier recours : jamais laisser une exception inattendue
            // remonter dans la relève.
            $cas->forceFill([
                'extraction_erreur' => 'Erreur inattendue : '.$e->getMessage(),
                'extrait_le' => now(),
            ])->save();

            Log::error('Extraction IA : erreur inattendue', [
                'cas' => $cas->reference,
                'exception' => $e->getMessage(),
            ]);

            return ResultatExtraction::Echec;
        }

        $cas->appliquerExtraction($donnees);

        Log::info('Dossier enrichi par extraction IA', [
            'cas' => $cas->reference,
            'produit' => $cas->produit,
            'complet' => $cas->complet,
        ]);

        return ResultatExtraction::Enrichi;
    }
}

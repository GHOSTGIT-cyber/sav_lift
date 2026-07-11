<?php

namespace App\Console\Commands;

use App\Models\Cas;
use App\Services\Ia\ResultatExtraction;
use App\Services\Ia\ServiceExtraction;
use Illuminate\Console\Command;

/**
 * (Ré)extrait un dossier précis. Utile pour rejouer l'IA après avoir corrigé
 * un mail, ou pour déboguer. N'envoie aucun mail.
 */
class ExtractCas extends Command
{
    protected $signature = 'sav:extract {id : ID ou référence du dossier}';

    protected $description = 'Relance l\'extraction IA sur un dossier (aucun envoi).';

    public function handle(ServiceExtraction $extraction): int
    {
        if (! $extraction->estConfiguree()) {
            $this->components->error('Extraction désactivée : aucune clé IA configurée (SAV_IA_CLE / ANTHROPIC_API_KEY).');

            return self::FAILURE;
        }

        $id = (string) $this->argument('id');

        $cas = Cas::query()
            ->where('id', $id)
            ->orWhere('reference', $id)
            ->first();

        if ($cas === null) {
            $this->components->error("Dossier introuvable : {$id}");

            return self::FAILURE;
        }

        $resultat = $extraction->pourCas($cas);
        $cas->refresh();

        return match ($resultat) {
            ResultatExtraction::Enrichi => tap(self::SUCCESS, function () use ($cas) {
                $this->components->info("Dossier {$cas->reference} extrait.");
                $this->components->twoColumnDetail('Produit', $cas->produit ?? '—');
                $this->components->twoColumnDetail('Modèle', $cas->modele ?? '—');
                $this->components->twoColumnDetail('MHS', $cas->numero_serie ?? '—');
                $this->components->twoColumnDetail('Sales Order', $cas->sales_order ?? '—');
                $this->components->twoColumnDetail('Complet', $cas->complet ? 'oui' : 'non');
            }),
            ResultatExtraction::Echec => tap(self::FAILURE, fn () => $this->components->error("Échec : {$cas->extraction_erreur}")),
            ResultatExtraction::Desactivee => self::FAILURE,
        };
    }
}

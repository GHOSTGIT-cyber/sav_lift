<?php

namespace App\Console\Commands;

use App\Models\Cas;
use App\Services\Ia\ExtractionException;
use App\Services\Ia\RedacteurLift;
use Illuminate\Console\Command;

/**
 * Génère (ou régénère) le brouillon d'e-mail Lift d'un dossier. **N'envoie rien** :
 * le brouillon est stocké, à relire puis à copier vers Lift par un humain.
 */
class BrouillonLift extends Command
{
    protected $signature = 'sav:brouillon-lift {id : ID ou référence du dossier}';

    protected $description = 'Génère le brouillon d\'e-mail Lift d\'un dossier (aucun envoi).';

    public function handle(RedacteurLift $redacteur): int
    {
        if (! $redacteur->estConfigure()) {
            $this->components->error('IA non configurée (SAV_IA_CLE).');

            return self::FAILURE;
        }

        $id = (string) $this->argument('id');

        $cas = Cas::query()->where('id', $id)->orWhere('reference', $id)->first();

        if ($cas === null) {
            $this->components->error("Dossier introuvable : {$id}");

            return self::FAILURE;
        }

        try {
            $brouillon = $redacteur->rediger($cas);
        } catch (ExtractionException $e) {
            $this->components->error("Échec de génération : {$e->getMessage()}");

            return self::FAILURE;
        }

        $cas->forceFill([
            'brouillon_lift' => $brouillon,
            'brouillon_lift_le' => now(),
        ])->save();

        $this->components->info("Brouillon Lift généré pour {$cas->reference} (non envoyé).");
        $this->newLine();
        $this->line($brouillon);

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Cas;
use App\Services\Ia\ResultatExtraction;
use App\Services\Ia\ServiceExtraction;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ré-extrait les dossiers déjà en base — typiquement ceux créés avant le Bloc 2,
 * dont le « Produit » est vide.
 *
 * **N'envoie aucun mail** : c'est purement de l'enrichissement de données.
 * Idempotent et sûr à relancer : par défaut on ne retouche que les dossiers pas
 * encore extraits (`--tous` pour tout rejouer), et la fusion ne réécrit pas un
 * champ déjà rempli.
 */
class ExtractBackfill extends Command
{
    protected $signature = 'sav:extract-backfill
                            {--tous : Rejoue aussi les dossiers déjà extraits}
                            {--limit=0 : Nombre maximum de dossiers à traiter (0 = tous)}';

    protected $description = 'Extraction IA en masse sur les dossiers existants (aucun envoi).';

    public function handle(ServiceExtraction $extraction): int
    {
        if (! $extraction->estConfiguree()) {
            $this->components->error('Extraction désactivée : aucune clé IA configurée (SAV_IA_CLE / ANTHROPIC_API_KEY).');

            return self::FAILURE;
        }

        $query = Cas::query()->orderBy('id');

        if (! $this->option('tous')) {
            // Pas encore extrait avec succès (extrait_le null) : le cas d'usage
            // principal, réextraire les dossiers du backlog.
            $query->whereNull('extrait_le');
        }

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $total = $query->count();

        if ($total === 0) {
            $this->components->info('Aucun dossier à extraire.');

            return self::SUCCESS;
        }

        $this->components->info("Extraction de {$total} dossier(s)…");

        $compteurs = ['enrichis' => 0, 'échecs' => 0];
        $barre = $this->output->createProgressBar($total);
        $barre->start();

        // chunkById plutôt que get() : la limite mémoire du conteneur est basse
        // (cf. l'OOM de la relève), on ne charge pas 100 dossiers d'un coup.
        $query->chunkById(20, function ($dossiers) use ($extraction, &$compteurs, $barre) {
            foreach ($dossiers as $cas) {
                try {
                    $resultat = $extraction->pourCas($cas);
                    $compteurs[$resultat === ResultatExtraction::Enrichi ? 'enrichis' : 'échecs']++;
                } catch (Throwable $e) {
                    // pourCas() rattrape déjà tout ; cette garde est ceinture-bretelles.
                    $compteurs['échecs']++;
                }

                $barre->advance();
            }
        });

        $barre->finish();
        $this->newLine(2);

        $this->components->twoColumnDetail('Enrichis', (string) $compteurs['enrichis']);
        $this->components->twoColumnDetail('Échecs', (string) $compteurs['échecs']);

        return self::SUCCESS;
    }
}

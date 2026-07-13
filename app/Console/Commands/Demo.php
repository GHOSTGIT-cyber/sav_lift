<?php

namespace App\Console\Commands;

use App\Models\Cas;
use App\Models\Message;
use App\Models\PieceJointe;
use App\Support\ModeDemo;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remet la démonstration en état. On la montre, on clique partout, on la salit :
 * il faut pouvoir la rendre présentable avant la prochaine démo.
 *
 * Refuse catégoriquement de tourner ailleurs qu'en mode démo — `--reset` efface
 * TOUS les dossiers, et cette commande ne doit jamais pouvoir le faire sur les
 * dossiers réels.
 */
class Demo extends Command
{
    protected $signature = 'sav:demo {--reset : efface tous les dossiers avant de resemer}';

    protected $description = 'Peuple (ou remet à neuf) l\'instance de démonstration.';

    public function handle(): int
    {
        if (! config('sav.demo.actif', false)) {
            $this->components->error('SAV_DEMO n\'est pas activé : cette instance n\'est pas une démo.');

            return self::FAILURE;
        }

        if (! ModeDemo::actif()) {
            $this->components->error('SAV_DEMO=true, mais cette instance touche au monde réel — mode démo refusé :');

            foreach (ModeDemo::empechements() as $empechement) {
                $this->components->bulletList([$empechement]);
            }

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $this->vider();
        }

        $this->callSilent('db:seed', ['--class' => DemoSeeder::class, '--force' => true]);

        $this->components->info(sprintf(
            'Démo prête : %d dossiers, %d messages, %d pièces jointes.',
            Cas::count(),
            Message::count(),
            PieceJointe::count(),
        ));

        return self::SUCCESS;
    }

    /**
     * Efface les dossiers ET les fichiers.
     *
     * L'ordre compte : les pièces jointes d'abord (leurs fichiers vivent sur le
     * disque, un DELETE en base ne les effacerait pas), puis les messages, puis
     * les dossiers.
     */
    private function vider(): void
    {
        Storage::disk('local')->deleteDirectory('sav');

        PieceJointe::query()->delete();
        Message::query()->delete();
        Cas::query()->delete();

        $this->components->info('Démo vidée (dossiers, messages, fichiers).');
    }
}

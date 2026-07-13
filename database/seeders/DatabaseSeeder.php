<?php

namespace Database\Seeders;

use App\Support\ModeDemo;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Joué au démarrage de chaque conteneur (AUTORUN_LARAVEL_MIGRATION_SEED).
     * Tout ce qu'on appelle ici doit donc être idempotent.
     */
    public function run(): void
    {
        $this->call(AdminUserSeeder::class);

        // L'instance de démonstration se repeuple toute seule à chaque déploiement.
        // DemoSeeder se refuse déjà de lui-même hors mode démo ; la garde est ici EN
        // PLUS, pour qu'on n'ait jamais à ouvrir un autre fichier pour se convaincre
        // que la production ne peut pas se retrouver semée de faux clients.
        if (ModeDemo::actif()) {
            $this->call(DemoSeeder::class);
        }
    }
}

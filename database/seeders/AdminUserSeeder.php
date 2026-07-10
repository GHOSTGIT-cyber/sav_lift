<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Crée — ou remet à jour — l'administrateur du panneau à partir de
     * ADMIN_EMAIL / ADMIN_PASSWORD. Idempotent : rejouable à chaque déploiement.
     */
    public function run(): void
    {
        $email = config('sav.admin.email');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => config('sav.admin.nom'),
                'password' => Hash::make(config('sav.admin.password')),
                'email_verified_at' => now(),
            ],
        );

        $this->command?->info("Administrateur prêt : {$email}");
    }
}

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Compte administrateur
    |--------------------------------------------------------------------------
    |
    | Utilisé par Database\Seeders\AdminUserSeeder pour créer — ou remettre à
    | jour — le compte qui ouvre le panneau /admin.
    |
    | On passe par la config plutôt que d'appeler env() dans le seeder : une
    | fois `php artisan config:cache` exécuté (ce que fait le conteneur au
    | démarrage), env() ne lit plus le .env et renverrait toujours les valeurs
    | par défaut.
    |
    */

    'admin' => [
        'nom' => env('ADMIN_NAME', 'Admin SAV'),
        'email' => env('ADMIN_EMAIL', 'admin@liftfoils.fr'),
        'password' => env('ADMIN_PASSWORD', 'password'),
    ],

];

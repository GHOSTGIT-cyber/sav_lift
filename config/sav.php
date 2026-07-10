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

    /*
    |--------------------------------------------------------------------------
    | Boîte SAV
    |--------------------------------------------------------------------------
    |
    | L'adresse relevée par `sav:fetch-mail`. Elle sert aussi de garde-fou : un
    | message dont l'expéditeur est cette adresse est ignoré, sans quoi l'accusé
    | de réception finirait par se répondre à lui-même.
    |
    | Par défaut, c'est l'identifiant IMAP — c'est le cas chez OVH. Mais un
    | identifiant n'est pas toujours une adresse (alias de connexion, Exchange
    | en `DOMAINE\utilisateur`…) : `SAV_MAILBOX` permet alors de les dissocier.
    | Si les deux divergent sans qu'on le dise, le garde-fou anti-boucle ne
    | reconnaît plus la boîte et l'outil peut se répondre à lui-même.
    |
    */

    'mailbox' => env('SAV_MAILBOX', env('IMAP_USERNAME', 'sav@liftfoils.fr')),

    'imap' => [

        'dossier' => env('SAV_IMAP_FOLDER', 'INBOX'),

        // Fenêtre de relève, en jours. La déduplication se fait sur le
        // Message-ID et non sur le flag \Seen : des humains lisent la même
        // boîte et marquent les mails comme lus. On repasse donc volontairement
        // sur des messages déjà traités, et cette fenêtre borne ce recouvrement.
        'jours' => (int) env('SAV_FETCH_DAYS', 14),

    ],

    /*
    |--------------------------------------------------------------------------
    | Pièces jointes
    |--------------------------------------------------------------------------
    |
    | Les clients envoient des vidéos du défaut. Au-delà de cette taille, la
    | pièce est ignorée — et journalisée — plutôt qu'écrite sur le disque.
    |
    */

    'max_attachment_mb' => (int) env('SAV_MAX_ATTACHMENT_MB', 40),

    /*
    |--------------------------------------------------------------------------
    | Taille maximale d'un message relevé
    |--------------------------------------------------------------------------
    |
    | Le vrai garde-fou contre l'OOM. La librairie IMAP décode le corps entier
    | — pièces jointes comprises — en mémoire dès qu'on le parse : filtrer les
    | pièces jointes après coup n'y change rien. On interroge donc d'abord la
    | taille RFC822 annoncée par le serveur, et on laisse le message dans la
    | boîte (non lu, journalisé) s'il dépasse cette limite.
    |
    | À garder sous le `memory_limit` de PHP, avec une marge : le message est
    | chargé encodé (base64, +33 %) puis décodé.
    |
    */

    'max_message_mb' => (int) env('SAV_MAX_MESSAGE_MB', 60),

    /*
    |--------------------------------------------------------------------------
    | Expéditeurs à qui l'on n'accuse jamais réception
    |--------------------------------------------------------------------------
    |
    | Lift et son Zendesk nous écrivent : leurs mails alimentent bien un
    | dossier, mais leur envoyer un accusé destiné à un client n'aurait aucun
    | sens — et risquerait d'ouvrir un ticket chez eux.
    |
    | Comparé en sous-chaîne sur l'adresse de l'expéditeur, en minuscules.
    |
    */

    'expediteurs_partenaires' => [
        'liftfoils.com',
        'zendesk.com',
        'zendesk.fr',
    ],

];

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

    /*
    |--------------------------------------------------------------------------
    | Interrupteur général d'envoi (Bloc 3-B)
    |--------------------------------------------------------------------------
    |
    | Cran de sûreté au-dessus de tout envoi sortant (accusé de réception,
    | demande d'infos, brouillon Lift). À `false`, RIEN ne part : l'envoi est
    | simulé et journalisé. Contrôlé en un seul point (App\Services\Mail\Expediteur).
    |
    | Par défaut `false` : on n'envoie jamais tant que ce n'est pas activé
    | explicitement, feu vert humain donné.
    |
    */

    'envoi_actif' => (bool) env('SAV_ENVOI_ACTIF', false),

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

    /*
    |--------------------------------------------------------------------------
    | Extraction IA (Bloc 2)
    |--------------------------------------------------------------------------
    |
    | La couche IA lit un mail SAV et en extrait produit / modèle / MHS / Sales
    | Order / contexte / urgence, en **verbatim ou null** — jamais inventé.
    | Isolée derrière App\Services\Ia\MailExtractor (point de bascule fournisseur).
    |
    | Fournisseur : n'importe quelle API **compatible OpenAI** (chat/completions).
    | Par défaut OpenRouter (offre des modèles gratuits) ; pour xAI Grok en direct,
    | mettre SAV_IA_URL=https://api.x.ai/v1/chat/completions et SAV_IA_MODELE=grok-….
    |
    | La clé n'est lue QUE via cette config, jamais en dur dans le code. Sans clé,
    | l'extraction est désactivée : la relève crée les dossiers sans les enrichir.
    |
    */

    'ia' => [

        'cle' => env('SAV_IA_CLE', env('OPENROUTER_API_KEY', env('GROQ_API_KEY'))),

        // Tout fournisseur parlant « chat/completions » convient. Repli tout prêt
        // si le gratuit d'OpenRouter se tarit — Groq, gratuit et généreux :
        //   SAV_IA_URL=https://api.groq.com/openai/v1/chat/completions
        //   SAV_IA_MODELE=llama-3.3-70b-versatile
        // Basculer = changer ces deux variables d'env + la clé. Zéro code.
        'url' => env('SAV_IA_URL', 'https://openrouter.ai/api/v1/chat/completions'),

        // Modèle **gratuit** (0 $ en entrée comme en sortie), comparé sur un vrai
        // mail SAV français : Gemma est le seul à tout extraire juste (catégorie
        // produit ET modèle ET MHS verbatim). Les autres confondent produit et
        // modèle — ce qui n'est plus anodin depuis que l'accusé de réception
        // réclame au client ce que l'extraction n'a pas trouvé.
        //
        // Écartés, mesurés : qwen3-next (429, saturé chez le fournisseur),
        // gpt-oss-20b et nemotron-nano (produit = « Lift4 »), nemotron-3-super
        // (sort du charabia). ⚠️ Les « :free » vont et viennent : en cas de 404
        // ou de 429 persistant, relister via https://openrouter.ai/api/v1/models.
        'modele' => env('SAV_IA_MODELE', 'google/gemma-4-26b-a4b-it:free'),

        'timeout' => (int) env('SAV_IA_TIMEOUT', 30),

        'max_tokens' => (int) env('SAV_IA_MAX_TOKENS', 1024),

        // Force une sortie JSON valide côté fournisseur (response_format). Débrayable
        // si un modèle gratuit ne le supporte pas : le prompt demande déjà du JSON
        // et le parsing est tolérant (fences Markdown, prose autour).
        'json_mode' => (bool) env('SAV_IA_JSON_MODE', true),

        // Attribution facultative envoyée à OpenRouter (en-têtes Referer/Title).
        'app_url' => env('SAV_IA_APP_URL', env('APP_URL', 'https://sav.efoilcotedazur.fr')),
        'app_titre' => env('SAV_IA_APP_TITRE', 'SAV Lift Foils'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lift Foils (Bloc 3-C)
    |--------------------------------------------------------------------------
    |
    | Destinataire des brouillons SAV et vocabulaire maison, injecté dans le
    | prompt du rédacteur. Les « Issue Types » Zendesk de Lift sont configurables
    | (liste séparée par des virgules) — laissés vides tant que Lift ne les fournit
    | pas ; le brouillon décrit alors le problème librement.
    |
    */

    'lift' => [
        'email' => env('SAV_LIFT_EMAIL', 'help@liftfoils.com'),
        'issue_types' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SAV_LIFT_ISSUE_TYPES', '')),
        ))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Portail Zendesk de Lift (Bloc 3-D — repli)
    |--------------------------------------------------------------------------
    |
    | La sync auto des statuts a été testée (Bloc 3-D) : l'auth mot de passe sur
    | l'API requester est fermée côté Lift (401). On reste donc en repli : les
    | mails de notification Zendesk se rattachent aux dossiers (Bloc 1), le n° de
    | ticket est saisi à la main, et on offre un lien profond vers le portail.
    |
    */

    'zendesk' => [
        'portail_url' => env('SAV_ZENDESK_PORTAIL', 'https://liftsupport.zendesk.com'),
        // Passera à true le jour où Lift fournit un token API (sync en lecture seule).
        'sync_active' => (bool) env('SAV_ZENDESK_SYNC', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mode démonstration (Bloc 4-bis)
    |--------------------------------------------------------------------------
    |
    | Une instance publique, SANS MOT DE PASSE, peuplée de dossiers fictifs, pour
    | montrer l'outil sans donner d'accès à la production.
    |
    | ⚠️ Ce drapeau ne suffit pas à lui seul, et c'est délibéré : App\Support\ModeDemo
    | exige EN PLUS que l'instance soit incapable de toucher au monde réel (aucun
    | mot de passe IMAP, aucun envoi possible). Sans quoi le mode démo se refuse.
    | Le même code tourne en prod : une faute de frappe dans les variables d'env ne
    | doit jamais y ouvrir le panneau à tout Internet.
    |
    | Ne JAMAIS mettre SAV_DEMO=true sur l'app de production.
    |
    */

    'demo' => [

        'actif' => (bool) env('SAV_DEMO', false),

        // Le compte sous lequel le visiteur est connecté d'office. Créé par
        // Database\Seeders\DemoSeeder ; sans mot de passe utilisable.
        'email' => env('SAV_DEMO_EMAIL', 'demo@liftfoils.fr'),
    ],

];

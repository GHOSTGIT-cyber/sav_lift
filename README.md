# Outil SAV — Lift Foils France

Outil interne de gestion du SAV eFoils. Chaque demande client devient un **dossier** (`Cas`), suivi jusqu'à résolution.
Voir [CLAUDE.md](CLAUDE.md) pour le contexte produit et le plan par blocs.

**État : Bloc 1 — mail → dossier.** La boîte `sav@` est relevée en IMAP : chaque
nouveau mail ouvre un dossier, ses pièces jointes sont stockées, et le client
reçoit un accusé de réception. Les réponses se rattachent au bon dossier.
Pas encore d'IA, ni de brouillon vers Lift (blocs suivants).

## Stack

| | |
|---|---|
| Framework | Laravel 13 (PHP ≥ 8.3) |
| Admin | Filament 5, panneau sur `/admin` |
| Base | SQLite |
| Hébergement | Coolify (Docker), image `serversideup/php:8.4-frankenphp` |

## Démarrer en local

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Puis `http://localhost:8000/admin`, avec les identifiants de `.env`
(`ADMIN_EMAIL` / `ADMIN_PASSWORD` — par défaut `admin@liftfoils.fr` / `password`).

Aucun `npm install` n'est nécessaire : Filament livre ses assets précompilés,
publiés dans `public/` par `composer install`.

### Tests

```bash
php artisan test      # 98 tests
vendor/bin/pint       # style de code
```

## La relève des mails

```bash
php artisan sav:fetch-mail            # fenêtre par défaut : 14 jours
php artisan sav:fetch-mail --jours=1  # pour un essai rapide
```

La commande est **idempotente** : on peut la relancer autant qu'on veut, et
l'interrompre en plein vol. Le planificateur l'exécute toutes les deux minutes
(`routes/console.php`).

Pour chaque mail relevé :

1. **Déduplication** sur le `Message-ID`, jamais sur le flag `\Seen` — des
   humains lisent la même boîte et marquent les mails comme lus.
2. **Gardes anti-boucle.** Sont ignorés : la boîte `sav@` elle-même, les
   expéditeurs `noreply` / `no-reply` / `mailer-daemon` / `postmaster`, les
   en-têtes `Auto-Submitted` (≠ `no`) et `Precedence: bulk|list|auto_reply`,
   et les sujets d'auto-réponse. Sans elles, l'accusé de réception et
   l'auto-répondeur d'en face se répondraient à l'infini.
3. **Rattachement au fil** via `In-Reply-To` puis `References` : une réponse
   n'ouvre jamais un second dossier.
4. Sinon, **ouverture d'un dossier** `SAV-{année}-{0001}` + envoi de l'accusé
   de réception, seul mail que l'outil expédie sans validation humaine.

Un mail qui plante est journalisé et n'interrompt pas la relève.

### Ce qui n'est pas encore couvert

- **Un accusé perdu ne repart pas.** Si le SMTP est en panne au moment de
  l'envoi, le dossier est bien créé et l'échec journalisé en `error`, mais rien
  ne réessaie : l'envoi est en ligne, pas en file d'attente. La queue arrive au
  Bloc 2, quand l'appel à l'IA rendra l'asynchrone utile.
- **Un message de plus de `SAV_MAX_MESSAGE_MB` reste dans la boîte**, non lu et
  journalisé en `warning`. C'est le garde-fou mémoire : la librairie IMAP décode
  le corps entier d'un coup, et un dépassement de `memory_limit` est une erreur
  fatale — elle tuerait la relève, pas seulement ce mail-là.
- Le texte de l'accusé vit dans
  [`resources/views/mail/accuse-reception.blade.php`](resources/views/mail/accuse-reception.blade.php) :
  il se retouche sans toucher au code.

## Déploiement Coolify

Le [`Dockerfile`](Dockerfile) est à la racine. Coolify le détecte tout seul.

### Le piège du port → « Bad Gateway »

Dans Coolify, **Configuration → Network → `Ports Exposes` = `8080`**
(laisser `Ports Mappings` vide). La valeur par défaut est `3000` : tant qu'elle
y reste, le proxy tape dans le vide et l'on obtient un **502 Bad Gateway**.

L'image expose trois ports, mais un seul répond en HTTP :

| Port | |
|---|---|
| `2019` | admin Caddy, **désactivé** → connexion refusée |
| `8080` | **HTTP — c'est celui-ci** |
| `8443` | HTTPS, inactif tant que `SSL_MODE` est off → connexion refusée |

FrankenPHP tourne en non-root : il n'écoute donc jamais sur le 80.

Au démarrage du conteneur, les *automations* serversideup exécutent
`storage:link`, puis `migrate --force --seed` (ce qui crée ou met à jour
l'administrateur), puis `optimize`. Les logs doivent se terminer par
`FrankenPHP started 🐘 … addr: :8080`.

### Variables d'environnement à définir dans Coolify

Rien de tout ça n'est dans le dépôt : `.env` est volontairement ignoré par git.

| Variable | Valeur | Note |
|---|---|---|
| `APP_KEY` | `base64:…` | **obligatoire** — sans elle, l'app renvoie 500. Générer avec `php artisan key:generate --show` |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://sav.efoilcotedazur.fr` | l'URL publique réelle : sert aux redirections de connexion |
| `APP_LOCALE` | `fr` | |
| `ADMIN_NAME` | `Admin SAV` | |
| `ADMIN_EMAIL` | *votre e-mail* | identifiant de connexion à `/admin` |
| `ADMIN_PASSWORD` | *un vrai mot de passe* | réappliqué à chaque déploiement |
| `DB_CONNECTION` | `sqlite` | |
| `DB_DATABASE` | `/var/www/html/storage/app/database.sqlite` | **chemin absolu, sur le volume** (voir ci-dessous) |
| `FILESYSTEM_DISK` | `local` | |
| `SESSION_SECURE_COOKIE` | `true` | recommandé : le cookie de session ne partira jamais en clair |

Plus, pour le Bloc 1, la boîte mail :

| Variable | Valeur | Note |
|---|---|---|
| `IMAP_HOST` | `ssl0.ovh.net` | à confirmer dans le panneau OVH — diffère en Email Pro/Exchange |
| `IMAP_PORT` | `993` | |
| `IMAP_ENCRYPTION` | `ssl` | |
| `IMAP_USERNAME` | `sav@liftfoils.fr` | sert aussi de garde-fou anti-boucle |
| `IMAP_PASSWORD` | *le mot de passe de la boîte* | |
| `IMAP_PROTOCOL` | `imap` | |
| `IMAP_DEFAULT_ACCOUNT` | `default` | |
| `MAIL_MAILER` | `smtp` | |
| `MAIL_HOST` | `ssl0.ovh.net` | idem : à confirmer côté OVH |
| `MAIL_PORT` | `465` | |
| `MAIL_ENCRYPTION` | `ssl` | |
| `MAIL_USERNAME` | `sav@liftfoils.fr` | |
| `MAIL_PASSWORD` | *le mot de passe de la boîte* | |
| `MAIL_FROM_ADDRESS` | `sav@liftfoils.fr` | |
| `MAIL_FROM_NAME` | `SAV Lift Foils France` | |

`ADMIN_EMAIL` / `ADMIN_PASSWORD` sont relus à **chaque** démarrage : changer la
variable puis redéployer suffit à changer le mot de passe.

### Le volume persistant (à faire au déploiement du Bloc 1)

1. Monter un volume sur **`/var/www/html/storage/app`** — il contiendra le
   SQLite **et** les pièces jointes.
   ⚠️ Sur `storage/app`, **pas** sur `storage` entier : sinon `framework/`
   disparaît et l'app ne démarre plus.
2. Créer le fichier une fois (onglet *Command*) :
   `touch /var/www/html/storage/app/database.sqlite`
   Si c'est un *permission denied* : le volume neuf appartient à `root`, alors
   que l'image tourne en `www-data`. Depuis un shell root du conteneur,
   `chown -R www-data:www-data /var/www/html/storage/app`.
3. Pointer `DB_DATABASE` dessus (tableau ci-dessus), et lancer les migrations
   en *post-deployment command* : `php artisan migrate --force`.

### Le conteneur planificateur

La relève IMAP tourne dans une **deuxième ressource Coolify** : même dépôt,
même image, **même volume monté**, mêmes variables d'environnement, et

- **start command** : `php artisan schedule:work`
- **aucun domaine public**

Sans elle, `sav:fetch-mail` ne s'exécute jamais. Le conteneur web ne planifie
rien : c'est volontaire, deux relèves concurrentes ouvriraient des doublons.

### Le piège du HTTPS → « la page s'affiche mais la connexion ne fait rien »

Le proxy termine le TLS et parle en clair au conteneur, en annonçant
`X-Forwarded-Proto: https`. Sans `trustProxies()` (voir
[`bootstrap/app.php`](bootstrap/app.php)), Laravel se croit en clair et génère
des `<script src="http://…">` dans une page servie en `https://` : le
navigateur les bloque (*mixed content*), Livewire ne démarre jamais, et le
bouton « Connexion » reste inerte — sans le moindre message d'erreur.

C'est verrouillé par [`tests/Feature/ProxyHttpsTest.php`](tests/Feature/ProxyHttpsTest.php).

### ⚠️ Sans volume monté, la base est éphémère

Le `Dockerfile` crée `database/database.sqlite` au build : ce fichier-là est
perdu à chaque redéploiement. Tant que le volume et `DB_DATABASE` ne sont pas
en place (voir plus haut), **les dossiers SAV et les pièces jointes
disparaissent au déploiement suivant**.

## Données clients et RGPD

Les pièces jointes vivent sur le disque `local` (`storage/app/private`), qui
n'expose **aucune URL publique**. Elles ne sortent que par
[`PieceJointeController`](app/Http/Controllers/PieceJointeController.php),
derrière l'authentification du panneau. L'aperçu inline est limité à une liste
blanche de formats bitmap : un SVG ou un HTML servi inline exécuterait le code
d'un inconnu dans la session du technicien.

## Repères dans le code

| Quoi | Où |
|---|---|
| Le dossier SAV | [`app/Models/Cas.php`](app/Models/Cas.php) |
| Les statuts (libellés + couleurs FR) | [`app/Enums/StatutCas.php`](app/Enums/StatutCas.php) |
| Relève IMAP | [`app/Console/Commands/FetchMail.php`](app/Console/Commands/FetchMail.php) |
| Dédup, gardes anti-boucle, threading | [`app/Services/Mail/IngesteurMail.php`](app/Services/Mail/IngesteurMail.php) |
| Lecture d'un mail (en-têtes, corps, PJ) | [`app/Services/Mail/MailEntrant.php`](app/Services/Mail/MailEntrant.php) |
| Texte de l'accusé de réception | [`resources/views/mail/accuse-reception.blade.php`](resources/views/mail/accuse-reception.blade.php) |
| Écran d'administration | [`app/Filament/Resources/Cas/`](app/Filament/Resources/Cas/) |
| Panneau Filament | [`app/Providers/Filament/AdminPanelProvider.php`](app/Providers/Filament/AdminPanelProvider.php) |
| Compte admin, réglages de la relève | [`config/sav.php`](config/sav.php) |

# Outil SAV — Lift Foils France

Outil interne de gestion du SAV eFoils. Chaque demande client devient un **dossier** (`Cas`), suivi jusqu'à résolution.
Voir [CLAUDE.md](CLAUDE.md) pour le contexte produit et le plan par blocs.

**État : Bloc 0 — squelette.** Laravel + Filament + table `cas` + déploiement Coolify.
Pas encore d'IMAP, ni d'IA, ni d'envoi de mail (blocs suivants).

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
php artisan test      # 20 tests
vendor/bin/pint       # style de code
```

## Déploiement Coolify

Le [`Dockerfile`](Dockerfile) est à la racine. Coolify le détecte tout seul.

**Port à exposer : `8080`** (FrankenPHP tourne en non-root, pas sur le 80).

Au démarrage du conteneur, les *automations* serversideup exécutent
`storage:link`, puis `migrate --force --seed` (ce qui crée ou met à jour
l'administrateur), puis `optimize`.

### Variables d'environnement à définir dans Coolify

Rien de tout ça n'est dans le dépôt : `.env` est volontairement ignoré par git.

| Variable | Valeur | Note |
|---|---|---|
| `APP_KEY` | `base64:…` | **obligatoire** — générer avec `php artisan key:generate --show` |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | |
| `APP_URL` | `https://sav.liftfoils.fr` | l'URL publique réelle |
| `APP_LOCALE` | `fr` | |
| `ADMIN_NAME` | `Admin SAV` | |
| `ADMIN_EMAIL` | *votre e-mail* | identifiant de connexion à `/admin` |
| `ADMIN_PASSWORD` | *un vrai mot de passe* | réappliqué à chaque déploiement |
| `DB_CONNECTION` | `sqlite` | |

`ADMIN_EMAIL` / `ADMIN_PASSWORD` sont relus à **chaque** démarrage : changer la
variable puis redéployer suffit à changer le mot de passe.

### ⚠️ La base est éphémère en Bloc 0

`database/database.sqlite` est créé dans l'image au build. **Il est perdu à
chaque redéploiement.** C'est volontaire : le Bloc 0 ne sert qu'à valider le
boot. Le Bloc 1 déplacera la base (et les pièces jointes) sur un volume
persistant Coolify, via `DB_DATABASE` en chemin absolu.

## Repères dans le code

| Quoi | Où |
|---|---|
| Le dossier SAV | [`app/Models/Cas.php`](app/Models/Cas.php) |
| Les statuts (libellés + couleurs FR) | [`app/Enums/StatutCas.php`](app/Enums/StatutCas.php) |
| Écran d'administration | [`app/Filament/Resources/Cas/`](app/Filament/Resources/Cas/) |
| Panneau Filament | [`app/Providers/Filament/AdminPanelProvider.php`](app/Providers/Filament/AdminPanelProvider.php) |
| Compte admin | [`config/sav.php`](config/sav.php) + [`database/seeders/AdminUserSeeder.php`](database/seeders/AdminUserSeeder.php) |

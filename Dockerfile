# Outil SAV Lift Foils — image Coolify (Laravel + Filament)
FROM serversideup/php:8.4-frankenphp

# Extensions PHP : PostgreSQL (prod) + i18n + calculs + images.
# pdo_sqlite reste installé : la suite de tests tourne sur SQLite in-memory.
# (pdo_sqlite/sqlite3 et pdo_pgsql sont déjà présents dans l'image ; on liste
# pgsql explicitement pour documenter l'intention et rester robuste si l'image
# de base change. intl, bcmath et exif, eux, ne sont pas fournis.)
USER root
RUN install-php-extensions intl bcmath pdo_sqlite pdo_pgsql pgsql exif
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# Dépendances PHP. Le script post-autoload-dump `filament:upgrade` publie les
# assets de Filament dans public/ : aucun build npm n'est nécessaire.
RUN composer install --no-interaction --no-progress --optimize-autoloader --no-dev

# La base est une ressource PostgreSQL managée par Coolify : rien à créer dans
# l'image. Le schéma est appliqué au démarrage par l'automation ci-dessous.

# Automations serversideup, au démarrage du conteneur :
#   php artisan storage:link
#   php artisan migrate --force --seed   ← AdminUserSeeder, idempotent
#   php artisan optimize
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION_SEED=true

# FrankenPHP tourne en non-root et écoute sur 8080 (pas 80).
#
# L'image de base expose aussi 2019 (admin Caddy, désactivé) et 8443 (HTTPS,
# inactif tant que SSL_MODE est off) : ces deux-là refusent les connexions.
# Coolify → Configuration → Network → « Ports Exposes » DOIT valoir 8080,
# sinon le proxy renvoie 502 Bad Gateway.
EXPOSE 8080

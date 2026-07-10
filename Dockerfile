# Outil SAV Lift Foils — image Coolify (Laravel + Filament)
FROM serversideup/php:8.4-frankenphp

# Extensions PHP : SQLite + i18n + calculs + images.
# (pdo_sqlite et sqlite3 sont déjà dans l'image ; intl, bcmath et exif non.)
USER root
RUN install-php-extensions intl bcmath pdo_sqlite exif
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# Dépendances PHP. Le script post-autoload-dump `filament:upgrade` publie les
# assets de Filament dans public/ : aucun build npm n'est nécessaire.
RUN composer install --no-interaction --no-progress --optimize-autoloader --no-dev

# Fichier SQLite — ÉPHÉMÈRE en Bloc 0 (juste pour valider le boot).
# En Bloc 1 on le déplacera sur un volume persistant Coolify.
RUN mkdir -p database && touch database/database.sqlite

# Automations serversideup, au démarrage du conteneur :
#   php artisan storage:link
#   php artisan migrate --force --seed   ← AdminUserSeeder, idempotent
#   php artisan optimize
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_MIGRATION_SEED=true

# FrankenPHP tourne en non-root et écoute sur 8080 (pas 80) : c'est ce port
# qu'il faut viser côté Coolify.
EXPOSE 8080

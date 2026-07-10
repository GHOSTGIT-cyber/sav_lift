# Outil SAV Lift Foils — image Coolify (Laravel + Filament)
# → renomme ce fichier en "Dockerfile" à la racine du repo Laravel.
FROM serversideup/php:8.4-frankenphp

# Extensions PHP : SQLite + i18n + calculs + images
USER root
RUN install-php-extensions intl bcmath pdo_sqlite exif
USER www-data

WORKDIR /var/www/html
COPY --chown=www-data:www-data . .

# Dépendances PHP (les scripts post-install de Filament publient ses assets)
RUN composer install --no-interaction --optimize-autoloader --no-dev

# Fichier SQLite — ÉPHÉMÈRE en Bloc 0 (juste pour valider le boot).
# En Bloc 1 on le déplacera sur un volume persistant Coolify.
RUN mkdir -p database && touch database/database.sqlite

# Migrations lancées automatiquement au démarrage du conteneur (serversideup)
ENV AUTORUN_ENABLED=true

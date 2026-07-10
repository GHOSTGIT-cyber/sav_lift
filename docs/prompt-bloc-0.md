# Prompt Claude Code — Bloc 0 (squelette)

Tu implémentes le **Bloc 0** de l'outil SAV décrit dans `CLAUDE.md`. Objectif : un projet **Laravel + Filament** qui démarre, avec un login admin et une ressource **« Cas »** (dossiers SAV) vide mais fonctionnelle, prêt à déployer sur Coolify. **Rien de métier automatisé à ce stade** (pas d'IMAP, pas d'IA, pas d'envoi de mail) — c'est le montage.

## Étapes

1. **Projet Laravel** neuf (dernière version stable) dans le dossier courant. Base **SQLite** (config par défaut).

2. **Filament** (panneau admin) : `composer require filament/filament` puis `php artisan filament:install --panels`. Panel sur le chemin `/admin`.

3. **Admin reproductible** : un seeder `AdminUserSeeder` qui crée (ou met à jour) un utilisateur à partir des variables d'env `ADMIN_EMAIL` et `ADMIN_PASSWORD` (avec des valeurs de dev par défaut si absentes). L'appeler depuis `DatabaseSeeder`.

4. **Modèle + migration `Cas`** avec les champs :
   - `reference` (string, unique, nullable)
   - `client_nom` (string, nullable), `client_email` (string, nullable), `client_telephone` (string, nullable)
   - `produit` (string, nullable) — catégorie produit
   - `modele` (string, nullable)
   - `numero_serie` (string, nullable) — le MHS
   - `sales_order` (string, nullable)
   - `description` (text, nullable)
   - `statut` (string, default `nouveau`)
   - `ticket_lift` (string, nullable), `tracking` (string, nullable)
   - `source` (string, default `email`)
   - timestamps

   Enum PHP `StatutCas` : `nouveau, attente_client, envoye_lift, attente_lift, atelier, pret, clos`. Le champ `statut` est casté vers cet enum, avec des libellés FR.

5. **Ressource Filament `CasResource`** :
   - **Formulaire** : tous les champs ci-dessus (`statut` = Select avec les valeurs de l'enum, libellés FR).
   - **Table** : `reference`, `client_nom`, `produit`, `statut` (badge coloré), `created_at`. Filtre par statut. Tri `created_at` desc.
   - Libellés en français ("Dossiers SAV" au pluriel, "Dossier" au singulier).

6. **Localisation** : `APP_LOCALE=fr`. (Pas besoin de traduire tout Filament pour le Bloc 0, juste les libellés de la ressource.)

7. **Déploiement** : ajoute à la racine le `Dockerfile` fourni (image serversideup/php frankenphp). Vérifie qu'il build localement si Docker est dispo.

8. **Vérifs finales** (fais-les tourner) :
   - `php artisan migrate --seed` sans erreur.
   - `php artisan serve` → login sur `/admin` avec `ADMIN_EMAIL` / `ADMIN_PASSWORD`.
   - Créer un Cas à la main dans l'admin → il apparaît bien dans la table.

## Contraintes
- **Français** pour tout ce qui est visible.
- **Pas** d'IMAP, d'IA, ni d'envoi de mail dans ce bloc (blocs suivants).
- Pas de dépendances superflues.
- Un commit propre, message clair (ex. `bloc 0 : squelette Laravel + Filament + ressource Cas`).
- Quand c'est fini, initialise un dépôt Git et pousse-le (je le brancherai à Coolify).

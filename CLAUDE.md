# CLAUDE.md — Outil SAV Lift Foils France

## Contexte
Outil **interne** de gestion SAV pour un distributeur/réparateur **Lift Foils** (eFoils) en France. Les demandes clients (batteries, télécommandes, eBox/ESC, moteurs, mâts, chargeurs, planches, foils…) arrivent par mail sur **sav@liftfoils.fr**. L'outil centralise chaque demande en **« dossier » (Cas)**, automatise les réponses, et suit le dossier jusqu'à résolution.
Équipe : petite (le patron + sa famille), saisonnière. Volume **faible** (quelques dizaines de dossiers/mois).

## Stack (décidé, ne pas dévier)
- **Laravel** (dernière version stable) + **Filament** (panneau admin) pour toute l'UI interne.
- Base : **SQLite** (volume faible).
- Hébergement : **Coolify** (Docker), image **serversideup/php** (FrankenPHP). Repo Git.
- **IA** : appelée via une **API HTTP en ligne** (fournisseur configurable), isolée dans **UNE seule classe** (`MailExtractor`). **Aucun modèle auto-hébergé.**
- Langue : UI et réponses clients en **français**. Le brouillon vers Lift est en **anglais**.

## Règles produit (non négociables)
- **Lift = mail uniquement.** Pas d'API vers leur Zendesk. On envoie un mail carré à help@liftfoils.com ; leurs réponses reviennent en mail sur sav@ et se rattachent au dossier.
- **Human-in-the-loop** : rien ne part vers Lift ni vers le client sans validation humaine — **sauf** l'accusé de réception auto. Les brouillons restent des brouillons.
- **Ne jamais inventer** un numéro de série (MHS) ou un Sales Order : extraction **verbatim ou null**.
- **Ne jamais décider automatiquement de la garantie** : l'outil signale les indices (date d'achat, contexte choc/eau/transport), l'humain tranche.

## Modèle de données (cible, construit par blocs)
Entité centrale : **Cas** (dossier SAV). Relations à venir : **PieceJointe**, puis **Piece**, **Devis**.

## Plan par blocs (une conversation par bloc)
- **Bloc 0** — squelette : Laravel + Filament + table `cas` + déploiement Coolify. ← *en cours*
- **Bloc 1** — relève **IMAP** de sav@ → création de cas + pièces jointes + accusé de réception auto.
- **Bloc 2** — **extraction IA** (`MailExtractor`) + statut complet/incomplet + mail de demande d'infos manquantes.
- **Bloc 3** — **brouillon email Lift (EN)** + champs de suivi (ticket Lift, SO, tracking) + gestion des états.
- **Bloc 4** — **tableau de bord** Filament + génération **devis « contrôle annuel 500 € »** depuis la grille de tarifs (catalogue intervention).

## Conventions techniques
- Statuts `Cas` : `nouveau, attente_client, envoye_lift, attente_lift, atelier, pret, clos` (+ `urgent` en tag/booléen).
- Tâches de fond (IMAP, envois) via **scheduler + queue Laravel**, lancées en **conteneurs séparés** sur Coolify (à partir du Bloc 1).
- Migrations **rétro-compatibles** (une par bloc), jamais de refonte destructive.
- Secrets (IMAP, clé API IA) **uniquement en variables d'env Coolify**, jamais dans le code.
- Stockage des pièces jointes sur un **volume persistant** Coolify (Bloc 1).

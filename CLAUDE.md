# CLAUDE.md — Outil SAV Lift Foils France

## Contexte
Outil **interne** de gestion SAV pour un distributeur/réparateur **Lift Foils** (eFoils) en France. Les demandes clients (batteries, télécommandes, eBox/ESC, moteurs, mâts, chargeurs, planches, foils…) arrivent par mail sur **sav@liftfoils.fr**. L'outil centralise chaque demande en **« dossier » (Cas)**, automatise les réponses, et suit le dossier jusqu'à résolution.
Équipe : petite (le patron + sa famille), saisonnière. Volume **faible** (quelques dizaines de dossiers/mois).

## Stack (décidé, ne pas dévier)
- **Laravel** (dernière version stable) + **Filament** (panneau admin) pour toute l'UI interne.
- Base : **PostgreSQL managé par Coolify** (ressource dédiée). Les pièces jointes vivent sur le disque `local` (`storage/app/private`), rendu persistant et partagé entre conteneurs par un **bind mount Coolify sur `storage/app`** (géré côté infra, pas dans le code).
- Hébergement : **Coolify** (Docker), image **serversideup/php** (FrankenPHP). Repo Git.
- **IA** : appelée via une **API HTTP en ligne** (fournisseur configurable), isolée dans **UNE seule classe** (`MailExtractor`). **Aucun modèle auto-hébergé.**
- Langue : UI et réponses clients en **français**. Le brouillon vers Lift est en **anglais**.

## Règles produit (non négociables)
- **Lift = principalement mail, + un portail Zendesk requester.** On ouvre les dossiers par mail carré à help@liftfoils.com (**jamais** d'écriture auto dans leur Zendesk). Côté lecture : il existe un **portail requester** (liftsupport.zendesk.com, statuts visibles) ET une **API Requests end-user** (`/api/v2/requests`, `/open`, `/solved`) qui liste *nos propres* tickets → **sync auto des statuts possible SI on obtient une auth serveur** (email+mot de passe si l'instance l'autorise, ou token fourni par Lift ; pas d'accès agent) — **à tester en Bloc 3**. Repli si bloqué : les mails de notification Zendesk arrivent sur sav@ (captés au Bloc 1) + n° de ticket manuel + lien profond vers le portail.
- **Human-in-the-loop** : rien ne part vers Lift ni vers le client sans validation humaine — **sauf** l'accusé de réception auto. Les brouillons restent des brouillons.
- **Ne jamais inventer** un numéro de série (MHS) ou un Sales Order : extraction **verbatim ou null**.
- **Ne jamais décider automatiquement de la garantie** : l'outil signale les indices (date d'achat, contexte choc/eau/transport), l'humain tranche.

## Modèle de données (cible, construit par blocs)
Entité centrale : **Cas** (dossier SAV) — dont, depuis les Blocs 2-3 : `contexte`, `urgent`,
`complet`, `extrait_le`/`extraction_erreur` (extraction IA), `brouillon_lift`/`brouillon_lift_le`,
`statut_lift`. Autour : **Message** (emails entrants/sortants, dédup par Message-ID + threading),
**PieceJointe**, puis **Piece**, **Devis**.

## Plan par blocs (une conversation par bloc)
- **Bloc 0** — squelette : Laravel + Filament + table `cas` + déploiement Coolify. ✅ *fait*
- **Bloc 1** — relève **IMAP** de sav@ → création de cas + pièces jointes + accusé de réception auto. ✅ *fait*
- **Bloc 2** — **extraction IA** (`MailExtractor`) + statut complet/incomplet. ✅ *fait*
- **Bloc 3** — **brouillon email Lift (EN)** + suivi (ticket Lift #, SO, tracking) + garde-fou d'envoi + test Zendesk. ✅ *fait*
- **Bloc 4** — **tableau de bord** Filament + génération **devis « contrôle annuel 500 € »** depuis la grille de tarifs (catalogue intervention). ← *suivant*

### Résultat du test Zendesk (Bloc 3-D)
Auth requester testée sur `liftsupport.zendesk.com/api/v2/requests` avec `sav@liftfoils.fr` +
mot de passe : **401 « Couldn't authenticate you »** → l'auth par mot de passe sur l'API est
**fermée** côté Lift. On reste donc en **repli** : les notifications Zendesk se rattachent aux
dossiers (Bloc 1), le n° de ticket est saisi à la main (`Cas::lienPortailZendesk()` construit le
lien profond), le statut Lift est manuel (`statut_lift`). `SAV_ZENDESK_SYNC` reste `false` ; à
rebrancher (lecture seule) le jour où Lift fournit un **token API**.

### Couche IA (Blocs 2 & 3)
Fournisseur **compatible OpenAI** (chat/completions) — OpenRouter (modèles gratuits) ou xAI Grok,
piloté par la config `sav.ia`. **Une seule classe touche le fournisseur** : `App\Services\Ia\ClientIa`,
utilisée par `OpenAiMailExtractor` (extraction, verbatim-ou-null) et `RedacteurLift` (brouillon EN,
jamais envoyé auto). Clé en env uniquement ; sans clé, IA désactivée (dossiers créés, non enrichis).

### Garde-fou d'envoi (Bloc 3-B)
`SAV_ENVOI_ACTIF` (défaut `false`) au-dessus de **tout** envoi, en un seul point
(`App\Services\Mail\Expediteur`). À `false`, rien ne part : envoi simulé + journalisé.

## Conventions techniques
- Statuts `Cas` : `nouveau, attente_client, envoye_lift, attente_lift, atelier, pret, clos` (+ `urgent` en tag/booléen).
- Tâches de fond (IMAP, envois) via **scheduler + queue Laravel**, lancées en **conteneurs séparés** sur Coolify (à partir du Bloc 1).
- Migrations **rétro-compatibles** (une par bloc), jamais de refonte destructive.
- Secrets (IMAP, clé API IA) **uniquement en variables d'env Coolify**, jamais dans le code.
- Stockage des pièces jointes sur un **volume persistant** Coolify (Bloc 1).
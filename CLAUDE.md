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
- **Lift = mail, et rien d'autre.** Leur API Zendesk est **fermée** (401 confirmé, cf. plus bas) : on ne la retente pas. On ouvre les dossiers par un mail carré à help@liftfoils.com, **envoyé par l'outil sur clic humain** (`App\Services\Mail\EnvoiLift`) — jamais d'écriture dans leur Zendesk. Le suivi se fait **par leurs mails**, qui arrivent sur sav@ (n° de ticket capté, statut avancé). Voir *Suivi Lift par mail*.
- **Human-in-the-loop** : rien ne part vers Lift ni vers le client sans validation humaine — **sauf** l'accusé de réception auto. L'envoi à Lift exige un clic explicite, et il est **impossible** si le dossier n'est pas complet.
- **Ne jamais inventer** un numéro de série (MHS), un Sales Order ou une date d'achat : extraction **verbatim ou null**.
- **Ne jamais décider automatiquement de la garantie** : l'outil signale les indices (date d'achat, contexte choc/eau/transport), l'humain tranche.

## Modèle de données (cible, construit par blocs)
Entité centrale : **Cas** (dossier SAV) — dont : `contexte`, `urgent`, `complet`,
`extrait_le`/`extraction_erreur` (extraction IA, Bloc 2) ; `brouillon_lift`/`brouillon_lift_le`,
`statut_lift`, `ticket_lift` (Bloc 3) ; `date_achat`, les trois pièces tri-état
`photo_etiquette`/`preuve_achat`/`photos_defaut`, et les dates `relance_client_le`,
`envoye_lift_le`, `reponse_lift_le`, `client_avise_lift_le` (Bloc 4).
Autour : **Message** (emails entrants/sortants, dédup par Message-ID + threading),
**PieceJointe**, puis **Piece**, **Devis**.

## Plan par blocs (une conversation par bloc)
- **Bloc 0** — squelette : Laravel + Filament + table `cas` + déploiement Coolify. ✅ *fait*
- **Bloc 1** — relève **IMAP** de sav@ → création de cas + pièces jointes + accusé de réception auto. ✅ *fait*
- **Bloc 2** — **extraction IA** (`MailExtractor`) + statut complet/incomplet. ✅ *fait*
- **Bloc 3** — **brouillon email Lift (EN)** + suivi (ticket Lift #, SO, tracking) + garde-fou d'envoi + test Zendesk. ✅ *fait*
- **Bloc 4** — **flux Nico** : règle de complétude, mail client généré, envoi à Lift, capture du ticket, **dashboard en 5 vues**. ✅ *fait*
- **Bloc 5** — génération **devis « contrôle annuel 500 € »** depuis la grille de tarifs (catalogue intervention). ← *suivant*

### Résultat du test Zendesk (Bloc 3-D)
Auth requester testée sur `liftsupport.zendesk.com/api/v2/requests` avec `sav@liftfoils.fr` +
mot de passe : **401 « Couldn't authenticate you »** → l'auth par mot de passe sur l'API est
**fermée** côté Lift. **Ne pas la retenter.** Le suivi passe par les mails (Bloc 4).
`SAV_ZENDESK_SYNC` reste `false` ; le portail sert seulement de lien profond
(`Cas::lienPortailZendesk()`). À rebrancher en lecture seule le jour où Lift fournit un **token API**.

### Règle de complétude (Bloc 4) — décision métier, **un seul point du code**
`App\Services\Dossier\RegleCompletude::exigences()`. La changer, c'est modifier cette méthode,
**et rien d'autre** : elle pilote le mail au client, la colonne « Ce qui manque », les 5 vues et
le bouton d'envoi.

- **BLOQUANT** (le dossier ne part pas chez Lift) : nom + e-mail ; produit + modèle ; MHS ;
  **photo lisible de l'étiquette** ; facture **ou** Sales Order ; description ; photos/vidéos du défaut.
- **SOUHAITABLE** (demandé, ne bloque pas) : téléphone, date d'achat, contexte.

Trois pièces ne sont pas décidables par un programme (« lisible », « montrant clairement le
défaut ») : `photo_etiquette`, `preuve_achat`, `photos_defaut` sont **tri-état**. `null` = présomption
tirée des pièces jointes (une image + un MHS ⇒ étiquette présumée fournie) ; l'humain confirme ou
infirme depuis la fiche. C'est ce qui fait qu'un dossier arrive tout seul en « À valider » — la vue
où, précisément, on regarde les photos.
`complet` est **dérivé, jamais saisi** : recalculé à chaque écriture du dossier (hook `saving`) et à
chaque pièce jointe qui entre ou sort (`PieceJointe::booted`).

### Mails au client (Bloc 4) — `App\Services\Mail\NotificateurClient`
Deux mails, et deux seulement :
1. **Accusé de réception**, envoyé **après l'extraction IA** — le seul mail automatique. Sa liste à
   puces est **générée** depuis les exigences manquantes : on ne redemande jamais ce que le client
   vient de fournir. Dossier déjà complet ⇒ pas de liste, on annonce la transmission. Sert aussi de
   **relance** (bouton sur la fiche).
2. **« Dossier transmis à Lift »**, déclenché par `CasObserver` dès que le dossier entre dans la vue
   « Chez Lift », quel qu'en soit le chemin. Envoyé une seule fois (`client_avise_lift_le`).

Invariant : `NotificateurClient` **refuse d'écrire à une adresse partenaire** (`App\Support\Partenaires`).
Un mail de Lift non rattaché ouvre un dossier dont le « client » est… Lift ; lui envoyer un accusé
lui ouvrirait un ticket.

### Suivi Lift par mail (Bloc 4) — la « synchro », sans API
L'outil envoie lui-même le mail à Lift (`EnvoiLift`), il porte donc **notre** Message-ID : la réponse
de Lift se rattache par threading natif. Replis, dans l'ordre, et **uniquement pour les expéditeurs
partenaires** : n° de ticket → référence `SAV-AAAA-NNNN` dans l'objet (forcée entre crochets par
`RedacteurLift`) → noyau du sujet (`App\Support\SujetMail`).

- Les gardes anti-robot de l'ingestion **laissent passer les partenaires** : l'accusé de Zendesk *est*
  une auto-réponse, et c'est lui qui porte le n° de ticket.
- **Le n° de ticket est le seul signal qui fait avancer le statut** (`App\Support\TicketLift`). Une
  notification Zendesk sans ticket ne pousse pas un dossier « Chez Lift » — elle ne fait que dater
  `reponse_lift_le` (« Lift a répondu, à traiter »).

### Les 5 vues (Bloc 4) — `App\Enums\VueDossier`
L'UI n'expose **que** ces cinq files : **À compléter · À valider · Chez Lift · Atelier · Clos**, avec
compteur (onglets de la liste + widget d'accueil). Un statut = une action attendue ; le nom de la vue
**est** l'instruction. Les 7 statuts internes (`StatutCas`) restent plus fins et s'y projettent — la
projection est exhaustive, aucun dossier ne peut se cacher. `VueDossier::de()` (PHP) et
`VueDossier::filtrer()` (SQL) doivent toujours concorder : c'est testé.

### Couche IA (Blocs 2 & 3)
Fournisseur **compatible OpenAI** (chat/completions) — OpenRouter (modèles gratuits) ou xAI Grok,
piloté par la config `sav.ia`. **Une seule classe touche le fournisseur** : `App\Services\Ia\ClientIa`,
utilisée par `OpenAiMailExtractor` (extraction, verbatim-ou-null) et `RedacteurLift` (brouillon EN).
Clé en env uniquement ; sans clé, IA désactivée (dossiers créés, non enrichis).

### Garde-fou d'envoi (Bloc 3-B)
`SAV_ENVOI_ACTIF` (défaut `false`) au-dessus de **tout** envoi, en un seul point
(`App\Services\Mail\Expediteur`). À `false`, rien ne part : envoi simulé + journalisé — et le dossier
**ne ment pas** : il ne se dit pas « envoyé à Lift » alors que rien n'est parti.

### Couche IA (Blocs 2 & 3)
Fournisseur **compatible OpenAI** (chat/completions) — OpenRouter (modèles gratuits) ou xAI Grok,
piloté par la config `sav.ia`. **Une seule classe touche le fournisseur** : `App\Services\Ia\ClientIa`,
utilisée par `OpenAiMailExtractor` (extraction, verbatim-ou-null) et `RedacteurLift` (brouillon EN,
jamais envoyé auto). Clé en env uniquement ; sans clé, IA désactivée (dossiers créés, non enrichis).

### Garde-fou d'envoi (Bloc 3-B)
`SAV_ENVOI_ACTIF` (défaut `false`) au-dessus de **tout** envoi, en un seul point
(`App\Services\Mail\Expediteur`). À `false`, rien ne part : envoi simulé + journalisé.

## Conventions techniques
- Statuts internes `Cas` : `nouveau, attente_client, envoye_lift, attente_lift, atelier, pret, clos` (+ `urgent` en booléen). **L'UI, elle, n'expose que les 5 vues** (`VueDossier`).
- Tâches de fond (IMAP, envois) via **scheduler + queue Laravel**, lancées en **conteneurs séparés** sur Coolify (à partir du Bloc 1).
- Migrations **rétro-compatibles** (une par bloc), jamais de refonte destructive.
- Secrets (IMAP, clé API IA) **uniquement en variables d'env Coolify**, jamais dans le code.
- Stockage des pièces jointes sur un **volume persistant** Coolify (Bloc 1).
# L'instance de démonstration

Une deuxième app Coolify, publique, **sans mot de passe**, peuplée de dossiers
fictifs. On y envoie qui on veut (Nico, un client, un associé) pour montrer
l'outil, sans jamais donner accès aux vrais dossiers.

## Le principe : ce n'est pas une branche, c'est un environnement

La démo déploie **`main`**, comme la production. Même image, même code. Ce qui
les distingue tient entièrement aux **variables d'environnement**.

C'est délibéré. Une branche `demo` séparée aurait deux défauts : il faudrait y
rebasculer `main` après chaque bloc (sinon la démo se fige et on montre une
version périmée), et surtout **une branche n'isole pas les données**. Une branche
`demo` déployée avec les identifiants IMAP de production relèverait la vraie
boîte `sav@` et afficherait de vrais clients sur une URL publique. Ce qui isole,
c'est la base et les secrets — pas le Git.

## Le garde-fou : `SAV_DEMO=true` ne suffit pas

Le code de l'accès sans mot de passe vit dans `main`, donc **dans la même image
Docker que la production**. Une variable mal placée et le panneau de prod
s'ouvrirait à tout Internet.

D'où la règle, portée par `App\Support\ModeDemo` : le mode démo exige, EN PLUS du
drapeau, que l'instance soit **incapable de toucher au monde réel** :

| Condition | Pourquoi |
|---|---|
| Aucun `IMAP_PASSWORD` | Sans lui, l'instance ne peut pas relever `sav@` : aucun mail de vrai client ne peut y entrer. |
| `SAV_ENVOI_ACTIF=false` | Sans lui, l'instance ne peut écrire à personne. |

Si l'une manque, le mode démo **se refuse**, hurle dans les journaux, et le
panneau réclame un mot de passe comme en prod. *Fail closed* : une erreur de
configuration ferme la porte, elle ne l'ouvre jamais. C'est testé
(`tests/Feature/ModeDemoTest.php`).

## Créer l'app démo dans Coolify

Même projet `efoil-cotedazur`, même repo, branche `main`, même Dockerfile,
port exposé **8080**.

**1. Une ressource PostgreSQL neuve** (jamais celle de la prod).

**2. Les variables d'environnement :**

```
APP_ENV=production
APP_DEBUG=false
APP_KEY=<en générer une NOUVELLE, pas celle de la prod>
APP_URL=https://demo-sav.efoilcotedazur.fr
APP_LOCALE=fr

DB_CONNECTION=pgsql
DB_HOST=<uuid du Postgres de la démo>
DB_PORT=5432
DB_DATABASE=sav_demo
DB_USERNAME=sav
DB_PASSWORD=<le sien>

SAV_DEMO=true            ← ce qui fait la démo
SAV_ENVOI_ACTIF=false    ← obligatoire, sinon le mode démo se refuse
MAIL_MAILER=log          ← ceinture et bretelles

# ABSENTS, et c'est le sujet : IMAP_PASSWORD, MAIL_PASSWORD.
# Sans IMAP_PASSWORD, le mode démo est refusé (voir ci-dessus).

SAV_IA_CLE=<la clé OpenRouter>   ← facultatif, mais c'est la vitrine
```

**3. Pas de conteneur scheduler, pas de tâche planifiée.** La démo ne relève
rien. (De toute façon elle ne le pourrait pas : pas de mot de passe IMAP.)

**4. Le domaine :** `demo-sav.efoilcotedazur.fr`, sans authentification proxy —
c'est le but.

**5. Déployer.** Le conteneur joue `migrate --force --seed` au démarrage, et
`DatabaseSeeder` appelle `DemoSeeder` **parce que le mode démo est actif**. Les
sept dossiers fictifs apparaissent tout seuls.

## L'entretenir

Nico va cliquer partout, créer, supprimer, salir. Pour la rendre présentable :

```bash
php artisan sav:demo --reset     # efface tout et resème une démo neuve
php artisan sav:demo             # complète sans rien effacer
```

Ces commandes **refusent de tourner** ailleurs qu'en mode démo : `--reset` efface
tous les dossiers, il ne doit jamais pouvoir le faire sur les vrais.

## Ce qu'il y a dedans

Un dossier dans chacune des cinq vues, choisi pour ce qu'il démontre :

| Dossier | Vue | Ce qu'il montre |
|---|---|---|
| SAV-2026-0101 | À compléter | Le mail brut. Tout manque. Urgent. |
| SAV-2026-0102 | À compléter | Relance déjà partie : la fiche la date et dit ce qu'on attend. |
| SAV-2026-0103 | À valider | **Le mécanisme central** : le client a envoyé l'étiquette, la facture et la photo → le dossier a basculé tout seul. |
| SAV-2026-0104 | À valider | Le brouillon anglais rédigé, prêt à partir d'un clic. |
| SAV-2026-0105 | Chez Lift | Ticket #90907 capté dans leur mail, et « Lift a répondu, à traiter ». |
| SAV-2026-0106 | Atelier | |
| SAV-2026-0107 | Clos | |

Les pièces jointes (`database/seeders/demo/`) sont fabriquées, pas volées :
étiquette MHS lisible, facture PDF, photos de défaut — toutes estampillées
« DÉMONSTRATION ».

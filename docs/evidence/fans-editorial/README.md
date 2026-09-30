# Identité éditoriale — preuve isolée

Base : `origin/main` après #105, `da1ca1b7dadaa8f8f0f04b75b9bbc047882f61d9`.
Ce lot ajoute du code fonctionnel sous opt-in fermé : gestion propriétaire,
modération, projection publique et portraits contrôlés.

## Environnement et portée

- Export Git LF isolé sous WSL, dépendances physiques conformes à `composer.lock`
  (aucune exécution via la jonction `vendor` du checkout Windows).
- PHP 8.5.4, MariaDB/InnoDB sur socket Unix dans un répertoire jetable neuf,
  `--skip-networking`, GD pour normalisation PNG et dérivé JPEG. Aucun serveur
  existant n’est contacté. Le processus SQL est arrêté après la recette.
- Vrais schémas, services et callbacks REST du plugin. Les primitives de session,
  nonce, routage REST et rendu WordPress sont des adaptateurs de test explicites.
  Il ne s’agit **ni d’une API métier simulée, ni d’une recette WordPress réelle**.
- Navigateur sur `127.0.0.1:8768`, toutes les requêtes externes interdites.
  Chromium 154.0.8037.58 et WebKit 26.5 ; 1440 × 1000 et 390 × 844.
  WebKit Windows ne vaut pas validation typographique Safari/iPhone.
- Nom et bio étiquetés comme données synthétiques ; portrait abstrait généré
  pour le test. Aucune identité réelle, session SSO réelle ou donnée produit.

## Vérifications

Suite complète isolée : **295 tests, 4 555 assertions**, aucun échec ; deux
dépréciations préexistantes. PHPStan : aucune erreur. Lint des PHP modifiés,
syntaxe des scripts JS, scan ciblé de secrets et `git diff --check` passent.

`tests/Fans/Profiles/recipe/run.py` installe les schémas dans sa seule base
`fans_editorial_test` et exerce les requêtes réelles : aucune diffusion avant
approbation, champs forgés refusés, nonce, propriétaire distinct, modération,
édition, rejet/purge, rollback InnoDB après faute d’audit injectée, portrait
pending/étranger/périmé, dérivé JPEG, suspension, retrait et révocation des
anciens liens. Deux processus PHP concurrents sur la même révision donnent
exactement un succès et un conflit 409. Cette recette devient un job CI.

Le parcours navigateur soumet le formulaire propriétaire, examine le portrait
privé et décide dans le panel existant. Il vérifie Explorer → profil public,
édition qui retire l’ancienne projection, refus qui efface les champs, retrait
propriétaire, accès invité/Fan/Créateur/admin, HTTP 404 **de la page** suspendue,
absence de débordement horizontal et d’erreur JavaScript, effacement du contenu
privé à `pagehide`. Résultats : [Chromium](chromium.json), [WebKit](webkit.json).

Commandes (export isolé avec dépendances physiques) :

```text
php vendor/bin/phpunit --colors=never
php vendor/bin/phpstan analyse --no-progress
python3 tests/Fans/Profiles/recipe/run.py
python3 tests/Fans/Profiles/recipe/run.py --web
FANS_UI_OUTPUT=<répertoire> node tests/Fans/Profiles/recipe/browser.cjs
FANS_UI_BROWSER=webkit FANS_UI_OUTPUT=<répertoire> node tests/Fans/Profiles/recipe/browser.cjs
```

Arrêter le serveur PHP de recette après les captures : le runner arrête alors
sa base. Aucune installation WordPress n’est nécessaire à ces commandes.

## Captures Chromium inspectées

Les images complètes conservent la barre de navigation à la position du viewport
initial ; sur une capture mobile longue elle apparaît donc avant le bas du fichier.

| Vue | Ordinateur | Mobile |
| --- | --- | --- |
| Formulaire propriétaire et choix privé | [Capture](owner-desktop.png) | [Capture](owner-mobile.png) |
| Panel et examen du portrait | [Capture](moderation-desktop.png) | [Capture](moderation-mobile.png) |
| Explorer après approbation | [Capture](explorer-desktop.png) | [Capture](explorer-mobile.png) |
| Profil public après approbation | [Capture](public-desktop.png) | [Capture](public-mobile.png) |

DA reprise du socle V2 : fond sombre, accent vert, bandeau clair, navigation
Créateur à huit accès et label actif seul. Le formulaire de gestion est distinct
du profil public. Pas de photographie ni de mesure sociale inventée pour remplir
les planches. Les publications/suivis non activés dans cette recette restent
explicitement indisponibles ; leur état n’est pas une conclusion sur le site cible.

## Limites et suite

La sélection du portrait exige une image déjà approuvée par Images. Le lot
suivant raccorde le dépôt et la galerie privée complète ; ce parcours n’est pas
déclaré terminé ici. La mise à disposition en production nécessite le stockage
privé attesté, l’activation explicite des schémas et opt-ins par le propriétaire,
une modération humaine et sa recette WordPress/thème/Elementor/SSO réelle.
Aucun site, flag, déploiement ou paiement n’a été modifié par cette validation.

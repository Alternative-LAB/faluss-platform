# Galerie privée — preuves de code et navigateur

30 septembre 2026, branche `feat/fans-private-image-library`, base #106
`e78eea8bd6b1b83c0da6ecf8ec0af60f7406db1e`. Les captures correspondent au code
de ce commit de galerie (voir historique Git), jamais à un site WordPress.

## Environnement et résultats

- Export Git LF isolé sous WSL, dépendances Composer physiques vérifiées contre
  les 31 références verrouillées. La jonction vendor Windows n’est pas exécutée.
- PHP 8.5.4 : lint des dix PHP modifiés, PHPStan sans erreur, PHPUnit **295 tests /
  4 573 assertions**, deux dépréciations préexistantes.
- MariaDB/InnoDB dédié, socket sans réseau, base jetable, répertoire privé POSIX
  0700 et fichiers 0600, GD réel. Quatre workers PHP sur loopback uniquement.
- Recette SQL existante : mutations éditoriales et contrôle concurrent de révision
  (une réponse 200, une 409). Recette HTTP : vrais multipart, `is_uploaded_file`,
  décodage, stockage, quotas, déduplication concurrente, isolation des propriétaires,
  invalidité de fichier, rollback de journal, révocation et nettoyage après panne.
- Chromium **154.0.8037.58** et WebKit **26.5**, 1440×1000 et 390×844 : dépôt natif,
  aperçu privé, rejeu sans doublon, modération image, sélection du portrait,
  modération éditoriale, Explorer → profil public, retrait révoquant le portrait,
  galerie fermée, formulaires sans JavaScript, nettoyage `pagehide`. Aucun
  débordement horizontal ni erreur JS. Résultats JSON joints.

Les primitives WordPress (session de test, nonce, dispatch REST, rendu des hooks)
sont des adaptateurs isolés. Les services, InnoDB, fichiers et HTTP sont réels.
**Aucun site WordPress, Elementor, SSO réel, hébergeur ou production validé.**
Le module Texte est fermé dans cette fixture et l’UI le signale honnêtement.
Les huit accès Créateur sont conservés ; les offres commerciales restent indisponibles.
Le carré vert et la présentation sont explicitement synthétiques, jamais des données produit.

## Captures Chromium examinées

| Parcours | Ordinateur | Mobile |
| --- | --- | --- |
| Galerie, dépôt privé, états, aperçu | [Capture](images-desktop.png) | [Capture](images-mobile.png) |
| Modération native de l’image | [Capture](image-moderation-desktop.png) | [Capture](image-moderation-mobile.png) |
| Portrait issu du dépôt, après deux approbations | [Capture](portrait-from-upload-desktop.png) | [Capture](portrait-from-upload-mobile.png) |
| Créer et ses huit accès | — | [Capture complète](create-mobile.png) |
| Retrait confirmé | — | [Capture](withdrawn-mobile.png) |

DA conservée : fond sombre, vert, bandeau ivoire, labels de navigation actifs.
Les captures longues conservent la barre mobile fixe à la position du viewport
au moment de la capture ; elle reste ancrée en bas pendant le défilement réel.
Les références officielles contiennent des données fictives ; aucun chiffre,
portrait photographique, nom ou score inventé n’a été ajouté au produit.
Le port WebKit Windows vérifie le comportement, pas le rendu Safari sur appareil.

## Reproduction hors site

Dans un export Linux isolé avec dépendances Composer, PHP/GD/PDO MySQL et binaires
MariaDB, sans utiliser une base existante :

```sh
python3 tests/Fans/Profiles/recipe/run.py --http
python3 tests/Fans/Profiles/recipe/run.py --web
# Dans un second terminal, avec Playwright installé :
FANS_UI_OUTPUT=/tmp/fans-images FANS_UI_BROWSER=chromium node tests/Fans/Profiles/recipe/images-browser.cjs
# Recréer la fixture --web avant un second moteur :
FANS_UI_OUTPUT=/tmp/fans-images-webkit FANS_UI_BROWSER=webkit node tests/Fans/Profiles/recipe/images-browser.cjs
```

Arrêter le serveur PHP enfant à la fin ; le runner ferme ses workers et sa base.
La CI conserve tous les contrôles précédents et ajoute `--http` à la recette SQL.

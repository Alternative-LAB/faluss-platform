# Publication illustrée — preuve du raccordement propriétaire

30 septembre 2026, base #107 `2a89c343596cf2a80e7ac498caf5e3b47a0fa2a2`,
branche `feat/fans-publication-image-author`. Captures du code de ce lot, avec
texte et image abstraite explicitement synthétiques.

## Vérifié

- Export Git LF isolé, dépendances physiques validées contre Composer lock :
  PHP 8.5.4, lint des six PHP modifiés, PHPStan sans erreur, PHPUnit **295 tests,
  4 582 assertions**, deux dépréciations préexistantes.
- Recette MariaDB/InnoDB sans réseau et HTTP PHP multipart : les 47 contrôles du
  lot Images restent verts ; 20 contrôles supplémentaires sur l’association native
  (cinq rôles/propriétaires, nonce, confirmation, choix absent, image pending,
  révision image périmée, association, pending non public, rejeu 409, JPEG réel,
  détachement, révocation et rollback d’une erreur SQL sur la référence).
- Chromium **154.0.8037.58**, WebKit **26.5**, ordinateur 1440×1000 et mobile
  390×844 : création native du texte, aperçu privé, association et absence de
  diffusion avant revue, modération native du couple texte/image, lecture publique
  à la demande, détachement sans JavaScript. Aucun overflow horizontal ni erreur JS.
- L’adaptateur utilise uniquement les contrats REST existants. Aucun moteur
  Images/Publications, schéma, flag ou contrat métier modifié.

Services, fichiers, transactions et formulaires sont réels ; WordPress et la
session sont des adaptateurs de test. **Aucun site WordPress, Elementor, SSO réel
ou serveur de production consulté.** La description alternative détaillée des
images publiques reste une limite connue du contrat de lecture ; aucune description
inventée n’est affichée. La barre mobile fixe conserve sa position de viewport
dans les captures longues. WebKit Windows ne remplace pas une recette Safari.

## Captures revues

| Parcours | Ordinateur | Mobile |
| --- | --- | --- |
| Choix et aperçu propriétaire | [Capture](association-desktop.png) | [Capture](association-mobile.png) |
| Modération du texte avec image | [Capture](text-image-moderation-desktop.png) | [Capture](text-image-moderation-mobile.png) |
| Lecture publique après approbation | [Capture](text-image-public-desktop.png) | [Capture](text-image-public-mobile.png) |

## Reproduire

Même environnement isolé que [la galerie](../fans-private-images/README.md),
avec le mode `--publications` qui installe les tables existantes dans sa base
jetable, sans modifier les flags d’un site :

```sh
python3 tests/Fans/Profiles/recipe/run.py --http --publications
python3 tests/Fans/Profiles/recipe/run.py --web --publications
FANS_UI_OUTPUT=/tmp/fans-author-image FANS_UI_BROWSER=chromium node tests/Fans/Profiles/recipe/publication-browser.cjs
```

Arrêter PHP pour fermer les workers et la base, puis recréer la fixture avant
le second moteur. Les rapports des deux moteurs sont joints en JSON.

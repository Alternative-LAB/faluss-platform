# Lot 7 — Lecture des images de publications

29 septembre 2026. Base `d25a07b5b0a291daff00fb427f4c5e423d0329a2`, version 0.6.3.
Export Git LF + diff dans WSL, vendor physique conforme au lock, PHP 8.5.4.
Lint des deux PHP modifiés, PHPStan complet, **284 tests / 4 262 assertions**,
aucun échec, deux dépréciations existantes. Syntaxe JS et diff contrôlés.

## Recette isolée

`tests/Fans/Ui/recipe/images.cjs` utilise Chromium, un serveur PHP local et des
réponses REST simulées. Le JPEG géométrique porte la mention « FIXTURE DE RECETTE ».
Il ne représente aucune personne, création réelle ou contenu approuvé en production.

- Aucun bouton ni appel d’image si le contrat de diffusion est fermé.
- Invité, Fan lié, Créateur lié, administrateur : lecture publique à la demande,
  UUID/révision dans le lien technique seulement, aucun téléchargement automatique.
- Clavier, bouton de masquage conservant le focus, suppression des URL Blob.
- 404, 503, mauvais type, plus de 2 Mio et JPEG corrompu : état explicite,
  aucune image, réessai manuel possible ; aucun fallback privé.
- Une requête à la fois, suppression au départ/masquage de page, réponse tardive
  ignorée. Régressions de lecture et matrice 168 cas également vertes.

## Captures inspectées

- [Publication sur ordinateur](publication-image-desktop.png), section de lecture
  à largeur 1440 px.
- [Image sur mobile](publication-image-mobile.png), viewport 390 × 844, après
  défilement vers la carte ; navigation persistante.
- [Image indisponible sur mobile](image-indisponible-mobile.png).

Couleurs, cartes et navigation V2 conservées ; aucun remplissage avec des visuels
de personnes fictives. La description alternative éditoriale n’est pas fournie
par le contrat, et cette limite est visible. Le chargement à la demande diffère
de la galerie des planches pour respecter le contrat de génération existant.

**Ni WordPress réel, ni Elementor, ni stockage privé réel, ni SSO réseau validé.**
Le moteur de dérivés n’est pas modifié ; sa recette historique ne valide pas ce
nouvel adaptateur navigateur. Aucun flag, site, release ou déploiement.

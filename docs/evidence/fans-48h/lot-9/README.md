# Lot 9 — Publications du profil public

29 septembre 2026. Base `2683da08abe24991ee434a0f5e3504f62f8f5be7`, après le filtre
serveur #96, version conservée 0.6.3. Export LF + diff, vendor physique conforme
au lock dans WSL ; PHP 8.5.4, trois PHP lintés, PHPStan complet vert,
**286 tests / 4 361 assertions**, aucun échec, deux dépréciations existantes.

## Recette et limites

`tests/Fans/Ui/recipe/profile-publications.cjs`, Chromium 1440 × 1000 et 390 × 844.
Serveur PHP local et adaptateurs REST simulés, textes explicitement marqués fixture.
Ni WordPress réel, ni Elementor, ni SSO réseau ou site cible consulté.

- Explorer → fiche Musique → publications du second profil pour invité, Fan lié,
  Créateur lié et administrateur ; aucun UUID visible ni nom/portrait inventé.
- Paramètre du même créateur conservé sur première page, suivante et relecture ;
  absence de lien vers le profil déjà ouvert dans chaque carte.
- Réponse d’un autre créateur refusée ; aucun contenu précédent réutilisé.
- Liste vide, route fermée et erreur explicites ; retour BFcache relu, départ
  de page effaçant les textes ; URL de dérivé d’image sans query du filtre texte.
- Profils absent/suspendu/retiré : HTTP 404 du document, aucune section de lecture
  ni requête de publications. La matrice existante vérifie aussi les quatre rôles.
- Régressions lecture, images et 168 cas rôle/route/viewport vertes.
- Syntaxe JS, scan de secrets ciblé et `git diff --check` contrôlés.

Le contrat serveur et ses règles de pagination/modération sont couverts par les
tests de #96 ; le navigateur simule ici le transport. Il n’existe toujours pas de
nom public ou portrait approuvé. Les contenus visibles sont soumis aux décisions
du serveur ; un texte déjà reçu ne peut pas être rappelé instantanément.

## Captures inspectées

- [Profil public, ordinateur](profil-public-textes-desktop.png), rôle Créateur et huit accès.
- [Publications du profil, mobile](profil-public-textes-mobile.png), invité après défilement.

Bandeau crème, cartes sombres, accents verts et navigation V2 conservés. La fiche
reste provisoire, sans les identités et compteurs fictifs des planches. Aucun flag,
déploiement, release, paiement ou changement des sites.

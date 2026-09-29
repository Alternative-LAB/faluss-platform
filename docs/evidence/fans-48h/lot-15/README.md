# Lot 15 — continuité de lecture au clavier

Base : `6cac194c3cf541cf6c02304912d0ce84ac479beb` (#102). Correctif limité
aux assets de lecture et de focus ; aucun PHP, contrat, flag ou moteur modifié.

## Défaut reproduit puis corrigé

La recette `tests/Fans/Ui/recipe/reading-keyboard.cjs` échouait sur la base :
« Page suivante must keep focus while loading ». La disparition/désactivation
du bouton faisait perdre le focus pendant la requête.

Le bouton reste maintenant focalisé et temporairement `aria-disabled`, avec
curseur invalidé pour empêcher les demandes concurrentes. À réception d’une
action explicite, le statut précédant les textes reçoit le focus si le lecteur
n’a pas changé de cible. Erreur et dernière page restent parcourables au clavier.

## Vérifications

- Chromium 154.0.8037.58 et WebKit 26.5 Windows : chargement initial sans focus
  imposé, chargement retardé, double activation, dernière page, reprise,
  erreur 503/réessai, déplacement pendant la requête sans reprise du focus.
- Ordinateur 1440 × 900 et mobile 390 × 844 ; aucun débordement horizontal.
- Chromium : recettes existantes `reading.cjs`, `images.cjs` et
  `profile-publications.cjs` réussies (rôles, curseurs, visibilité, erreurs,
  contenu texte, images, réponses dépassées et HTTP 404 du document).
- Syntaxe des deux JavaScript, liens documentaires, revue du diff et recherche
  ciblée de secrets. Aucun PHP changé ; contrôles PHP/analyse statique confiés
  à la CI de la PR, sans réutiliser la jonction vendor Windows.

Le banc WebKit Windows ignore les liens natifs avec Tab, y compris dans une page
minimale indépendante (`p tabindex=-1`, lien, bouton) ; Alt+Tab n’y change rien.
La recette vérifie explicitement le bouton suivant dans ce moteur et le lien
suivant dans Chromium. Aucun contournement produit ni parité Safari prétendue.
La lecture avec lecteur d’écran et les réglages clavier Safari restent à recetter.

## Captures inspectées

Captures natives du viewport après pagination au clavier, avec focus sur le
statut. Les textes sont des fixtures ; serveur PHP 8.5.4 local isolé, adaptateurs
de test, sans WordPress, Elementor, compte réel ni site consulté.

- [Ordinateur](lecture-clavier-1440.png)
- [Mobile](lecture-clavier-390.png)

La DA, les états indisponibles et les huit accès Créateur restent ceux du
[lot 14](../lot-14/README.md). Ces captures ne prouvent pas le rendu du site cible.

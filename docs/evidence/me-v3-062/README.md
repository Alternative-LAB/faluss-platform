# Faluss Platform 0.6.2 — recette ciblée locale

Base : `main` 0.6.1, `79599c36bce1330442a83016fbd471ad0d5daa99`. Date : 29 septembre 2026.

## Nature des preuves

Les vues PHP et contrôleurs JS/CSS réels sont exécutés localement. Les transactions utilisent des doubles WordPress en mémoire ; les requêtes du Studio sont simulées, y compris latence, conflit 409 et erreur 503. Les pièces jointes 77/78, le catalogue Instagram du test et les SVG illustratifs sont des fixtures. Aucun média de remplacement n'est livré dans le catalogue du produit.

**Aucun accès à faluss.me/faluss.com, aucune installation, aucun flag modifié.** Ce lot ne constitue pas une recette WordPress/MariaDB, Elementor réel, ni Safari avec clavier physique. Les rendus partagent le renderer canonique ; le propriétaire vérifiera les intégrations et le comportement iPhone avec le ZIP.

## Causes, corrections et scénarios

| Point | Cause observée / correction | Vérifications positives et négatives |
| --- | --- | --- |
| Fond de l'aperçu | Le minimum dépendait du viewport extérieur, pas de la hauteur visible du téléphone après zoom. Il utilise maintenant la géométrie du mockup. | Carte au moins aussi haute que l'écran du mockup, y compris 390×690 et après expansion. Pas de nouvelle hauteur imposée à la carte publique. |
| Avatar | Anciennes règles `border-radius:50%` et `clip-path:circle` écrasaient les variantes. Spécificité canonique corrigée sur conteneur et image ; remplacement facultatif et vignette locale après upload accepté. | 3 formes × 4 effets, identifiant 78 conservé après relecture ; aperçu égal au rendu sauvegardé. Masquer/réafficher garde le fichier. Ancien contrat d'onboarding sans `avatar_visible` toujours accepté. |
| Clavier | Le code ignorait le déplacement de l'origine visuelle de Safari. Compensation du shell fixe, événements regroupés par frame, correction instantanée du seul scroll interne. | Identité/Réseaux/Dernier regard, clavier simulé ouvert/fermé, expansion manuelle ; liens ajoutés et champ URL avec `offsetTop=180`, 30 événements resize/scroll, texte conservé, header et bas ancrés ; zéro appel de scroll fenêtre. Un champ court dispose d'une réserve minimale sans expansion maximale. |
| Noir / wallpaper | Palette noire en réalité `#292929` ; transition du thème maintenue malgré un fond surchargé. Noir `#000000` et transition liée sauf choix explicite. Mouvement absent car réservé à l'ancien mode immersif et annulé par CSS canonique. | Fond calculé `rgb(0,0,0)`, transition noire et transition explicite violette conservée. Compacte/Cover au repos identiques, mouvement public discret au scroll, aucun mouvement sous reduced-motion. Aucun gris historique migré. |
| Studio | Sélection mise à jour après GET et liens recréés. Sélection/pill immédiates, liens conservés à destinations identiques et restauration sur échec. | Réponse retenue aux deux niveaux : pill animée avant réponse, ancien contenu visible. Historique, clavier, URL directe, annulation dirty, canonical save/read, 409, 503, courses, repli sans JS, Shop indisponible et reduced-motion. Visibilité Avatar utilise la préférence existante. |
| Composition | Espacement responsive réduit légèrement ; compensation sans photo et dégradé Cover rapproché de l'identité. | 320/390/768, Compacte/Cover, avec/sans avatar ; identité centrée et premier lien accessible. La bio et les autres blocs gardent leur ordre naturel. |
| Liens illustrés | Disposition cachée dans Réglages ; styles de liste prioritaires donnant des tuiles ovales ; image excluant le pictogramme. Sélecteur ajouté dans Liens, grille prioritaire, pictogramme configuré admis avec image. | Première tuile pleine largeur puis deux colonnes, rayon 18 px, libellé lisible, fallback cliquable. Ajout77/remplacement78/retrait0 et relecture ; ordre, visibilité masquée, collections et contenu conservés. Le mode liste reste sans images. Aucun nouveau type de bloc/droit/paiement. |

## Exécution

- `php vendor/bin/phpunit tests/MeStudio` : **35 tests, 471 assertions**, succès.
- `php vendor/bin/phpunit tests/Link` : **19 tests, 215 assertions**, succès.
- `php vendor/bin/phpstan analyse --no-progress` : aucune erreur.
- PHP lint des fichiers modifiés, JS syntax des scripts modifiés, contrat JS autosave, scan ciblé des lignes ajoutées et `git diff --check`.
- `tests/MeStudio/v3-mobile-regression.js` : Chromium/WebKit, 320/390/768×844 et 390×690. [Résultats](browser-results.txt).
- `tests/MeStudio/v3-studio-navigation-regression.js` : Chromium/WebKit, 320/390/768 ; HTTP simulé. [Résultats](studio-results.txt).
- `tests/MeStudio/v3-surface-regression.js` : Chromium/WebKit, 320/390/768 ; vrais styles calculés du renderer PHP. [Résultats](surface-results.txt).

Les empreintes de caractérisation sont mises à jour uniquement pour les deux assets Link volontairement modifiés. Aucun fichier Identity/SSO, Fans, Hub/Portal, Token Engine/Connector ni contrat PF n'est modifié. Les contrôles complets habituels du dépôt sont exécutés par la CI de la PR.

## Captures

- [Identité](onboarding-v3_identity-390.png), [Réseaux](onboarding-v3_socials-390.png), [Dernier regard](onboarding-v3_review-390.png).
- [Studio Chromium](chromium-studio-design-390.png), [Studio WebKit](webkit-studio-design-390.png).
- [Compacte illustrée Chromium](chromium-compact-images-390.png), [Cover illustrée WebKit](webkit-cover-images-390.png).

Les captures sont locales, avec images illustratives et sans les polices/médias distants du site. Elles prouvent la géométrie testée, pas la direction artistique finale.

## Retour arrière

Pas de migration. Réinstaller le ZIP 0.6.1 remet le rendu précédent tout en conservant les préférences existantes et les pièces jointes. Le propriétaire effectue lui-même installation et recette. Aucun changement de flag n'est nécessaire au correctif.

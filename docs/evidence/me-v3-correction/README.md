# Correction Me V3 — 0.5.0

## Causes corrigées

- Les valeurs temporaires V3 passaient sous le thème lors de la résolution. Les champs modifiés rejoignent maintenant les dérogations temporaires, comme lors de la persistance.
- L'aperçu compact et l'habillage public immersif appliquaient des dimensions et marges incompatibles. Une composition standard unique remplace ces variations pour les cartes canoniques.
- Le changement d'effet avatar ne synchronisait pas la bordure dans l'aperçu ; la graisse du nom manquait à la version agrégée.
- Le fournisseur V3 ne proposait aucun Studio. Il propose maintenant dix rubriques natives, qui sauvegardent l'agrégat existant sans écrire de curseur.

## Vérifications ciblées

`tests/MeStudio/v3-composition-regression.php` utilise le renderer PHP réel avec un double transactionnel en mémoire : séquence Compacte/Aucun → Dégradé → Compacte → Cover → Compacte, deux structures, aperçu sans écriture égal au HTML relu après sauvegarde, couleurs, forme, nom, avatar, réseaux, conservation de la photo, refus de média étranger et HTTP 409 simulé sans écriture. Le test PHPUnit appelle ce contrat en CI. Les dix écrans Studio et la confirmation sont également rendus par PHP.

`tests/MeStudio/v3-mobile-regression.js` exécute Chromium et WebKit à 320, 390 et 768 px : onglets, expansion avant défilement complet, champs 16 px, absence de débordement horizontal, en-tête stable avec hauteur clavier simulée, réponse AJAX obsolète ignorée, dimensions publiques/aperçu et bascules de fond. Résultats dans [browser-results.txt](browser-results.txt).

Trois vues locales illustratives : [onboarding](onboarding-390.png), [Studio](studio-390.png), [carte](public-390.png). Les images de profil et couverture sont des formes synthétiques de test. Elles ne représentent pas un catalogue officiel ni une recette réelle.

## Limites exactes

Aucune connexion, installation, modification de flag ou recette sur faluss.me/faluss.com. Aucun nouveau test WordPress/MariaDB/Elementor réel ni sur téléphone physique dans ce lot. Le clavier est simulé via visualViewport ; les barres Safari et le clavier iOS restent à vérifier par l'utilisateur. La police Outfit dépend de celle fournie par le site. Les styles Elementor explicites restent une couche locale susceptible de modifier la carte ; aucune configuration de widget réelle n'a été inspectée. Les causes des anciens incidents de téléversement/publication de production restent inconnues.

Le lot Fans #53 est conservé, sans modification des rôles ni du démarrage de modules. Le chargement des assets de carte demeure possédé par Link, réservé au rôle `me` ; le Studio V3 reste sous son flag existant.

## Contrôles de livraison

Lint PHP ciblé, syntaxe JavaScript, PHPStan et contrat Identité : réussis. Suite MeStudio : 15 tests réussis. La suite locale complète exécute 171 tests ; trois comparaisons d'octets de sources historiques hors périmètre échouent sur le checkout Windows CRLF (Portal, Subscriptions, Token Engine). Ces fichiers ne sont pas modifiés. La CI Linux de la PR reste le contrôle requis avant fusion.

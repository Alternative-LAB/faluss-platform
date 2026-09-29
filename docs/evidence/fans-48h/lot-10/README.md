# Lot 10 — WebKit, navigation mobile et clavier

29 septembre 2026. Base `43eec96f74b9236e96ae5feb103629bb8a16964c`, version 0.6.3.
Serveur PHP local isolé, export LF avec vendor physique, REST et comptes simulés.
**Aucun WordPress, site, SSO réseau ou iPhone réel consulté.**

## Résultats fonctionnels

Sept recettes sous **WebKit 26.5, port Windows de Playwright** : `browser.cjs`,
`reading.cjs`, `images.cjs`, `profile-publications.cjs`, `author.cjs`,
`admission.cjs`, `matrix.cjs`. Toutes passent, y compris les 168 cas de la matrice.
La navigation utilise aussi des contextes `isMobile`/`hasTouch` : les huit liens
Créateur sont touchés à 320 et 390 px ; cibles d’au moins 44 × 44, centre non
recouvert, destination et unique label actif vérifiés.

Une perte de focus après chargement d’image au clavier a été reproduite avant
correction. `disabled` retirait le focus ; l’UI utilise désormais `aria-disabled`
pendant l’attente et son garde refuse les lancements concurrents. Le test vérifie
le focus après affichage et masquage, ainsi que l’unicité de la requête en cours.

Le test du formulaire texte attend maintenant l’arrivée effective du bouton de
réessai après la réponse 503, avant d’inspecter la clé et le champ readonly.
Les assertions restent identiques ; l’ancien test pouvait lire le formulaire
précédent pendant la navigation WebKit.

Régressions sous **Chromium/Chrome 154.0.8037.58** : images, navigation tactile,
formulaires auteur et admission vertes. Aucune modification PHP ; la base possède
286 tests / 4 361 assertions verts. Syntaxe JS et diff contrôlés.

## Limite visuelle mesurée

La fonte variable Outfit a un axe `wght` de 100 à 900, avec défaut 100. Dans ce
banc WebKit Windows, les textes restent très fins : ni `font-weight`, ni
`font-variation-settings`, ni `format('truetype-variations')` ne produisent les
poids attendus, alors que les FontFace sont déclarées chargées. Le même fichier
et le même diagnostic sous Chromium distinguent les poids 400 et 700.

- [Diagnostic WebKit Windows](font-probe-webkit.png).
- [Même diagnostic Chromium](font-probe-chromium.png).

Ces mesures isolent un écart entre les moteurs du banc ; elles ne prouvent pas
son comportement sur Safari macOS/iOS ni sa cause interne exacte. Aucun contournement
de fonte n’est ajouté sur la seule base de ce port Windows. **Les captures WebKit
ci-dessous prouvent des états et le focus, pas la conformité typographique cible.**
La validation visuelle sur le navigateur et le thème réels reste à faire par le
propriétaire avant activation.

## Captures inspectées

- [Image et focus, ordinateur](publication-image-desktop.png).
- [Image et focus, mobile](publication-image-mobile.png).
- [Navigation Créateur, mobile](creer-mobile-bottom.png).
- [Formulaire natif sans JavaScript, mobile](creation-mobile.png).

Le JPEG est une fixture géométrique identifiée, aucun nom/portrait ou score inventé.

## Reproduction locale

Avec Playwright et ses navigateurs déjà disponibles, lancer le serveur PHP de
`tests/Fans/Ui/recipe/router.php` depuis un export isolé du dépôt. Puis :

```powershell
$env:FANS_UI_BROWSER = 'webkit' # ou chromium, valeur par défaut
$env:FANS_UI_OUTPUT = '<répertoire de preuves temporaire>'
node tests/Fans/Ui/recipe/browser.cjs
node tests/Fans/Ui/recipe/images.cjs
node tests/Fans/Ui/recipe/author.cjs
node tests/Fans/Ui/recipe/admission.cjs
node tests/Fans/Ui/recipe/reading.cjs
node tests/Fans/Ui/recipe/profile-publications.cjs
node tests/Fans/Ui/recipe/matrix.cjs
node tests/Fans/Ui/recipe/font-probe.cjs
```

`FANS_UI_BASE` reste par défaut `http://127.0.0.1:8765`. Ne pas pointer ces fixtures
sur un site réel. Aucun flag de production, déploiement, release ou paiement.

# Vérification de l’import de liens dans le navigateur

Le contrat utilise les vues PHP réelles produites par
`tests/MeStudio/v3-composition-regression.php`, les feuilles de style du Studio
et les scripts livrés `link-import.js` et `studio-v3.js`. Les réponses AJAX sont
simulées dans Chromium. Aucun site WordPress ni aucune plateforme tierce n’est
consulté. Les profils, liens et images des captures sont synthétiques.

Commande exécutée depuis la racine du dépôt :

```sh
NODE_PATH=/tmp/faluss-import-browser/node_modules node tests/MeStudio/link-import-browser-test.js
```

Playwright a été installé uniquement dans le dossier temporaire
`/tmp/faluss-import-browser`, sans dépendance supplémentaire dans le projet.
Un navigateur Chromium compatible doit être disponible dans le cache Playwright
ou indiqué par `FALUSS_CHROME`. `FALUSS_PHP` permet de choisir le binaire PHP.

La passe UI/UX reprend les surfaces blanches et grises, les rayons de 12 px et
le bouton principal corail du Studio. Le parcours sépare le choix de la source
de la vérification. Les lignes restent compactes ; chaque lien ouvre son éditeur.

Les scénarios passent aux largeurs **320, 390 et 768 pixels** : ouverture au
clavier, aperçu mixte, exclusion initiale des doublons et erreurs, affichage
littéral d’un titre hostile sans exécution HTML, modification et nouvelle
validation, conservation des exclusions, nonce et version ciblés, un seul envoi
en cas de double clic, relecture canonique après succès, conservation du
brouillon après conflit, erreur JSON, lecture locale des fichiers, limites de
taille et d’extension, verrouillage pendant la lecture d’un fichier, garde de
navigation et de sauvegarde d’un autre élément, blocage des nouveaux envois après
une écriture confirmée dont la relecture échoue, puis reprise par actualisation.
Les onglets de source fonctionnent au clavier (flèches, Home/End), les brouillons
fichier/texte restent distincts et les liens fermés occupent moins de 100 px chacun.
Les tests vérifient la visibilité d’une seule action principale, les éditeurs
dépliés, les libellés sémantiques et l’absence de débordement horizontal.
Aucune erreur JavaScript n’a été observée.

[Résultats détaillés](browser-results.json) ·
[320 px](preview-320.png) · [390 px](preview-390.png) · [768 px](preview-768.png)

Ces preuves vérifient le comportement client et son intégration au Studio. Le
transport simulé ne démontre pas le traitement HTTP WordPress, les permissions
réelles, les transactions MariaDB ou la compatibilité Elementor. Les tests PHP
d’import couvrent séparément la validation et la transaction avec des doubles
WordPress. Les vérifications de libellés et de clavier ne constituent pas un
audit d’accessibilité complet ; Safari et Firefox ne sont pas couverts ici.

Captures de présentation avec des liens fictifs : [choix du fichier](source-390.png),
[copier-coller](paste-390.png), [vérification mobile](demo-mobile.png),
[édition d’un lien](edit-390.png) et [écran large](demo-large.png). Elles utilisent le rendu livré et les résultats
du parseur/validateur PHP réels ; le transport AJAX reste simulé.

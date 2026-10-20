# Recette visuelle locale du Studio web

La [recette complémentaire sur WordPress/MariaDB isolé](wordpress-isolated.md) contient les captures et les écritures réellement observées sur l’instance locale.

Base : `main` à `6b25c3168c1dac90536f2dadd7b14269f01dfa62`. Les vues proviennent du PHP du plugin. Chromium et WebKit rendent réellement les pages et exécutent les interactions ; les réponses AJAX et leur persistance après rechargement proviennent d’un serveur jetable en mémoire. Le média de la fixture est un SVG synthétique local. La vue est aussi placée dans un conteneur de 645 px pour reproduire la largeur de contenu imposée par certains thèmes WordPress : le compositeur web doit malgré cela occuper la largeur du viewport.

Commande : `node tests/MeStudio/studio-v3-web-composer.js` avec `FALUSS_PHP`, `NODE_PATH` pour Playwright et, si nécessaire, `FALUSS_CHROME` définis. [Résultats complets](results.txt).

| Taille | Attendu et vérifié |
| --- | --- |
| 390 × 844, 768 × 900, 900 × 900 | Studio mobile conservé, panneau inférieur fixe, modal d’aperçu |
| 1024 × 600 | Vue mobile conservée faute de hauteur |
| 1024 × 768, 1440 × 900 | Réglages à gauche, carte défilante à droite ; aucun débordement horizontal |

Sur les deux grandes tailles, un clic souris sous WebKit et un toucher sous Chromium sélectionnent le lien existant, chargent sa rubrique, ouvrent son éditeur exact et empêchent la navigation vers l’URL externe. Une saisie valide met à jour la preview par l’AJAX simulé sans mutation ; Enregistrer est la seule mutation ; le rechargement relit la valeur simulée. Le test de contrat PHP appelle aussi la vraie fonction de preview : identifiant stable, URL HTTPS valide, refus d’URL non HTTPS et aucune écriture du brouillon. Les tests historiques de navigation mobile couvrent retour, historique, focus, conflits et erreurs de relecture.

Captures : [PC Chromium](chromium-studio-1440-900.png), [grande tablette Chromium](chromium-studio-1024-768.png), [téléphone Chromium](chromium-studio-390-844.png), [PC WebKit](webkit-studio-1440-900.png), [grande tablette WebKit](webkit-studio-1024-768.png), [téléphone WebKit](webkit-studio-390-844.png).

Ces captures constituent une recette visuelle réelle du navigateur sur vues PHP, avec médias et réseau simulés. Elles ne prouvent pas une installation WordPress/MariaDB/Elementor, un serveur de mise à jour, une tablette ou un téléphone physique, ni une sauvegarde sur base réelle. Avant publication, vérifier ces points sur une instance WordPress isolée, puis comparer preview et carte publique après sauvegarde et rechargement. Aucun site, donnée ou permission de production n’a été touché.

## Régression corrigée le 20 octobre

Un en-tête de thème de 280 px décalait le compositeur hors du viewport et rendait sa navigation inférieure inaccessible avec le défilement global bloqué. Le test navigateur a reproduit `top = 280` au lieu de zéro avant correction. La vue web reprend désormais le positionnement plein écran du Studio mobile. Les six tailles Chromium réussissent avec cet en-tête ; les nouvelles assertions vérifient aussi le bas du compositeur. Le job CI « Me Studio browser tests » exécute les deux moteurs, avec des outils temporaires épinglés et des captures fraîches conservées en artefact. Les résultats de cet audit local restent limités à Chromium ; WebKit est vérifié par ce job distant. Les captures WordPress ci-dessus restent celles du 19 octobre, avant ce correctif ; aucune nouvelle recette WordPress/Elementor ou intervention de production n’est revendiquée.

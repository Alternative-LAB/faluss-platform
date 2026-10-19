# Recette visuelle locale du Studio web

Base : `main` à `6b25c3168c1dac90536f2dadd7b14269f01dfa62`. Les vues proviennent du PHP du plugin. Chromium et WebKit rendent réellement les pages et exécutent les interactions ; les réponses AJAX et leur persistance après rechargement proviennent d’un serveur jetable en mémoire. Le média de la fixture est un SVG synthétique local.

Commande : `node tests/MeStudio/studio-v3-web-composer.js` avec `FALUSS_PHP`, `NODE_PATH` pour Playwright et, si nécessaire, `FALUSS_CHROME` définis. [Résultats complets](results.txt).

| Taille | Attendu et vérifié |
| --- | --- |
| 390 × 844, 768 × 900, 900 × 900 | Studio mobile conservé, panneau inférieur fixe, modal d’aperçu |
| 1024 × 600 | Vue mobile conservée faute de hauteur |
| 1024 × 768, 1440 × 900 | Réglages à gauche, carte défilante à droite ; aucun débordement horizontal |

Sur les deux grandes tailles, un clic souris sous WebKit et un toucher sous Chromium sélectionnent le lien existant, chargent sa rubrique, ouvrent son éditeur exact et empêchent la navigation vers l’URL externe. Une saisie valide met à jour la preview par l’AJAX simulé sans mutation ; Enregistrer est la seule mutation ; le rechargement relit la valeur simulée. Le test de contrat PHP appelle aussi la vraie fonction de preview : identifiant stable, URL HTTPS valide, refus d’URL non HTTPS et aucune écriture du brouillon. Les tests historiques de navigation mobile couvrent retour, historique, focus, conflits et erreurs de relecture.

Captures : [PC Chromium](chromium-studio-1440-900.png), [grande tablette Chromium](chromium-studio-1024-768.png), [téléphone Chromium](chromium-studio-390-844.png), [PC WebKit](webkit-studio-1440-900.png), [grande tablette WebKit](webkit-studio-1024-768.png), [téléphone WebKit](webkit-studio-390-844.png).

Ces captures constituent une recette visuelle réelle du navigateur sur vues PHP, avec médias et réseau simulés. Elles ne prouvent pas une installation WordPress/MariaDB/Elementor, un serveur de mise à jour, une tablette ou un téléphone physique, ni une sauvegarde sur base réelle. Avant publication, vérifier ces points sur une instance WordPress isolée, puis comparer preview et carte publique après sauvegarde et rechargement. Aucun site, donnée ou permission de production n’a été touché.

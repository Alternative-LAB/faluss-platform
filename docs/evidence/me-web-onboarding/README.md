# Recette visuelle locale de l’onboarding web

Cette PR dépend de la PR Studio web #244 pour les identifiants de bloc dans l’aperçu et le brouillon de lien validé côté serveur. Les vues de cette recette proviennent du PHP du plugin. Chromium et WebKit rendent réellement les pages et exécutent les gestes ; les réponses AJAX et leur persistance après rechargement sont simulées par un serveur jetable en mémoire. Le média de la fixture est synthétique.

Commande : `node tests/MeStudio/onboarding-v3-web-composer.js`, avec `FALUSS_PHP`, `NODE_PATH` (Playwright) et `FALUSS_CHROME` (Chrome système) définis. [Résultats détaillés](results.txt).

| Taille | Résultat observé |
| --- | --- |
| 390 × 844, 768 × 900, 900 × 900 | Étape et panneau mobile conservés ; éditeur web masqué |
| 1024 × 600 | Vue mobile conservée faute de hauteur |
| 1024 × 768, 1440 × 900 | Configurateur à gauche, carte à droite, sans débordement horizontal |

Sur les deux grandes tailles, le toucher Chromium et le clic souris WebKit sélectionnent le lien existant, révèlent son éditeur exact et maintiennent l’étape `v3_colors`. Une URL non HTTPS reçoit un refus de prévisualisation et n’entraîne aucune écriture. Après correction, le libellé apparaît immédiatement sur la carte simulée ; l’enregistrement seul déclenche la mutation `update_link` et le rechargement retrouve le libellé. Une modification non enregistrée de l’étape bloque l’ouverture de l’éditeur avec un message explicite. Le passage de 1440 à 768 px puis le retour à 1440 px rétablit respectivement l’étape mobile puis l’éditeur web. Le contrat PHP rejette l’éditeur avant réservation du slug. Les tests mobiles historiques couvrent les étapes Simple et Atomique, le panneau inférieur, le clavier simulé, les médias et la géométrie de la carte publique.

Captures : [PC Chromium](chromium-onboarding-1440-900.png), [lien sélectionné](chromium-onboarding-link-selected.png), [grande tablette Chromium](chromium-onboarding-1024-768.png), [téléphone Chromium](chromium-onboarding-390-844.png), [PC WebKit](webkit-onboarding-1440-900.png), [grande tablette WebKit](webkit-onboarding-1024-768.png), [téléphone WebKit](webkit-onboarding-390-844.png).

Ces captures prouvent le rendu visuel dans de vrais navigateurs sur les vues PHP, avec réseau et persistance simulés. Elles ne prouvent pas une installation WordPress/MariaDB/Elementor, une tablette ou un téléphone physique, ni une écriture sur base réelle. Avant publication, vérifier l’onboarding complet Simple et Atomique sur une instance WordPress isolée, l’édition d’un lien après réservation du slug, une erreur 409 de version, le rechargement, la carte publique et l’archive de mise à jour produite par les scripts existants. Aucun site, donnée ou permission de production n’a été touché.

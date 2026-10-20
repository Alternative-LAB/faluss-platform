# Recette visuelle locale de l’onboarding web

La [recette complémentaire sur WordPress/MariaDB isolé](wordpress-isolated.md) distingue les écritures réelles des réponses simulées ci-dessous.

Cette PR dépend de la PR Studio web #244 pour les identifiants de bloc dans l’aperçu et le brouillon de lien validé côté serveur. Les vues de cette recette proviennent du PHP du plugin. Chromium et WebKit rendent réellement les pages et exécutent les gestes ; les réponses AJAX et leur persistance après rechargement sont simulées par un serveur jetable en mémoire. Le média de la fixture est synthétique. La vue est aussi placée dans un conteneur de 645 px pour reproduire la largeur de contenu imposée par certains thèmes WordPress : le compositeur web doit malgré cela occuper la largeur du viewport.

Commande : `node tests/MeStudio/onboarding-v3-web-composer.js`, avec `FALUSS_PHP`, `NODE_PATH` (Playwright) et `FALUSS_CHROME` (Chrome système) définis. [Résultats détaillés](results.txt).

| Taille | Résultat observé |
| --- | --- |
| 390 × 844, 768 × 900, 900 × 900 | Étape et panneau mobile conservés ; éditeur web masqué |
| 1024 × 600 | Vue mobile conservée faute de hauteur |
| 1024 × 768, 1440 × 900 | Configurateur à gauche, carte à droite, sans débordement horizontal |

Sur les deux grandes tailles, le toucher Chromium et le clic souris WebKit sélectionnent le lien existant, révèlent son éditeur exact et maintiennent l’étape `v3_colors`. Une URL non HTTPS reçoit un refus de prévisualisation et n’entraîne aucune écriture. Après correction, le libellé apparaît immédiatement sur la carte simulée. Le premier enregistrement reçoit HTTP 503 et laisse les champs visibles sans écrire ; le second déclenche la mutation `update_link` et le rechargement retrouve le libellé. Une modification non enregistrée de l’étape bloque l’ouverture de l’éditeur avec un message explicite. Le passage de 1440 à 768 px puis le retour à 1440 px rétablit respectivement l’étape mobile puis l’éditeur web ; si l’étape est modifiée sur tablette, elle reste visible en revenant sur PC, même avec un brouillon de lien en attente. Le contrat PHP rejette l’éditeur avant réservation du slug. Les tests mobiles historiques couvrent les étapes Simple et Atomique, le panneau inférieur, le clavier simulé, les médias et la géométrie de la carte publique.

Captures : [PC Chromium](chromium-onboarding-1440-900.png), [lien sélectionné](chromium-onboarding-link-selected.png), [grande tablette Chromium](chromium-onboarding-1024-768.png), [téléphone Chromium](chromium-onboarding-390-844.png), [PC WebKit](webkit-onboarding-1440-900.png), [grande tablette WebKit](webkit-onboarding-1024-768.png), [téléphone WebKit](webkit-onboarding-390-844.png).

Ces captures prouvent le rendu visuel dans de vrais navigateurs sur les vues PHP, avec réseau et persistance simulés. Elles ne prouvent pas une installation WordPress/MariaDB/Elementor, une tablette ou un téléphone physique, ni une écriture sur base réelle. Avant publication, vérifier l’onboarding complet Simple et Atomique sur une instance WordPress isolée, l’édition d’un lien après réservation du slug, une erreur 409 de version, le rechargement, la carte publique et l’archive de mise à jour produite par les scripts existants. Aucun site, donnée ou permission de production n’a été touché.

## Régression corrigée le 20 octobre

La recette ajoute un en-tête de thème de 280 px sur grand écran. Avant correction, le compositeur était repoussé à `top = 280` dans une page dont le défilement est bloqué ; ses actions pouvaient disparaître sous la fenêtre. Le positionnement web plein écran rétablit `top = 0` et le bas du compositeur à la limite du viewport. Les six tailles Chromium réussissent après avoir reproduit l’échec ; la CI héritée de #244 exécute aussi WebKit et conserve ses captures fraîches dans un artefact temporaire, sans remplacer les preuves historiques versionnées. Les anciennes captures WordPress restent celles du 19 octobre, avant ce correctif ; aucune nouvelle recette WordPress/Elementor ou opération de production n’est revendiquée ici.

Le premier rejeu CI a aussi révélé une course dans le test de contraction de la fenêtre : la commande de redimensionnement peut terminer avant le rappel `matchMedia`. La recette attend désormais que les éditeurs soient masqués et les contrôles d’étape visibles, comme les transitions suivantes le faisaient déjà. Toutes les assertions restent présentes ; aucun délai fixe ni assouplissement du comportement attendu n’est ajouté.

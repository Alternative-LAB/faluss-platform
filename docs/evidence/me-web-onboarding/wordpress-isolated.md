# Recette Me sur WordPress isolé — 19 octobre 2026

## Instance et installation

Docker Desktop ne présentait pas de moteur : `docker context show` retournait `desktop-linux`, les distributions `Ubuntu` et `docker-desktop` étaient arrêtées, et `docker version` répondait `failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: The system cannot find the file specified.` Après ces diagnostics en lecture seule, la recette a utilisé une nouvelle instance privée dans WSL Ubuntu : WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6, thème Twenty Twenty-Five, comptes et base jetables. Aucun serveur ni donnée de production.

Le paquet `0.14.0` a été construit avec `scripts/build-release.sh`, validé par le dépôt et installé puis mis à jour avec `wp plugin install --force --activate`, via les API WordPress. SHA-256 de l’archive testée : `4ec9b460c6d2caa527df697c9f54b5380975b6665359689262a8351ab584db11`. L’instance bloque volontairement les connexions sortantes WordPress.org ; ses avertissements de vérification des mises à jour ne constituent pas une preuve du serveur de mise à jour. Aucun script de publication ni mécanisme de mise à jour du plugin n’a été modifié.

## Gestes et persistance réellement vérifiés

- Deux comptes neufs ont parcouru en Chrome les étapes V3 complètes Simple et Atomique depuis le choix du mode jusqu’à la publication. Les retours d’étape, les médias, les réseaux, les deux liens ordonnés, les réglages Atomique, le rechargement et le rendu de révision ont été contrôlés par `tests/MeStudio/onboarding-v3-wordpress-recipe.js`. Un second navigateur, parti de la même version, a reçu `409` avec `stale_version` après une modification concurrente. Un fichier non image a reçu `400` avec `unsupported_image`. La première publication et sa répétition idempotente ont chacune répondu `200`; la page rechargée a montré « Ton Faluss est en ligne ».
- Sur deux autres comptes non publiés, un lien a été enregistré dans l’onboarding puis sélectionné au toucher depuis la carte à droite. Son éditeur s’est ouvert à gauche ; le libellé modifié par `faluss_studio_v3_manage` a été retrouvé après rechargement, avec le même identifiant de bloc.
- Pour Simple et Atomique publiés, le Studio a été ouvert sur la même instance : sélection tactile d’un lien, édition, AJAX `200`, réouverture de la page et comparaison des libellés, URLs et ordre avec la page publique racine et avec `[faluss_link_card]` sur des pages locales. Les données concordent. Les captures Studio et le détail de ce contrôle sont dans [la recette de #244](../me-web-studio/wordpress-isolated.md).

| Largeur × hauteur | Onboarding vérifié |
| --- | --- |
| 1440 × 900, 1024 × 768, 980 × 650 | Configurateur à gauche, aperçu à droite, largeur complète, aucun débordement horizontal |
| 768 × 900, 390 × 844 | Vue mobile et panneau inférieur conservés, aucun débordement horizontal |

Captures Chrome sur le vrai WordPress : [Simple PC](wordpress-isolated/onboarding-layout_simple-1440x900.png), [Atomique PC](wordpress-isolated/onboarding-layout_atomic-1440x900.png), [grande tablette](wordpress-isolated/onboarding-layout_atomic-1024x768.png), [petite tablette](wordpress-isolated/onboarding-layout_atomic-768x900.png), [Simple téléphone](wordpress-isolated/onboarding-layout_simple-390x844.png), [Atomique téléphone](wordpress-isolated/onboarding-layout_atomic-390x844.png).

## Limites

Le thème de l’instance contraignait le shortcode à 645 px sur PC ; la recette a révélé puis vérifié le correctif CSS qui restitue la largeur du viewport. L’en-tête Twenty Twenty-Five reste au-dessus du compositeur, car il ne s’agit pas d’une page Elementor Canvas. Sans Elementor, l’URL publique racine utilise le rendu de secours Identity : le nom, l’identifiant, l’avatar et les liens correspondent à la révision, mais cette recette ne prouve pas une parité visuelle de cette page avec la carte canonique. Le shortcode canonique a été vérifié séparément. Les tailles et gestes proviennent de Chrome sur Windows, pas de tablettes ou téléphones physiques. Les tests Chromium/WebKit sur vues PHP avec réseau simulé restent décrits dans [la recette navigateur](README.md) et ne sont pas comptés comme des écritures WordPress.

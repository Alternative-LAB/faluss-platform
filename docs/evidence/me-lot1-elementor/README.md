# Lot 1 Me — recette Elementor sur WordPress isolé, 20 octobre 2026

Cette recette complète les preuves des PR [#244](https://github.com/Alternative-LAB/faluss-platform/pull/244) et [#245](https://github.com/Alternative-LAB/faluss-platform/pull/245). Elle porte sur leur `main` fusionné, SHA `89c4405ce5cebd7d30dd73793f7f7b116acbe26f`, après les deux corrections de hauteur du 20 octobre. Aucun site ni compte de production n'a été utilisé.

## Instance et installation

Instance privée WSL Ubuntu en boucle locale : WordPress 7.1.2, MariaDB 11.8.6, PHP 8.5.4, thème Twenty Twenty-Five, Elementor 4.3.1, Chrome Windows. L'archive du plugin a été construite par `scripts/build-release.sh`, puis validée par `scripts/validate-release.py` : SHA-256 `d3b68b0be9506cd6f9bb58c84545a5ddfa352ff99e9efa698504007a5d884175`. La copie source temporaire a été normalisée en LF pour exécuter le script Bash sous WSL ; le dépôt et les scripts de publication sont inchangés. Le ZIP Elementor officiel 4.3.1, vérifié par `unzip -t`, a pour SHA-256 `9e947ce507a6c76d22ec7c710da96b65ecc7da18703dc662a6940f5443491c8d`.

Les deux plugins ont été installés ou mis à jour avec `wp plugin install` via WP-CLI. Une page modèle Elementor locale contenant le widget `faluss_link_card` a été créée avec `wp_insert_post` et `update_post_meta`, puis choisie comme modèle de la route publique par `update_option`. Le widget était enregistré (`widgetRegistered=true`) et la route publique rendait `.faluss-identity-profile-page--elementor`. Le blocage HTTP sortant de cette instance provoque des avertissements de vérification WordPress.org et du serveur de mises à jour ; il ne teste pas la distribution privée. Aucun droit global, dossier de plugin ni donnée de production n'a été modifié directement.

## Résultats réellement observés

- Pour des cartes publiées **Simple** et **Atomique**, `tests/MeStudio/onboarding-v3-wordpress-parity.js` a comparé la route publique, `[faluss_link_card]` et le widget Elementor. Nom, identifiant, ordre et URLs des liens, réseaux, avatar, couverture, classes de présentation et variables CSS concordent.
- À 1440 × 900, 1024 × 768, 768 × 900 et 390 × 844, la route publique Elementor occupe la largeur et la hauteur de la fenêtre sans débordement horizontal ; les images sont chargées. Les couleurs, typographies et styles calculés de la carte correspondent au widget placé sur une page Elementor locale. Les [mesures publiques et widget](measurements.json) sont conservées.
- Sur le SHA fusionné, le Studio et l'onboarding Simple/Atomique ont été ouverts dans ce WordPress : à 1440 et 1024 px, configurateur à gauche, aperçu à droite et racine de `top=0` jusqu'au bas du viewport ; clic souris à 1440 px et toucher à 1024 px sélectionnent le même identifiant de lien et ouvrent son éditeur. À 768 et 390 px, la vue mobile reste active, sans débordement. Le thème centre le composant mobile dans 645 px à 768 px ; à 390 px il occupe toute la largeur. Voir les [mesures Studio](composer-measurements.json) et [onboarding](onboarding-measurements.json).
- Pour les deux cartes publiées, une modification du libellé dans le Studio a mis à jour l'aperçu immédiat tandis que la route publique gardait l'ancienne valeur. `faluss_studio_v3_manage` a répondu HTTP 200 à l'enregistrement. Après rechargement, le même identifiant de bloc et le nouveau libellé apparaissaient dans le Studio, la route publique Elementor et le widget. Les libellés de test sont « Elementor persistant simple » et « Elementor persistant atomic ».

| Vue inspectée | PC ou grande tablette | Téléphone |
| --- | --- | --- |
| Route publique Elementor | [Simple 1440](simple-public-1440x900.png), [Atomique 1024](atomic-public-1024x768.png) | [Atomique 390](atomic-public-390x844.png) |
| Widget sur page Elementor Canvas | [Simple 1440](simple-widget-1440x900.png) | [Atomique 390](atomic-widget-390x844.png) |
| Studio, après la sauvegarde | [Simple 1440](studio-simple-1440x900.png), [Atomique 1024](studio-atomic-1024x768.png) | [Simple 390](studio-simple-390x844.png) |
| Onboarding avant publication | [Simple 1440](onboarding-simple-1440x900.png), [Atomique 1024](onboarding-atomic-1024x768.png) | [Atomique 390](onboarding-atomic-390x844.png) |

## Limites avant publication

La page Elementor locale est un modèle minimal, différent du modèle, des styles et des éventuels en-têtes Elementor Pro du site cible. Sur la page autonome du widget, le conteneur d'exemple le limite à 560 px sur PC ; la **route publique** testée occupe bien la fenêtre. Les ressources externes sont bloquées pendant la recette ; elle ne valide donc pas les polices ou caches externes. Les gestes et captures viennent de Chrome sur Windows à des tailles de viewport, pas de tablettes ou téléphones physiques. Le mécanisme de mise à jour privée et l'installation sur les sites cibles ne sont pas testés ici. Aucune publication du plugin n'a été faite.

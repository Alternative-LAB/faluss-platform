# Catégorie d'activité Me — preuve locale

Les quatre captures montrent les vues PHP V3 dans Chromium avec les styles du plugin, à 390 × 844 et 1440 × 900. Elles proviennent des recettes `onboarding-v3-web-composer.js` et `studio-v3-web-composer.js` sur une page et des réponses HTTP simulées en mémoire. Elles ne prouvent ni une écriture WordPress/MariaDB, ni le modèle Elementor installé sur faluss.me.

Les recettes navigateur ont passé les largeurs 390, 768, 900, 1024 × 600, 1024 × 768 et 1440 pixels dans Chromium et WebKit. Elles contrôlent la présence des cinq choix, le panneau mobile et la disposition gauche/droite lorsque la hauteur le permet. Les recettes existantes continuent de vérifier la sélection des liens à la souris et au toucher, le brouillon, le refus d'une URL invalide et le retour après sauvegarde dans leur stockage simulé.

`v3-composition-regression.php` exerce la sauvegarde de la catégorie, sa version agrégée, les conflits 409, les valeurs rejetées et sa conservation lors des sauvegardes V3 et des préférences historiques avec une base en mémoire. Le bloc contextuel facultatif et son rendu public appartiennent à la PR suivante.

Sur Windows, `onboarding-v3-visual-test.js` s'arrête à l'assertion existante « Full cover does not extend behind links ». Le même échec a été reproduit sur la branche documentaire #250, sans ces changements. La recette mobile ciblée `v3-mobile-regression.js` passe dans Chromium et WebKit, mais elle ne remplace ni un appareil physique ni la recette WordPress isolée.

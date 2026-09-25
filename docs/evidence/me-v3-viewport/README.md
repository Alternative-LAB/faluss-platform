# Correctif 0.5.1 — page publique et éditions du Studio

## Cause et correction

Dans la preuve de la PR #58, seule la carte avait été rendue et mesurée : hauteur minimale 440 px, enveloppe arrondie, corps HTML par défaut. Cela ne vérifiait ni le shell Identity ni le bas du document. La carte canonique remplit maintenant au minimum le viewport dynamique, sans cadre, et suit la hauteur du contenu. Le shell public garde le fond autour d’un contenu de largeur de lecture limitée. L’aperçu fixe la même hauteur logique avant sa mise à l’échelle.

Une seule nouvelle vérification de **page entière** : `tests/MeStudio/v3-public-page-regression.js`, Chromium 390 × 844, avec contenu court puis débordant. Elle charge les CSS du shell Identity et de Link, le HTML du renderer PHP commun et vérifie le rectangle extérieur, les coins, le bas du viewport/document, l’absence de bordure, d’arrondi, d’ombre et de débordement horizontal. Une seule capture est conservée : [public-390.png](public-390.png).

Cette image utilise un **média SVG synthétique**, un profil de test et un double de transaction en mémoire. Elle prouve la géométrie locale, pas une recette WordPress, Elementor, catalogue officiel ou téléphone physique. Aucun site Faluss n’a été ouvert ou modifié pour ce correctif.

## Comparaison ciblée des fonctions

Sources relues : `LegacyLinkService::render_studio`, panneaux d’édition et contrats de mutations ; `StudioRenderer::studioExtension` ; les dix rubriques de `OnboardingV3` en 0.5.0. Pas d’audit global.

| Fonction antérieure | Dix rubriques V3 en 0.5.0 | Accès en 0.5.1 |
|---|---|---|
| Nom, avatar, réseaux, couleurs, formes et textures | Présents | Conservés |
| Collections : créer, renommer, décrire, dissoudre sans supprimer les liens | Inaccessibles | **Collections**, mutations existantes |
| Ajouter un lien dans une collection | Inaccessible | **Liens**, choix de la collection |
| Image, visibilité et suppression d’un lien | Inaccessibles | **Liens**, édition unitaire |
| Ordre complet des blocs et placement entre collections | Réordonnancement limité aux liens | **Contenus et ordre**, liste complète et validation de tous les UUID |
| Texte et teaser : texte, image, format et accès | Helpers historiques présents mais déjà non raccordés dans `render_studio` ; aucun accès V3 | **Contenus et ordre**, CRUD natif ; aucune prétention à un service de contenu protégé |
| Bio et publication/brouillon après onboarding | Inaccessibles | **Réglages** |
| Disponible, visibilité avatar, alignement, disposition réseaux | Inaccessibles | **Réglages** |
| Catalogue de thèmes, retrait couverture/avatar, transition couleur/intensité/position | Inaccessibles | **Réglages**, contrôle serveur des thèmes et même gradient public/aperçu |
| Toutes les polices et espacement du nom | Choix restreint | **Réglages** ; la graisse V3 reste dans **Nom** |
| Bordure d’avatar ; logos pleins/contours | Équivalents V3 présents | **Avatar → Effets**, **Style des réseaux** |
| Grille d’images et largeur des liens (extension V2) | Inaccessibles | **Réglages** |
| Teinte rose, menthe et couleur unie (extension V2) | Absentes des six choix V3 | **Réglages → Tous les styles des réseaux** |
| URL publique / accès accueil et liste | Pas de raccourci Studio | **Réglages**, URL sélectionnable et liens |
| Partage natif du navigateur, panneau promotionnel écosystème, liste descriptive des extensions | Non repris dans V3 | Non réintroduits : raccourcis/lecture seule, aucune édition métier concernée |
| Annonce et mode bio historiques | Champs cachés dans le dernier Studio V1/V2, déjà sans éditeur | Valeurs conservées ; hors des éditions perdues avec V3 |

Le Studio passe à treize rubriques, sans renvoi vers un écran V1/V2. Les données restent dans Identity/Link. Les champs non soumis et les blocs non visés sont conservés. Les liens historiques non initialisés conservent leur restriction de mutation préexistante ; aucune migration implicite.

## Scénarios et résultats locaux

- **Page entière** : court et débordant, 390 × 844, bords et bas du document couverts — réussi ; capture inspectée.
- **Transactions** (`v3-composition-regression.php`) : créer/modifier/supprimer texte et média propriétaire ; créer/renommer/dissoudre une collection ; ajouter/modifier/supprimer son lien, visibilité et image ; ordre complet ; bio/publication, en-tête, grille et transition — réussis. Tous les blocs initiaux restent identiques après le cycle.
- **Cas négatifs** : média étranger, type/accès invalide, droit vide, bloc absent, ordre partiel et version périmée — refus/rollback ; 409 conserve l’agrégat.
- **Client natif** (`v3-studio-management-regression.js`) : formulaire réellement rendu par PHP, payload ciblé, nonce, UUID distincts, un seul appel ; réponse AJAX 409 simulée, champs conservés et bouton réactivé — réussi. Ce serveur AJAX simulé ne constitue pas une réponse de production.
- PHPUnit ciblé `MeStudio|LinkCharacterization|PluginVersion` : 20 tests, 344 assertions — réussi.
- PHPStan, lint PHP des fichiers modifiés, syntaxe JavaScript, scan de secrets ciblé et `git diff --check` : résultats consignés dans la PR.

Le renderer et les styles de composition sont communs aux contextes aperçu/public/shortcode/widget. L’enveloppe de page complète est désormais vérifiée séparément de l’intérieur de la carte. **Elementor réel, Safari sur téléphone et WordPress/MariaDB ne sont pas exécutés dans ce lot.** Aucun flag, fichier, plugin ni donnée WordPress n’a été modifié. Aucun ancien incident de production n’est déclaré résolu sur la base de ces tests locaux.

## Reproduction

Configurer `FALUSS_PHP` vers PHP, `NODE_PATH` vers les dépendances Playwright et, si nécessaire, `FALUSS_CHROME` vers Chromium/Chrome.

```text
php tests/MeStudio/v3-composition-regression.php
node tests/MeStudio/v3-public-page-regression.js
node tests/MeStudio/v3-studio-management-regression.js
php vendor/bin/phpunit --filter 'MeStudio|LinkCharacterization|PluginVersion'
php vendor/bin/phpstan analyse --no-progress
```

## Compatibilité et retour arrière

Version distribuable 0.5.1 ; version d’assets 3.1.1. Aucune table, option ou migration ajoutée. Les nouvelles mutations réutilisent la transaction et la projection Identity existantes ; les CRUD historiques restent disponibles. Aucun changement Fans, SSO, cron, rôles ou configuration des sites. Les marges imposées par un thème Elementor extérieur nécessitent une vérification réelle par l’utilisateur.

Un retour aux fichiers 0.5.0 est compatible avec les données, au prix du retour de la bande et des accès manquants. Aucune restauration de base nécessaire pour ce changement. Livraison et artefact par GitHub ; installation et tests réels laissés à l’utilisateur.

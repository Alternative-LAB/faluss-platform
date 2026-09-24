# Recette fonctionnelle V3 locale — 24 septembre 2026

## Environnement et portée

WordPress 7.1.2, MariaDB 11.8.6, PHP 8.5.4, Elementor 4.3.1 et le plugin Faluss de cette branche ont été exécutés dans Ubuntu WSL. La route testée est `/commencer/`, avec les modules Identity, Catalog, Token Engine Connector, Link, Apps Registry et Onboarding V3 activés **uniquement dans la configuration locale non versionnée**. Les comptes de recette ont été créés et marqués actifs localement ; le flux initial de vérification passwordless n'a pas été rejoué. Aucun staging, compte de production, média de production ou flag de production n'a été modifié.

Les scripts reproductibles sont `tests/MeStudio/onboarding-v3-wordpress-recipe.js`, `onboarding-v3-wordpress-mobile.js` et `onboarding-v3-wordpress-parity.js`. Ils prennent l'URL et les identifiants locaux en variables d'environnement. Les anciens curseurs ont été injectés un à un dans l'état Identity local puis rendus par `Faluss_Identity_Onboarding::render()` ; les 19 valeurs historiques testées ont affiché un écran V3, sans écran V1/V2. Les tests de contrat V3 couvrent aussi la sauvegarde du brouillon d'identité, son cloisonnement entre comptes, l'avatar étranger refusé et la version obsolète HTTP 409.

## Parcours et rendu

| Scénario | Résultat observé |
| --- | --- |
| Simple, nouveau profil `v3-simple-mufyl2re` | Identité conservée après rechargement et Retour ; avatar PNG WordPress accepté (pièce jointe 11) ; Réseaux et Liens sauvegardés ; Passer, Retour, ordre des liens et rechargements vérifiés ; publication HTTP 200 puis second appel HTTP 200, même URL. |
| Atomique, profil repris `v3-atomic-mufynuik` | Couleurs, boutons, avatar, couverture, nom et style des réseaux parcourus avec Retours, Passer sur les étapes facultatives et rechargements. Avatar PNG accepté (pièce jointe 12) et couverture PNG acceptée (pièce jointe 17). Publication HTTP 200 puis second appel HTTP 200, même URL. |
| Rechargement Rythme et Identité, nouveau profil local distinct | Rythme reste en V3 après rechargement. Après Retour vers Rythme, le nom et le slug saisi restent présents. Un avatar PNG uploadé (pièce jointe 24) est enregistré dans le brouillon avant le message « Image prête » ; nom, slug et avatar reviennent ensemble après rechargement, puis le parcours avance vers Réseaux. |
| Résolution Link V4 | Un défaut découvert pendant la recette faisait perdre l'override `page_background` après Retour. Après correction, `#DED4E4` reste sélectionné et rendu, avec `button_color=#FF515B`, nom `#F54955`, police système et poids 400 jusque dans la carte publique. |
| Aperçu et surfaces | Pour les deux modes, nom, identifiant, liens et ordre, URL et images sociales, avatar, couverture, classes visuelles et variables CSS de l'aperçu final sont égaux à la carte publique. La carte publique est égale au shortcode et au **widget Elementor réel** sur les mêmes champs. Une ligne de carte par slug a été constatée dans MariaDB après le double appel de publication. |

Les médias de réseaux Instagram et Telegram sont deux vrais fichiers PNG chargés dans le **catalogue Link local**, puis servis par WordPress. Ils proviennent respectivement de [Wikimedia Commons Instagram](https://commons.wikimedia.org/wiki/File:Instagram_icon.png) et [Wikimedia Commons Telegram](https://commons.wikimedia.org/wiki/File:Telegram_blue_icon.png). Les huit autres entrées du catalogue local n'avaient pas de média : l'interface l'a signalé. Cette configuration locale ne prouve pas l'état ni la provenance des médias du catalogue de production.

## Mobile et clavier

Sur la vraie route WordPress, des gestes tactiles Chromium ont été rejoués à 320, 390 et 768 px. À chaque largeur, le premier mouvement a agrandi le panneau Réseaux sans déplacer son contenu ; les mouvements suivants ont atteint sa limite exacte de défilement (respectivement `scrollTop=747`, `754`, `794`). À 390 × 500 px, une mise au point dans un champ simulant la réduction de viewport par le clavier a conservé le bouton d'action visible (`bottom=486`). [Panneau agrandi](wordpress-networks-390-expanded.png) et [panneau entièrement défilé](wordpress-networks-390-scrolled.png). Le clavier logiciel d'un appareil physique, VoiceOver et TalkBack n'ont pas été essayés.

## Échecs HTTP et traces conservées

Les deux sessions concurrentes partaient de la même version. Après sauvegarde dans la première, la seconde a reçu le conflit attendu. Un fichier `refuse.txt` déclaré `text/plain` a été rejeté par WordPress. Extraits bruts :

| Mode | Échec | Capture navigateur UTC | HTTP et réponse brute | Trace serveur locale CEST |
| --- | --- | --- | --- | --- |
| Simple | Publication de la session périmée | `2026-09-24T20:03:18.761Z` | `409 {"success":false,"data":{"code":"stale_version"}}` | `[Thu Sep 24 22:03:16 2026] 127.0.0.1:54940 [409]: POST /wp-admin/admin-ajax.php` |
| Simple | Upload `refuse.txt` | `2026-09-24T20:03:18.761Z` | `400 {"success":false,"data":{"code":"unsupported_image","message":"Format d\u2019image non reconnu par WordPress."}}` | `[Thu Sep 24 22:03:16 2026] 127.0.0.1:54952 [400]: POST /wp-admin/admin-ajax.php` |
| Atomique | Publication de la session périmée | `2026-09-24T20:27:10.197Z` | `409 {"success":false,"data":{"code":"stale_version"}}` | `[Thu Sep 24 22:27:10 2026] 127.0.0.1:51682 [409]: POST /wp-admin/admin-ajax.php` |
| Atomique | Upload `refuse.txt` | `2026-09-24T20:27:10.424Z` | `400 {"success":false,"data":{"code":"unsupported_image","message":"Format d\u2019image non reconnu par WordPress."}}` | `[Thu Sep 24 22:27:10 2026] 127.0.0.1:51694 [400]: POST /wp-admin/admin-ajax.php` |

Le code `unsupported_image` correspond au type de fichier refusé ; `stale_version` correspond à la version changée par l'autre session. Aucun échec n'a été observé pour les uploads PNG ni pour la publication en première session dans ce banc. Les échecs d'upload et de publication rapportés en production restent sans réponse HTTP brute ni logs accessibles ; leur cause ne peut pas être déduite de cette recette locale.

## Limites avant une activation éventuelle

Le staging et ses intégrations, les médias officiels complets du catalogue, les logs de production, le vrai clavier mobile et les lecteurs d'écran restent à vérifier. Les formats JPEG/GIF/WebP, les quotas ou dossiers d'upload indisponibles et les incidents de publication de production ne sont pas couverts par les deux uploads PNG et le fichier texte rejeté. Les captures de la [maquette exécutable](README.md) sont une vérification visuelle synthétique séparée de cette recette WordPress.

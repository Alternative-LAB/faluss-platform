# Recette ciblée Me — 0.6.3

Base : `d888f9cec3bfe088166a4a52ba10df610eb26bec` (main, 0.6.2). Recette locale du 29 septembre 2026. Aucun accès aux sites, aucune installation WordPress, aucun changement de flag.

## Causes et corrections

| Point | Cause dans la source | Correction et scénario positif/négatif |
| --- | --- | --- |
| Focus onboarding | Le focus conservait la hauteur repliée et le footer sous le clavier. | Déploiement synchrone sans transition, espace du clavier réservé dans le panneau ancré, champ révélé par scroll interne instantané. Identité, Réseaux et dernier champ de cinq liens : focus depuis replié et depuis déployé, 50 événements resize/scroll, clavier simulé, fermeture et saisie conservée. Pas de scroll de fenêtre ; header et bas du panneau stables ; actions au-dessus du clavier. Dernier regard reste compact hors saisie. |
| Studio | Le focus programmatique après navigation pouvait conserver le contour clavier ; header non sticky. | Modalité pointeur/clavier explicite, header sticky opaque et marges de scroll. Contour absent après clic, présent au clavier ; longue rubrique et hauteurs 844/690/844 ; navigation dynamique, historique, modifications non enregistrées, conflits et repli sans JS conservés. |
| Page publique | Fond racine crème indépendant du fond choisi, hauteur liée au viewport réduit, espacements possibles des hôtes. | Fond SSR et theme-color sur la seule route publique ; hôtes WordPress/Elementor ciblés ; minimum 100lvh, contenu extensible, marges de sécurité. Cartes courtes/longues Compact/Cover, viewport variable et extrémités du scroll, document complet et wrappers mesurés. Aperçu embarqué sans recoloration du document ; couleur SSR contrôlée sans JS. |
| Handle et bio | Contraste automatique sans choix commun explicite du membre. | Un contrôle dans Design → Nom, blanc/noir/couleur personnalisée, « Actuelle » conserve le rendu antérieur. Aperçu, sauvegarde et relecture concordent ; nom, liens et autres textes inchangés. Valeurs invalides refusées sans écriture. |
| Bordures illustrées | Bordure claire imposée par le rendu des liens. | Contrôle global dans la présentation des liens, défaut sans bordure, activable avec couleur. Vedette et autres tuiles mesurées, aperçu/sauvegarde/relecture concordants ; mode liste et contenus conservés. Valeurs invalides refusées, version périmée : 409. |

## Vérifications exécutées

- PHPStan : succès.
- PHPUnit ciblé : MeStudio **17 tests / 258 assertions**, Link **19 tests / 215 assertions**, soit **36 tests / 473 assertions**. Le nouveau test appelle les vrais contrats de rendu et de mutation sur des doubles WordPress/base en mémoire : anciennes compositions exactes, absence de migration à la lecture, sauvegarde indépendante sans nouvelles clés par défaut, nouvelles préférences, JSON malformé refusé sans fatal, validation négative et conflit.
- `php -l` sur les dix PHP modifiés/ajoutés ; `node --check` sur les six JS modifiés/ajoutés ; contrat `tests/Link/link-hotfix-autosave-test.js` : succès.
- Trois scripts navigateur exécutés sur **Chromium et WebKit**, largeurs **320, 390 et 768 px** : `v3-mobile-regression.js`, `v3-studio-navigation-regression.js`, `v3-controls-regression.js`. Pour le public, hauteurs 690/844/932 et insets 44/34 simulés ; pour l'onboarding, hauteur supplémentaire 690 et clavier 350/480 simulé.
- La navigation Studio vérifie aussi les interactions avec les nouveaux contrôles et le contenu transmis à l'aperçu, puis la sauvegarde canonique. Les erreurs 409/503 et les réponses obsolètes sont injectées dans le banc, pas observées sur un site.
- `git diff --check`, inspection du périmètre et scan de secrets ciblé avant commit. La CI complète et le package officiel sont référencés dans la PR.

### Traces et captures

- [Onboarding et géométrie](browser-results.txt), [Studio](studio-results.txt), [couleurs, bordures et document public entier](controls-results.txt).
- [Identité](onboarding-v3_identity-390.png), [Réseaux](onboarding-v3_socials-390.png), [Liens](onboarding-v3_links-390.png), [Dernier regard](onboarding-v3_review-390.png) : après fermeture du clavier simulé.
- [Studio Chromium](chromium-studio-design-390.png), [Studio WebKit](webkit-studio-design-390.png).
- [Page publique entière Chromium](chromium-public-cover-390.png), [WebKit](webkit-public-cover-390.png) : wallpaper synthétique, couleur commune blanche, bordures désactivées, insets simulés. `public-390.png` est la comparaison interne historique ; les deux captures `*-public-cover-390.png` et les assertions de document complet apportent la preuve de couverture du viewport.

## Limites et recette iPhone après installation par le propriétaire

Les scripts utilisent le PHP réel du plugin avec des doubles en mémoire, des médias synthétiques et des wrappers Elementor simulés. Ils ne constituent pas une recette WordPress/MariaDB/Elementor réel, ni une validation d'un clavier logiciel ou du chrome Safari. Aucun catalogue officiel ni compte membre réel n'a été consulté.

Sur iPhone réel, vérifier les trois étapes de saisie repliées puis déployées, un champ bas, clavier ouvert/fermé, saisie conservée et actions accessibles. Vérifier la longue rubrique Studio, le header, la navigation tactile sans contour violet et les boutons précédent/suivant. Sur une carte courte puis longue, vérifier Compact/Cover, le scroll avec barres Safari ouvertes/réduites, la couleur commune et les bordures après sauvegarde/rechargement. Comparer au shortcode et au widget Elementor réels.

La page remplit sa **surface dessinable** et fournit `theme-color` ; elle ne dessine pas elle-même les barres ni les boutons Safari. Le comportement de ces barres reste à confirmer sur l'appareil. Le mode installé n'a pas été installé : seuls ses insets ont été simulés ; sa vérification réelle est pertinente si ce mode est utilisé.

## Compatibilité et retour arrière

Les lectures ne réécrivent pas les données. Les trois préférences sont facultatives et leurs valeurs par défaut sont omises dans le JSON existant. Aucune table, migration, route, cron ou permission ajoutée ; SSO/OTP, collections, Fans, Hub, moteurs de points et flags inchangés. Le hook `wp_head` est limité aux profils publics publiés ; il ne modifie pas les pages privées ou les aperçus.

**Ne pas rétrograder aveuglément après l'utilisation des nouveaux réglages.** Le validateur exact de 0.6.2 ne reconnaît pas leurs clés non standard. Avant de remettre 0.6.2, enregistrer sous 0.6.3 les cartes concernées avec couleur handle/bio « Actuelle », bordure désactivée et couleur de bordure blanche. Les clés redeviennent absentes ; les liens et collections restent conservés. Cette procédure n'a été exécutée que dans le banc local, pas sur des données membres.

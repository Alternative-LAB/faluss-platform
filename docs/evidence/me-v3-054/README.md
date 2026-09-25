# Recette 0.5.4 — scénarios ciblés

Ce lot part de main 6cfe591 (0.5.3). Le propriétaire valide sur iPhone le retour du Studio, le parcours complet et la carte publique de la livraison précédente. Aucun changement de carte, données, Fans, parcours ou flag.

## Scénarios à vérifier

- Identité et Réseaux : focus puis clavier simulé, repositionnement interne dès la prochaine frame ; rafale de resize/scroll sans boucle de correction de la fenêtre. Champ visible, header/panneau/bouton inchangés ; fermeture et expansion manuelle conservées.
- Studio : liens natifs avec/sans JS, changement de navigation principale/contextuelle sans nouvelle navigation document ; pill unique qui se déplace, même sous défilement horizontal ; réduction des animations selon prefers-reduced-motion.
- URL directe, précédent/suivant et clavier : rubrique et aria-current synchronisés ; annulation d’un retour avec saisie conserve URL et valeurs.
- Saisie modifiée/restituée ; sauvegarde réussie puis état canonique relu ; 409 et panne réseau conservent les champs. Clics rapides : aucune réponse obsolète n’écrase la rubrique suivante. Upload/sauvegarde en cours : navigation empêchée.
- Shop reste indisponible. Aucun nouveau contrat de mutation ni stockage navigateur des données membres.

Recette navigateur locale avec contrôles PHP réels et réponses HTTP simulées, sans WordPress ni accès aux sites. La sensation du clavier Safari/iPhone reste à valider par le propriétaire.

## Résultats

- Chromium et WebKit, 320/390/768 × 844 et 390 × 690 : [focus et géométrie](browser-results.txt). 50 événements resize/scroll, smooth scroll injecté, champ visible dès la frame suivante, au plus une correction interne et zéro correction de fenêtre. Fermeture, expansion manuelle et défilement entier conservés.
- Chromium et WebKit, 320/390/768 : [navigation Studio](studio-results.txt). Un seul chargement document pour l’ensemble du scénario, URL directe, historique avant/arrière et annulation avec brouillon, clavier, pill persistante/alignée, reduced motion et repli sans JavaScript.
- Saisies restituées : pas d’alerte ; sauvegarde confirmée : valeur canonique rechargée. HTTP 409 et 503 simulés conservent les champs. Sauvegarde/upload en attente : sortie empêchée. Relecture échouée après sauvegarde : bouton Actualiser, aucune seconde mutation. Clics rapides : réponse ancienne ignorée.
- Contrôles PHP utilisés par le Studio réel, banc HTTP simulé. Le fichier upload du test est un contenu client factice, **aucune recette média ou WordPress revendiquée**.
- [Capture Chromium](chromium-studio-design-390.png), [capture WebKit](webkit-studio-design-390.png). Les captures montrent le résultat de navigation ; les assertions vérifient l’identité du nœud de pill, ses coordonnées et ses propriétés d’animation.

## Limites

Le propriétaire a validé la livraison précédente sur iPhone : Studio rétabli, onboarding terminé d’une traite, carte publique conforme. Ce lot ne rouvre pas ces chantiers. Les événements de clavier sont simulés : la durée ressentie et le panoramique natif du clavier iPhone seront vérifiés par le propriétaire après installation. Aucun accès aux sites, aucune installation WordPress, aucun changement de flag.

## Rejouer

`FALUSS_PHP` désigne PHP, `FALUSS_CHROME` Chrome installé et `NODE_PATH` le dossier contenant Playwright. Exécuter `node tests/MeStudio/v3-mobile-regression.js` et `node tests/MeStudio/v3-studio-navigation-regression.js`. Chromium et WebKit doivent être disponibles. PHPUnit MeStudio/PluginVersion et PHPStan complètent ces contrôles ; `php -l`, syntaxe JS, scan de secrets ciblé et `git diff --check` sont requis avant livraison.

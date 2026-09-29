# Lot 1 — Classement Fans et navigation

Date : 29 septembre 2026. Base : `ad7c5857a0c1d65c84d8ec56b5fdbbd7dba178af`
(0.6.3), avec le diff de la PR de ce lot. Aucun site réel consulté.

## Environnement et preuve

- Export Git LF de la base, puis application des fichiers du diff, dans un
  répertoire WSL isolé. `vendor` copié physiquement ; les 31 dépendances et leurs
  références correspondent à `composer.lock`. Aucun autoload par jonction.
- PHP 8.5.4 : lint des trois PHP modifiés ; suite complète **279 tests,
  4 152 assertions**, aucun échec, deux dépréciations ; PHPStan complet sans erreur.
- Serveur PHP de test et adaptateurs simulés de `recipe/router.php`, Chrome
  headless via Playwright. **Ce n’est pas une installation WordPress**. Les
  cookies de rôles et les réponses API sont des fixtures locales de test.
- 1440 × 900 et 390 × 844 ; contrôle supplémentaire à 320 × 640 avec mouvement
  réduit. Routes positives/négatives, six accès Fan, huit accès Créateur,
  clavier, un seul actif, absence de débordement horizontal.
- Classement : invité/non lié/admin non lié 403 ; Fan/Créateur liés sur route
  Fan 200 ; route Créateur inexistante 404. Aucun score, rang, PC, montant,
  tableau, formulaire ou appel d’API de classement.
- Régression : Explorer → profil, filtres, vide/erreur API, HoF invité, profil
  inexistant, autres routes Créateur. Labels Créateur masqués sauf actif, noms
  accessibles toujours présents. La recette WordPress antérieure est adaptée
  au nombre de liens mais **n’est pas exécutée dans ce chantier**.

## Captures et comparaison aux planches V2

| Surface | Ordinateur | Mobile |
| --- | --- | --- |
| Classement Fans, service absent | [capture](classement-fans-desktop.png) | [haut](classement-fans-mobile.png), [bas après défilement](classement-fans-mobile-bottom.png) |
| Créer, navigation à huit accès | [capture](creer-desktop.png) | [haut](creer-mobile.png), [bas](creer-mobile-bottom.png) |
| Explorer et profil, fixtures anonymes | [Explorer](explorer-desktop.png), [profil](profil-public-desktop.png) | [Explorer vide](explorer-mobile-empty.png) |

Inspection visuelle : fond sombre, accents verts, bandeau crème texturé, titre
serif et rail de navigation conservés. La composition du nouvel écran reprend
ces éléments sans inventer une liste classée. Les illustrations de personnes,
graphiques et statistiques des planches ne sont pas copiés faute de données
approuvées. Le bandeau utilise le motif CSS du socle #84, pas une reproduction
pixel à pixel du relief photographique de la planche.

Limites : pas de recette Elementor, vrai SSO, thème cible, WordPress distant ou
appareil physique. Aucun calcul économique testé dans ce lot : service Hub
absent, classement fermé selon le [contrat](../../../modules/FANS-FAN-RANKING.md).

Commande navigateur : `node tests/Fans/Ui/recipe/browser.cjs`, avec
`FANS_UI_OUTPUT` vers ce dossier et serveur PHP de fixtures local sur le port
8765. Les cookies de rôle de ce routeur n’existent que sous `tests/`.

# Lot 5 — Refus et matrice des routes

29 septembre 2026. Base `e5cf47465f91e3459fa2b0518ab5ca967813c951`, 0.6.3.
PHP 8.5.4, export Git LF + diff et vendor physique conforme au lock dans WSL.
Lint des trois PHP, PHPStan complet vert, **284 tests / 4 253 assertions**, aucun
échec, deux dépréciations. Syntaxe JS, scan ciblé de secrets et diff vérifiés.

## Preuve navigateur

`node tests/Fans/Ui/recipe/matrix.cjs` : **168 combinaisons** consignées dans
[routes.json](routes.json), puis recette `browser.cjs` de régression verte.
Serveur PHP de fixtures, Chromium 1440 × 900 et 390 × 844 : **pas WordPress réel**.

- 18 routes, quatre rôles (invité, Fan lié, Créateur lié, administrateur non lié),
  deux viewports ; contrôles 200/403/404 sur le document HTTP lui-même.
- Douze variantes de profil public absent/suspendu/retiré par viewport : même
  404, même message neutre, aucun motif privé, formulaire ou appel REST de profil.
- No-store, pas de débordement, huit accès Créateur / six Fan / deux visiteur,
  labels Créateur inactifs masqués ; aucun score/rang/PC/euro inventé.
- Services HoF/session/classements/progression fermés, toujours sans API de score.
- La barre WordPress réelle n’est pas simulée dans ces captures. Son filtre de
  permission reste couvert par les tests PHP, pas par une nouvelle recette WP.

## Captures inspectées

| Surface | Ordinateur | Mobile |
| --- | --- | --- |
| Profil indisponible, HTTP 404 | [capture](profil-indisponible-1440.png) | [capture](profil-indisponible-390.png) |
| HoF invité | [capture](guest-hof-1440.png) | [capture](guest-hof-390.png) |
| Progression Créateur | [capture](creator-progression-1440.png) | [capture](creator-progression-390.png) |
| Ma boutique, service absent | [capture](creator-boutique-1440.png) | [capture](creator-boutique-390.png) |
| Messages, service absent | [capture](creator-messages-1440.png) | [capture](creator-messages-390.png) |

Le défaut observé était la sortie du shell vers une page d’erreur nue. Le bandeau,
la navigation et un retour Explorer sont maintenant conservés. Aucun changement
des permissions ni de révélation d’un statut de modération à un visiteur.
Comparaison V2 : mêmes palette/typographies/navigation que le socle ; les contenus,
personnes et graphiques des maquettes restent absents lorsqu’aucun service ne les
fournit. Ces captures ne prouvent pas le rendu du thème ou d’Elementor cible.

La [matrice intermédiaire](../../../modules/FANS-48H-EVALUATION.md) distingue
fonctionnement testé sur adaptateurs, services absents et décisions/accès requis.
Aucun site consulté, flag activé, déploiement, release ou paiement.

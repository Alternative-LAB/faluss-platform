# Bouton SSO Fans — 30 septembre 2026

## Scénarios et périmètre

- Positifs : vrai shortcode rendu après `wp_head`, police et image locales,
  texte accessible sans JavaScript, POST natif par Entrée avec action, nonce et
  destination. Même formulaire dans la page de connexion Fans.
- Négatifs : destination externe exclue, nonce invalide refusé, compte privilégié
  refusé, échec d'échange et rejeu sans nouvelle session (suite SSO existante).
  Aucun style appliqué au bouton témoin hors shortcode, aucun débordement horizontal.

## Environnement et reproduction

WordPress **7.1.2** jetable, PHP **8.5.4**, MariaDB privée par socket, thème
Twenty Twenty-Five. Aucun plugin activé, aucun flag changé : un MU-plugin de
recette enregistre uniquement le vrai adaptateur de rendu et son hook CSS.
Le client de test est volontairement inutilisable, sans identité ni secret réel.
Les requêtes externes sont bloquées côté WordPress et navigateur.

Le décor de la capture est une fixture locale reprenant le contexte visuel fourni,
sans compteur ni donnée produit inventée. Le formulaire provient directement de
`FansSsoService::button()` via le moteur de shortcodes WordPress, avec un vrai nonce
WordPress. La soumission est interceptée localement avant traitement pour vérifier
son corps POST ; **aucun aller-retour vers Identity n'est effectué**.
Le retour et les protections SSO sont couverts séparément par les tests de contrat.

```sh
python3 tests/Fans/Sso/recipe/button-wordpress.py \
  --source /chemin/snapshot-physique-avec-vendor \
  --core /chemin/wordpress --cli /chemin/wp-cli.phar
# Dans un second terminal, avec Playwright et Chrome disponibles :
BASE=http://127.0.0.1:PORT OUT=docs/evidence/fans-sso-button \
  node tests/Fans/Sso/recipe/browser-button.cjs
# Toucher le fichier STOP du dossier jetable annoncé pour arrêter PHP/MariaDB.
```

Chrome, JavaScript désactivé : **1286×744**, **390×844**, **320×844**.
Mesures et assertions : [results.json](results.json).

Suite PHP complète sur snapshot Linux avec dépendances physiques : **302 tests,
4 789 assertions**, deux dépréciations préexistantes, aucun échec. PHPStan niveau 7 :
aucune erreur. Lint PHP des fichiers modifiés et de la fixture, syntaxe Node/Python,
scan ciblé de secrets et `git diff --check` : conformes.

## Captures

| Contexte | Ordinateur | Mobile |
| --- | --- | --- |
| Shortcode dans le corps de page | [1286 px](button-1286.png) | [390 px](button-390.png), [320 px](button-320.png) |
| Focus clavier | [1286 px](focus-1286.png) | [390 px](focus-390.png), [320 px](focus-320.png) |
| Coexistence avec le shell Fans | [1286 px](fans-shell-1286.png) | [390 px](fans-shell-390.png), [320 px](fans-shell-320.png) |

La forme, le vert, Outfit et le pictogramme viennent de la demande et des assets
existants. Le blanc sur ce vert respecte la référence mais ne revendique pas un
contraste texte WCAG AA. Le focus possède un contour double blanc/sombre.

## Limites et livraison

Cette preuve est locale, sans Elementor actif ni CSS spécifique du site cible.
Elle ne prouve ni le rendu sur `fans.faluss.me`, ni le parcours SSO réel, que le
propriétaire testera après mise à jour. Aucun accès aux sites, serveur de mises à
jour, secret ou configuration de production. Aucune migration ni activation.
La mise à jour suit exclusivement Prepare release et Publish private release.

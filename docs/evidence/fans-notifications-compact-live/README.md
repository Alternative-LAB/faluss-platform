# Notifications Fans compactes et actualisées — recette isolée

Base : `f9147e4dece76196b215f22ffe2c4032c57758ef` (0.12.4). Aucun accès à un site cible,
aucun flag ou compte de production modifié. Les événements montrés proviennent de
vraies écritures métier dans deux WordPress/MariaDB jetables, sur des comptes de recette.

## Environnement et portée de preuve

- WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6, Twenty Twenty-Five ; application Fans autonome.
- Chromium 155.0.8059.26 (Chrome), largeurs 320, 390, 430, 834 et 1440 px.
- Deux membres distincts liés à des identités SSO synthétiques (Fan / Créateur) :
  cookies WordPress réels, REST et SQL réels. Pas de connexion centrale à Me, pas de
  compte cible. Réseau navigateur limité au loopback ; HTTP sortant et mail interdits.
- Captures inspectées : Fan/Créateur chargés avec états lus/non lus, 1440×1000,
  834×1000 et 390×844. Lignes simples mesurées à 56 px, sans débordement à toutes
  les largeurs testées, cible clavier/tactile unique. Fond gris pour non lu,
  transparent pour lu, aucune bordure verte. Seuls des libellés génériques apparaissent.
- Simulation navigateur des événements visibilité/réseau et injection de pannes de
  transport ; données positives jamais simulées. Pas de preuve de Safari matériel
  ni de rendu du site cible/Elementor, auxquels aucun accès n’a eu lieu.

## Résultats

- `browser.json` : 69 contrôles, dont demande reçue et acceptation entre membres,
  messages réellement envoyés, cloche/liste sans rechargement, lecture non mutante,
  lu/non lu persisté via le POST historique, filtre, pagination >20, conservation
  du focus et de la position, masquage/reconnexion, clavier et formulaire sans JS,
  nonce invalide, destinataire étranger, destination injectée, objet expiré,
  invalidation serveur de la session. Aucun corps privé dans les notifications.
- `transport.json` : 5 contrôles, délais mesurés 24 puis 48 secondes après erreurs,
  sérialisation malgré événements simultanés, récupération automatique, lecture
  du seul compteur sur Explorer. Plafond de délai 120 secondes dans le code.
- `expiration.json` : expiration effective des jetons WordPress de recette,
  suppression de la liste privée et du compteur au prochain contrôle.
- `admission-checks.json` : 83 contrôles réels d’admission/permissions/concurrence.
- `notifications-checks.json` : 65 contrôles SQL/HTTP, y compris rollback métier,
  rejeu dédupliqué, droits du destinataire, nouveau GET privé et ouverture POST/303.
- `notifications-report-checks.json` : 12 contrôles de décisions et recours,
  sans diffusion des notes internes ni attestation automatique.
- `notifications-retention-checks.json` : 17 contrôles de rétention/rollback,
  purge notifications avec leur objet, conservation séparée des signalements.
- Suite PHP : 317 tests, 5 342 assertions ; 2 dépréciations PHP 8.5 préexistantes.
  PHPStan : aucune erreur. PHP modifiés lintés ; syntaxe JS et diff vérifiés.

## Reproduction

Depuis une copie physique du dépôt avec les dépendances verrouillées : lancer
`tests/Fans/Profiles/recipe/admission-wordpress.py --source <source> --core <core>
--cli <wp-cli> --backoffice --keep`, puis
`tests/Fans/Messaging/recipe/layout-prepare.py --root <root> --base <loopback> --cli <wp-cli>`.

Avec Node/Playwright sous Windows, définir `ROOT` (racine WSL jetable), `BASE`
(loopback), `OUT` (répertoire de preuves), puis exécuter dans cet ordre :

1. `tests/Fans/Ui/recipe/notifications-wordpress.cjs`
2. `tests/Fans/Ui/recipe/notifications-transport-wordpress.cjs`
3. `tests/Fans/Ui/recipe/notifications-expiration-wordpress.cjs`

Le premier invalide le jeton Créateur à la fin ; le dernier expire les jetons Fan.
Repartir d’une fixture fraîche pour recommencer. Les nonces/cookies restent dans
le répertoire jetable, jamais dans les preuves ni les captures. Les redirections
du domaine fictif sont réécrites vers le loopback uniquement par un MU-plugin de recette.

Les tests SQL/HTTP utilisent une seconde fixture `--test --keep`, puis `app-seed.php`
(Fan de recette), et les trois scripts `notifications*-http.py` dans l’ordre
notifications, reports, retention. Arrêter et supprimer les deux fixtures après vérification.

## Comportement et limites explicites

Le clic est une navigation native après POST protégé et redirection 303. Aucun
marquage sur GET/défilement et aucune mutation d’ouverture par lecture dynamique.
Le serveur revalide l’objet au clic ; s’il est inaccessible, 409 sans changement
lu/non lu. Les destinations recontrôlent elles-mêmes les droits.

Les boutons visibles lu/non lu ont été retirés conformément à la correction de
la demande. Leur ancien traitement POST reste compatible et sert à éprouver les
changements provenant d’une autre session. Aucun nouvel état « ouverte ».

Les nouvelles notifications s’insèrent sur la première page. En page ancienne,
la page et son curseur restent fixes ; le compteur et « Revenir au début » permettent
d’accéder aux nouvelles arrivées. Le poll ne remet jamais au début implicitement.
Le focus d’une ligne disparue du filtre passe à la ligne suivante ou au filtre.

Il s’agit exclusivement d’actualisation dans l’application (centre 12 s, cloche
seule 20 s). Aucun push/e-mail ajouté ; politique externe et recours non attestés.
Le garde de session existant attend désormais la reprise réseau sur les vues avec
cloche, sans renouveler sa durée et sans dévoiler une page masquée avant validation.

## Captures

| Vue | Ordinateur | Tablette | Mobile |
|---|---|---|---|
| Fan | [1440](fan-loaded-1440.png) | [834](fan-loaded-834.png) | [390](fan-loaded-390.png) |
| Créateur | [1440](creator-loaded-1440.png) | [834](creator-loaded-834.png) | [390](creator-loaded-390.png) |

[Objet devenu inaccessible](creator-inaccessible-1440.png).

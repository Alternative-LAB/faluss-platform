# B6 — preuve native des brouillons et examens de session

## Périmètre et source

Sous-lot fermé : liste privée, brouillon, édition, calendrier, soumission des
règles, décisions motivées, recours et révocation. Routes de l'application et
panel existants, authentification WordPress réelle par cookies de comptes liés
localement à des identités **fictives distinctes**. Le protocole SSO central Me
n'est pas exécuté. Aucun site, flag de production, migration ou achat réel.

Source locale immuable : arbre `ac888673c87d09d2edb5ffe696c5d7cd752a69f9`.
Les 21 fichiers PHP/CSS modifiés de la branche sont comparés aux octets LF de
l'export testé ; empreintes dans [source-proof.json](source-proof.json).
Le head final ajoute documentation/preuves et un contrôle supplémentaire de
présentation du fuseau WordPress. Sa CI exacte doit confirmer ce dernier cas.

## Résultats acquis

| Vérification | Résultat |
|---|---|
| WordPress/MariaDB conjoint Hub/Fans | 822 réussis, zéro échec, dont 95 B6 et 31 nouveaux cas natifs ; fixture_removed=true |
| Qualité locale | 802 tests / 8004 assertions, HoF 84/291, PHPStan 0 ; deux dépréciations historiques |
| Navigateur | 32 contrôles, deux parcours natifs ordinateur/tactile, zéro échec |
| Largeurs | 320, 375, 390, 430, 768 et 1440 px |
| Images | 16 captures des vraies routes, toutes examinées personnellement |

Versions réellement exécutées : WordPress 7.1.2, PHP 8.5.4,
MariaDB 11.8.6. Cible d'analyse statique PHP 8.3.35. Les rapports complets sont
[physical-checks.json](physical-checks.json) et
[browser-checks.json](browser-checks.json) ;
[sha256.json](sha256.json) contient les empreintes des fichiers publiés ici.
Ces résultats portent sur la branche, pas encore sur un futur main consolidé.

### Scénarios natifs

- Invité, Fan, autre Créateur et administrateur non lié : permissions
  distinctes, absence de propriété implicite, cible et nonce falsifiés refusés.
- Création avec 303 vers le détail privé courant, champs de propriétaire fournis
  par le navigateur ignorés, absence d'UUID dans le texte visible.
- GET sans modification, brouillon absent du catalogue public, approbation des
  règles laissant le brouillon fermé.
- Erreur de saisie conservée, heure inexistante refusée, heure ambiguë exigeant
  le choix d'une occurrence ; instant UTC vérifié en base.
- Deux requêtes HTTP clientes simultanées sur la même révision : une acceptée,
  une refusée 409 ; saisie perdante conservée sans nouveau formulaire susceptible
  de remplacer silencieusement la révision. Même contrôle pour les décisions.
- Nonce de modération lié à la cible, motif et curseur d'historique invalides
  refusés avant écriture ; rejet, recours, ancien formulaire et révocation
  confirmée, journaux et absence de diffusion.
- Anciennes routes : 302 en GET, 307 en POST, sélection conservée. Lecture privée
  après suspension sans formulaire de modification.
- Navigateur : création, édition au clavier, soumission, décision dans le
  contexte administrateur séparé, relecture de l'état approuvé et retour à la
  liste ; gestes tactiles et navigation clavier.

La concurrence des requêtes clientes ne prétend pas transformer le serveur PHP
de recette en serveur multithread. La sérialisation SQL et les quotas relèvent
des recettes B2 existantes. La preuve antérieure de liste privée est conservée
séparément : [49 contrôles](private-list-checks.json),
[nettoyage](private-list-cleanup.json), arbre `fae56fba1ad139f90c32f92ff4a0147adb0d5689` du 15 octobre. Elle ne
remplace pas les nouvelles vérifications des routes.

### Échecs et préflight conservés dans le suivi local

La première exécution s'est arrêtée après douze nouveaux cas sur une erreur du
test : le DTO public expose `id`, pas le champ de stockage `session_id`. Le
test a été corrigé sans élargissement d'API ; aucune réussite globale de cette
tentative n'est revendiquée. Un préflight de 18 agencements avec stubs a précédé
la recette ; ses images ne sont pas utilisées ici comme preuve d'authentification.

## Captures examinées

Le bandeau, les panneaux, la typographie, la palette et les conteneurs Fans
existants sont repris ; sidebar Créateur conservée à huit accès. Les dates
natives restent dépendantes du navigateur et du système ; le fuseau de la
session est indiqué. Le panel affiche le fuseau WordPress explicitement.
Les vues mobiles sont des captures de viewport ; leur bas se parcourt par
défilement naturel. Les onglets intérieurs défilent horizontalement.

| État | Ordinateur | Mobile 390 px |
|---|---|---|
| Liste privée | [Voir](creator-session-list-1440.png) | [Voir](creator-session-list-390.png) |
| Nouveau brouillon | [Voir](creator-session-new-1440.png) | [Voir](creator-session-new-390.png) |
| Brouillon et recours en attente | [Voir](creator-session-review-1440.png) | [Voir](creator-session-review-390.png) |
| Examen administratif et historique | [Voir](admin-session-review-1440.png) | [Voir](admin-session-review-390.png) |
| Modification native | [Voir](creator-session-edited-1440.png) | [Voir](creator-session-edited-390.png) |
| Proposition soumise | [Voir](creator-session-pending-1440.png) | [Voir](creator-session-pending-390.png) |
| Décision administrative | [Voir](admin-session-pending-1440.png) | [Voir](admin-session-pending-390.png) |
| Règles approuvées, brouillon conservé | [Voir](creator-session-approved-1440.png) | [Voir](creator-session-approved-390.png) |

## Limites et suites

Cette preuve n'atteste ni SSO Me réel, ni thème/Elementor du site cible, ni
clavier virtuel ou appareil physique. Aucun contenu réel, cookie, nonce,
secret de client, HTML privé ou fichier de capacité du renderer n'est exporté.

Le cycle d'ouverture/fermeture Hub, coorganisateurs/participations, territoires
et intentions ont leurs raccordements suivants. Aucun score public ou ouverture
sur site n'est annoncé par l'examen d'un brouillon. #150 et #161 restent distinctes.
La version 0.13.0 publiée n'inclut pas ce lot ; aucune release n'est déclenchée
pour cette preuve seule.

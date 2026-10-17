# B6 — preuve du cycle natif de session

## Source et limites

Source physique et navigateur : commit `c254d50398b38026a29b321f1ed614ea23b79c98`,
arbre `7781312ddfbcbbd87ff001bfb62e0b556ebf0f9a`. Les sources applicatives
PHP/Python modifiées sont comparées aux octets LF de l'export réellement testé :
[source-proof.json](source-proof.json). Les ajouts suivants de documentation ou
de preuves ne changent pas ces octets ; la CI du nouveau head reste requise.

Instances Hub/Fans WordPress jetables, bases distinctes, clés/pairs/identités
fictifs ; cookies WordPress réels de comptes liés localement. Ce n'est ni le
véritable SSO Me, ni une recette du thème/Elementor du site cible, ni un test
sur appareil physique. Aucun site, achat, flag de production ou contrat Hub modifié.

## Vérifications acquises

| Vérification | Résultat |
|---|---|
| Recette conjointe physique | 846 contrôles / 0 échec ; 119 B6, dont 23 nouveaux cas natifs ; fixture_removed=true |
| Qualité de cette source | 802 tests / 8037 assertions, HoF 84/291, PHPStan 0 ; deux dépréciations historiques |
| Navigateur | 80 agencements, deux parcours natifs desktop/tactile, zéro erreur automatique |
| Largeurs | 320, 375, 390, 430, 768 et 1440 px |
| Captures | 24 PNG examinés personnellement le 17 octobre 2026 |

Versions exécutées localement : WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6.
Analyse statique ciblée PHP 8.3.35. Rapports [physique](physical-checks.json),
[navigateur](browser-checks.json) et [empreintes](sha256.json).
Cette recette corpus-joined ne lance pas l'ancien B3beh de completion HTTP :
ses compteurs barrier/completion HTTP sont zéro. Elle ne prouve pas le correctif
nonce distinct ni les invitations de coorganisation. Résultats de branche,
pas encore d'un main intégrant ce cycle.

### Parcours réels de l'application

- Nonce, propriété, Fan, GET sans mutation, confirmation explicite et révision
  périmée contrôlés côté serveur.
- Ouverture native, annulation, réponse perdue après COMMIT et reprise même clé
  par lookup ; état terminal seulement depuis le journal et l'application attestée.
- Révocation administrative commise, fermeture encore incertaine présentée 202,
  reprise protégée, annulation concurrente non transformée en suspension.
- Recours, nouvel examen, réadmission conservant règles/contributions et anciennes
  clés ; une ancienne fermeture ne suspend pas la version réadmise.
- ACK historique distingué de la fraîcheur actuelle ; panne de relecture puis
  récupération explicite sans nouvelle action économique.
- Fin anticipée refusée et fin à échéance figée attestée par Hub 1.1, sans vainqueur.
- Deux parcours navigateur natifs avec création/édition/soumission, administrateur
  distinct, ouverture, suspension, recours, réadmission, annulation et retour liste.

### Résultats visuels et écarts

Bandeau crème, palette sombre/menthe, Outfit, conteneurs, sidebar à huit accès
et navigation inférieure existants conservés. Formulaires lisibles, champs et
boutons dans leur conteneur ; aucun débordement de page aux six largeurs. Sur
mobile les captures montrent le viewport, les contrôles suivants restent dans
le défilement naturel ; les onglets intérieurs défilent horizontalement.

Ces vues privées de gestion ne reproduisent pas les faux scores, photographies,
participants ou graphiques des planches publiques. La session réadmise ou
annulée est distinguée sans contenu économique inventé. Le petit panneau de
cycle peut rester vide avant approbation ; amélioration de densité possible
lors du raccordement des rôles, sans bloquer les opérations testées. Les dates
natives dépendent de la locale du navigateur ; les valeurs serveur restent
UTC et le fuseau est explicitement affiché. Le panel utilise le fuseau WordPress
configuré (+00:00 dans cette fixture), distinct du fuseau de session.

Les preuves antérieures des brouillons sont [conservées séparément](../fans-hof-b6-session-ui/README.md).
Les deux premiers essais du cycle ont échoué sur mon scénario d'horloge de
recette ; l'adaptateur applicatif coordonne désormais les horloges fictives
Fans/corpus et Hub. Aucun workflow, provisioning, cache, délai, assertion
historique ou runner n'a été modifié. Les défauts de ces essais ne sont pas
effacés du suivi local ; leur résultat n'est pas présenté comme une réussite.

## Captures examinées

| État | Ordinateur | Mobile 390 px |
|---|---|---|
| Liste privée | [1440 px](creator-session-list-1440.png) | [390 px](creator-session-list-390.png) |
| Nouveau brouillon | [1440 px](creator-session-new-1440.png) | [390 px](creator-session-new-390.png) |
| Édition | [1440 px](creator-session-edited-1440.png) | [390 px](creator-session-edited-390.png) |
| Examen en cours | [1440 px](creator-session-pending-1440.png) | [390 px](creator-session-pending-390.png) |
| Règles approuvées, session encore fermée | [1440 px](creator-session-approved-1440.png) | [390 px](creator-session-approved-390.png) |
| Ouverture attestée | [1440 px](creator-session-open-1440.png) | [390 px](creator-session-open-390.png) |
| Suspension | [1440 px](creator-session-suspended-1440.png) | [390 px](creator-session-suspended-390.png) |
| Réadmission | [1440 px](creator-session-readmitted-1440.png) | [390 px](creator-session-readmitted-390.png) |
| Annulation | [1440 px](creator-session-cancelled-1440.png) | [390 px](creator-session-cancelled-390.png) |
| Recours | [1440 px](creator-session-review-1440.png) | [390 px](creator-session-review-390.png) |
| Panel : demande | [1440 px](admin-session-pending-1440.png) | [390 px](admin-session-pending-390.png) |
| Panel : historique et recours | [1440 px](admin-session-review-1440.png) | [390 px](admin-session-review-390.png) |

## Ce qui reste distinct

Participation/admission, gouvernance des territoires et choix d'attribution
restent à raccorder aux services fermés existants. Les contrôles serveur de
ces domaines sont préservés ; aucun organisateur n'est inscrit automatiquement.
La recette locale ne rend pas le classement utilisable sur les sites.
Conservation réelle #150, stockage privé RustFS #161, admission et exploitation
de production restent distincts. Aucune publication ou activation par ce lot.

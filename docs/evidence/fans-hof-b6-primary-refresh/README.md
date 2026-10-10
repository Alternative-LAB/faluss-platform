# B6 — relecture primaire : recette incomplète au point de pause

Arbre LF `8a3a8b8bd905475b16ee33d6dedbd8ae9a98fea4`, deux WordPress/MariaDB
jetables fictifs, HTTP loopback et clés privées de recette. Aucun site ou vrai SSO.

La suite PHP et l'analyse statique sont réussies : **648 tests / 7 102 assertions**,
HoF **76 / 259**, PHPStan complet cible 8.3 sans erreur, deux dépréciations
historiques. Lint, compilation et diff étaient vérifiés avant la recette.

La recette réelle a achevé **599 contrôles** : les 592 préalables et sept nouveaux
contrôles B6pr. Elle a ensuite **échoué** sur l'assertion composée
`B6pr the new primary acknowledgement promotes exactly the same complete generation`.
Cette assertion examine état/identité/clé de lecture, manifeste, cardinalités et
nouvel instant primaire ; le prédicat fautif n'est pas encore isolé. Ce n'est
pas un succès de fraîcheur ni une cause démontrée du code ou de l'environnement.
Les scénarios suivants n'ont pas été exécutés. Aucun rapport final réussi existe.

Un échec antérieur du lecteur de preuve du harnais (échappement JSON MariaDB
batch) a été corrigé par HEX avant cette invocation. Il est distinct de cet
échec d'assertion. La demande de pause interdit de lancer une nouvelle recette
ou une correction maintenant ; le diagnostic reprendra explicitement après reprise.

Le `finally` a arrêté les workers et le primaire, puis détruit les fixtures.
Après sortie non nulle, zéro racine privée correspondant à cette source et zéro
worker de cette invocation restaient présents. Leurs constantes de recette
disparaissent avec les configurations jetables ; aucun flag de site n'a changé.

Le [rapport partiel expurgé](partial-checks.json) conserve les seuls intitulés,
totaux, statut d'échec et limites. Aucun contenu privé, clé, identité, wire,
signature, cookie ou preuve détaillée n'y figure. PR #202 en brouillon : pas de
Ready for review, fusion ou publication. #150 et #161 distinctes.

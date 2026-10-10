# B4b — preuve du cache privé reconstruisible

Recette du 10 octobre 2026 sur la copie Git immuable
`5aed12518b21f333395e4c95766ad3bf7864cae2`, avant ajout de cette preuve.
Instances WordPress 7.1.2 / MariaDB 11.8.6 jetables, PHP 8.5.4 ; données,
identités, pairs et clés fictifs. Aucun site ni flag de production.

## Vérifications

- `run.py --b3-corpus-reader` : **576 contrôles**, dont **28 nouveaux B4b** ;
  zéro échec. Le [rapport expurgé](wordpress-checks.json) confirme la destruction
  de la fixture. Les 548 contrôles antérieurs sont inclus, pas simulés.
- HoF : 60 tests / 161 assertions ; suite complète : 632 / 6 986 ; zéro échec,
  deux dépréciations historiques. PHPStan complet cible PHP 8.3 satisfait.
- Erreur d'insertion après DELETE, COMMIT sans acquittement, reconstruction
  concurrente, corpus incomplet, clé Hub révoquée et restauration d'anciens
  octets vérifiés sur InnoDB. Net corrigé partiel/total, litige/résolution et
  événements désordonnés restent liés au mois d'origine, sans second débit.
- Une clé de mois `2026-10` n'est pas admise par le codec signé Hub : la
  sérialisation dérivée utilise une liste locale. Un test protège la séparation
  et le retour au document de calcul sans changer le contrat Hub.
- Lint des neuf PHP, compilation des quatre Python, 13 comparaisons LF avec la
  copie immuable, scan de 17 fichiers, quatre liens relatifs et
  `git diff --check` satisfaits avant commit.

## Limites et investigations

Le cache vérifie une génération complète attestée à un instant primaire. Il ne
prouve pas la fraîcheur continue, la production, le véritable SSO, l'ouverture
de l'origine, la visibilité publique ou B5/B6. Chaque lecture recompose les
faits : coût linéaire, plafonds B3 fermés, aucune validation de dimensionnement.

Sur cette recette, l'inventaire de 102 faits occupait 173 513 octets ; mutex
propriétaire environ 2,63 s, matérialisation 2,97 s et vérification finale 7,68 s.
Ces mesures incluent l'instrumentation de fixture et ne garantissent pas un
débit de production. Des exécutions antérieures ont échoué dans des reprises
HTTP héritées sans classification suffisante : aucune cause d'environnement
n'a été affirmée. Les diagnostics désormais expurgés ne publient que durée,
code HTTP et classe timeout/transport, jamais URL, corps ou identités.

La preuve ci-dessus porte sur la recette complète finale réussie. La recette
focalisée utilisée pour isoler le bug de sérialisation n'est pas sa substitution.
Pas de ledger PF parallèle, API de score, migration automatique ou publication.
Conservation #150 et RustFS #161 demeurent distinctes.

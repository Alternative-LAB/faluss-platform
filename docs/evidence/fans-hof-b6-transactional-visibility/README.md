# B6 — visibilité et transaction appelante

Source corrigée LF : arbre `2def72573a22088ea36a26258418b57b6d3549fb` ;
WordPress 7.1.2 / PHP 8.5.4 / MariaDB 11.8.6, instances privées jetables.
[Rapport expurgé](wordpress-checks.json), **74/74 contrôles**, dont **24 B6**
(sept nouveaux depuis la première primitive), fixture détruite.

Avant correction, arbre `e4edc03a82b598adb5c95cab55b07f5507ca7f80` : le même
test de conservation de transaction Créateur échoue. Le chemin appelé ouvre
`START TRANSACTION` et `COMMIT` via la lecture éditoriale habituelle, au sein de
la transaction du test. Une table temporaire InnoDB et un insert sentinelle
vérifient le maintien de la transaction puis l'annulation effective par rollback.

Après correction : lecture minimale du nom approuvé dans la transaction
existante, sans démarrage/COMMIT interne. Fan et Créateur conservent le rollback ;
profil suspendu et consentement révoqué restent masqués. Un retrait concurrent
attend réellement un verrou InnoDB, puis la lecture suivante est masquée. La
lecture publique habituelle hors transaction conserve son comportement.

PHPUnit complet **648 / 7 102**, HoF **76 / 259**, PHPStan complet cible 8.3
sans erreur ; deux dépréciations historiques. Lint, compilation Python, scan
ciblé, égalité LF et diff vérifiés. Les entrées classées restent synthétiques :
ce test de consentement/édition ne prétend pas livrer un score Hub ou un écran.

Aucune API publique, migration, flag, site, vrai SSO ou nouvelle publication.
Preuve de branche, distincte de main ; #150 et #161 restent séparées.

# B6 — composition transactionnelle : preuve isolée

Recette du 10 octobre 2026, arbre LF
`a5d6354f84597c3cf724c2604b6f7c966f5b528a`, deux WordPress 7.1.2,
PHP 8.5.4 et MariaDB 11.8.6 jetables fictifs ; HTTP loopback, socket SQL privé,
racine POSIX 0700, bail et clés fictives privés. Aucun site ou vrai SSO.

**610/610 contrôles WordPress/MariaDB**, dont **18 nouveaux B6tx**, après
les 592 préalables du lecteur/corpus et des projections B4/B5. La recette
`--b6-corpus-transaction` termine avec code zéro ; `fixture_removed=true`.

Le source exécuté diffère de l'arbre qualité
`ecd7152ab19a95416afefaaf71424041ddb4da0f` uniquement par un PHPDoc de forme
callback ajouté pour PHPStan. **Aucune instruction exécutable ne diffère**.
Suite complète **648 tests / 7 102 assertions**, HoF **76 / 259**, PHPStan
complet cible PHP 8.3 sans erreur, deux dépréciations historiques. Trois PHP
lintés, deux Python compilés, six sources LF comparées, scan ciblé, liens et
diff check satisfaits. Les deux diagnostics PHPStan précédant ce PHPDoc ne
portaient que sur la forme d'un callback extraite de son contexte typé.

## Vérifications nouvelles

- Absence de transaction, de mutex ou mauvaise origine : aucun callback.
- Transaction appelante toujours active, rollback puis COMMIT extérieur unique
  vérifiés par un témoin InnoDB, sans COMMIT implicite intérieur.
- Exception du callback et appels imbriqués/recursifs : témoin rollbacké.
- Réponse perdue après COMMIT extérieur réel : résultat incertain, métadonnées
  présentes au primaire et même génération privée lisible.
- Confiance Hub révoquée, cache altéré et génération non reconstruite refusés.
- Collecteur distinct observé dans `information_schema.PROCESSLIST` en attente
  réelle du mutex origine ; reprise après le COMMIT du lecteur, sans interblocage.
- Rapprochement en cours : ancienne lecture fermée. Nouvelle fence : cache à
  reconstruire avant livraison, rollback du nouveau callback toujours possible.
- Aucun ledger PF Fans supplémentaire.

## Limites

Le test extérieur détient un mutex/transaction de confiance ; **il ne fabrique
pas un acquittement Hub** et ne prouve pas encore la jonction B1/B2/barrières.
Le document est privé, les filiations et la visibilité restent à raccorder
avant B6 complet. Aucun API publique, écran, score actif, gagnant ou publication.
La fence atteste son instant primaire, jamais une fraîcheur future continue.

Le `finally` arrête workers et primaire puis détruit les deux WordPress et leurs
configurations/flags de recette. Aucun achat, site, pair réel, migration,
flag de production, politique de conservation #150 ou adaptation RustFS #161.
Retour arrière : retirer la composition privée sans supprimer de preuve ou
migrer un site. La pause utilisateur laisse cette PR en brouillon.

Voir le [contrat local](../../modules/FANS-HOF-B6-CORPUS-TRANSACTION.md) et le
[rapport expurgé](wordpress-checks.json), limité aux intitulés, versions et
totaux ; aucun fait détaillé, nom de membre, clé, cookie, signature ou wire.

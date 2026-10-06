# B2a — sessions privées réellement persistées

Recette isolée du 6 octobre 2026 : WordPress **7.1.2**, PHP **8.5.4**, MariaDB
**11.8.6**, base/socket neufs. Dépendances copiées avec lock Composer identique,
sans utiliser la jonction vendor du checkout Windows.

- [Rapport WordPress/MariaDB](wordpress-checks.json) : **42 vérifications**,
  dont concurrence, quatrième ouverture personnelle/coorganisée refusée,
  acceptation explicite, règles figées, retrait après suspension, journal atomique
  et refus d'un schéma non transactionnel.
- Calculs B1 et règles B2 : **41 tests / 94 assertions**, dont les titres et règles
  accentués sans changement du codec historique Hub. PHP lint et PHPStan
  ciblé/complet ; résultat final de CI du head exact requis avant fusion.
- Suite complète isolée du head `69bda75` : **480 tests / 6 241 assertions**, aucun échec ou
  erreur ; deux dépréciations préexistantes.
- Reproduction : `--test --hof-b2` dans la recette d'admission WordPress ; rapport
  `fans-hof-b2-checks.json` joint à l'artefact CI `hub-pf-runtime-checks`.

Les comptes et filiations SSO sont injectés pour la recette ; aucun véritable
SSO central n'est attesté. Pas de score, consommation PF, contexte Hub acquitté,
ouverture économique, route enregistrée ou installation sur site. Les portées
locale/nationale restent fermées jusqu'au service d'examen territorial B2b.
Sans modification visuelle dans ce lot backend, aucune capture UI présentée.

Les textes historiques sont normalisés en LF uniquement dans la copie de test.
Aucune règle/hash historique modifié, aucun secret, achat réel, flag de production,
accès serveur, cron de purge ou politique de conservation ajouté.

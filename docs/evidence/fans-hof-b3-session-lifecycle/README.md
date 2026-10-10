# B2/B3 — preuve du cycle de vie fermé

Source LF : arbre `b8c7dac607b63e9a43da3220609492891a6ce8df`, export Git immuable,
Composer exact. WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6** ; deux
instances jetables, HTTP loopback, clés et identités fictives. Aucun véritable
SSO, site ou pair réel. Rapport expurgé : [wordpress-checks.json](wordpress-checks.json).

- **436/436 contrôles**, dont **37 B2/B3 nouveaux** ; fixture détruite.
- PHPUnit complet **666 tests / 7 128 assertions** ; HoF **55 / 126**.
- PHPStan complet cible PHP 8.3 : zéro erreur ; deux dépréciations historiques.
- Préparation concurrente, révision périmée, règles figées, profils suspendus,
  panne du journal, COMMIT incertain et arrêt réel de processus avant/après COMMIT.
- Réponse Hub perdue et lookup primaire ; même action et clé ; aucune seconde
  décision. Fin anticipée refusée, fin à échéance attestée, suspension réservée
  à l'administrateur, réadmission versionnée et ancien ACK refusé.
- Ledger historique inchangé, aucun ledger Fans et installation physiquement
  impossible hors de l'enclave avant toute requête SQL.

Ces preuves portent sur cette branche. Elles ne constituent pas une vérification
sur main, un raccordement B6, une recette du véritable SSO ou une validation de
production. La fraîcheur du corpus, les participants et choix d'attribution
restent des raccordements distincts. Version publiée inchangée.

# B6 — mutex de sessions avant la transaction composée

14 octobre 2026, tree exécuté `dac4906e13faf0dad855bf7fd30252b5278a100d`,
depuis la branche #209. WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**,
comptes/identités fictifs. Commande `admission-wordpress.py --test
--hof-b6-visibility` sur un export Git LF et dépendances physiques du lock.

[Rapport](wordpress-checks.json) : **126 contrôles**, code zéro,
`fixture_removed=true` ; **6 B6mx**, **46 B6id**, **24 B6v**, **50 B1**.
La concurrence oppose réellement la nouvelle lecture et la transaction
existante `SessionStore::transaction` sur le même mutex. Elle attend la
libération, puis valide son propre probe SQL. Callback rejeté, BEGIN imbriqué,
transaction abandonnée et rollback de l'appelant sont vérifiés.

Qualité sur ce tree : **722 tests / 7 532 assertions**, HoF **84/291**,
PHPStan cible8.3 zéro erreur, deux dépréciations historiques. Quatre PHP
modifiés lintés, deux Python compilés ; scan ciblé, liens et diff à vérifier
avant commit. Aucune fonction transactionnelle existante réécrite.

Ce résultat prouve l'ordre de mutex local, pas encore sa composition avec la
barrière/corpus dans la livraison B6. Lignes classées synthétiques dans les
tests de visibilité ; pas de SSO Me réel, site ou donnée économique réelle.
Pas de route, permission, schéma, cron, flag, release ou migration. RustFS
#161 et conservation #150 restent distincts.

# B6 — barrières dans la transaction d'origine existante

14 octobre 2026, tree exécuté `a684662b51d650dc51136ab8ab4c4df1eba347c3`,
depuis la branche #210. Deux WordPress **7.1.2**, PHP **8.5.4**, MariaDB
**11.8.6**, identités/pairs/clés et données fictifs, HTTP loopback seulement.

Commande : `tests/TokenEngine/recipe/run.py --b6-barrier-refresh --source
<export> --core <wordpress> --cli <wp-cli.phar> --output <rapport>`.
[Rapport](wordpress-checks.json) : **432/432**, `failed=0`,
`fixture_removed=true`, **27 B6br** et **6 B6bt**. Absence de transaction,
mutex non détenu, autre origine, lecture équivalente, callback rollbacké et
commit par le seul propriétaire extérieur. Contrats/reprises historiques
rejoués ; aucune mutation du ledger officiel ou PF parallèle.

Qualité sur ce même tree : **722 tests / 7 534 assertions**, HoF **84/291**,
PHPStan cible8.3 sans erreur, deux dépréciations historiques. Quatre PHP
modifiés lintés, deux Python compilés, runtime comparé, liens/scan/diff.

Cette preuve de branche ne démontre pas encore la livraison finale B6,
les autorisations complètes de sessions/pays ni les vues. Pas de véritable
SSO, site, achat, pair, migration ou flag réel ; aucune fraîcheur future
continue. #150 et RustFS #161 distincts. Aucune release par ce sous-lot.

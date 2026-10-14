# Preuve de consolidation sur main

Exécution nouvelle du 14 octobre 2026 sur **main
`28a81499fd79c10af256ac35fd544597407273b0`**, arbre
`9bcb2e4241d58ec90a42f6d75c3e420f4d671a95`. Les résultats antérieurs de
branches ne sont pas substitués à cette exécution. Source exportée LF depuis
Git, archive SHA-256
`c839596f460e6262dcb2046ac63a0e0bf3e7beab20aabe455b39553ac72973e6`.

PHP **8.5.4**, analyse cible PHP **8.3**, dépendances physiques après égalité
du lock : **722 tests / 7 483 assertions**, HoF **84 / 291**, PHPStan sans
erreur. Deux dépréciations historiques. Aucune jonction vendor Windows.

Deux nouvelles recettes indépendantes, chacune avec Hub/Fans jetables
WordPress **7.1.2** / MariaDB **11.8.6**, InnoDB et HTTP loopback :

- [Barrières](barriers-wordpress-checks.json) : **455/455**, zéro échec,
  `fixture_removed=true` ; B1/B2, transport/reprise des barrières 1.0/1.1.
- [Jonction](joined-wordpress-checks.json) : **727/727**, zéro échec,
  `fixture_removed=true` ; corpus/cache/barrière sur la même origine et
  transaction. Achat fictif seul sans score ; consommation officielle fictive
  alimentant Fan/Créateur ; correction partielle attestée sans second débit.
- [Synthèse](summary.json) avec les empreintes des rapports originaux ;
  exports limités aux intitulés, résultats, versions, mesures et limites.

CI propre de ce main : [PHP](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38510004562),
[JavaScript](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38510004504)
et [archive](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38510004487)
réussies. Les fixtures, workers et configurations jetables sont nettoyés.

Ces preuves n'incluent pas encore la filiation/visibilité et toutes les
barrières de session dans une livraison B6 complète, ni son UI. Pas de vrai
SSO Me, site, achat, pair réel, restauration/réplica de production ou
dimensionnement de production. L'attestation primaire vaut à son instant,
sans promesse de fraîcheur future. RustFS #161 et conservation #150 restent
distincts. Version effectivement publiée : **0.12.6** ; ces fusions et recettes
ne déclenchent aucune publication ou activation.

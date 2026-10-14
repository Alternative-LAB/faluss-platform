# B6 — preuve commune sur la branche consolidée

Exécution du 13 octobre 2026 sur le commit source
`1391adab21e3e0e0ec8d68be6e026924580c2806`, arbre Git
`1e1aa0e9c2e540743300afe7854356f1c318c54a`, export LF isolé.
Deux WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6**, InnoDB privé et
HTTP loopback ; clés, identités et preuves fictives sous bail POSIX.

Commande : `python3 tests/TokenEngine/recipe/run.py --b6-joined-integration
--source <export> --core <wordpress> --cli <wp-cli.phar> --output <rapport>`.
Résultat : **727 / 727 contrôles**, code zéro, `failed=0`,
`fixture_removed=true`. Tous les processus et configurations des instances
jetables sont arrêtés et supprimés par le `finally` de la recette.

## Preuve obtenue ensemble

- **28 B6pr** : demande primaire durable sur la même génération/clé,
  acquittement antérieur refusé, reprise réseau, corrections et confiance.
- **18 B6tx** : transaction appelante, rollback, absence de COMMIT implicite,
  cache exact et collecteur concurrent réellement en attente du mutex.
- **18 B6join** : action et descripteur durables avant HTTP, ACK Hub signé,
  même origine et primaire Fans pour la barrière et le corpus/cache.
- Corpus complet vide puis achat de pack fictif seul : zéro point Fan/Créateur.
- Une consommation officielle fictive : trois points dans chaque projection,
  sans seconde consommation. Correction partielle attestée : deux points dans
  chacune ; l'ancien cache devient indisponible pendant le rapprochement.
- Rollback commun, COMMIT réel, confiance révoquée, fermeture locale avant
  acquittement et refus de livraison sur une origine fermée.
- Ledger officiel et claims historiques préservés ; aucun ledger PF Fans.

Le [rapport](wordpress-checks.json) ne contient que les intitulés, versions,
totaux, mesures et limites. Aucune clé, signature, wire, identité, donnée
personnelle ou contenu privé n'est conservé dans cette preuve versionnée.

## Qualité et portée exacte

Les sources `src/` et tests PHP hors fixtures sont identiques à l'arbre qualité
`4fdb52453b6da61aac1d3a91ab497c661819b7bf`, vérifié auparavant : **722 tests /
7 483 assertions**, HoF **84 / 291**, PHPStan cible 8.3 sans erreur, deux
dépréciations historiques. Cette égalité ne prétend pas qu'une deuxième
exécution de cette suite a été faite sur le commit source. Les changements
de recette sont lintés/compilés ; l'horloge atomique dispose de **six tests**.
Les vérifications finales sur `main` restent nécessaires après intégration.

Mesures fictives : corpus de 101–102 attributions, environ 172–174 ko ; mutex
global observé entre **2,88 et 3,70 secondes** pour materialisation/fence.
Ce volume et cette machine ne valident pas le dimensionnement de production.

Restent distincts : B1/B2 complet dans cette livraison, barrières de
participation/Créateur/pays, choix d'intention, filiation Identity, visibilité
et UI B6. Aucun véritable SSO, TLS de production, achat réel, admission de pair,
score public, restauration de réplica ou conservation réelle n'est validé.
La fence prouve son instant primaire ; aucune fraîcheur future continue.

Cette preuve est celle de la **branche #203**. Elle ne démontre pas que ces
descendants sont intégrés à `main`, publiés ou testables sur les sites.
Le nettoyage n'autorise aucune release ; #150 et #161 restent indépendantes.

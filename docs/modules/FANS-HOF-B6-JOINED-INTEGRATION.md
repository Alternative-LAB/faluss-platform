# Jonction fermée des barrières et projections — preuve de branche

Cette recette de consolidation utilise le gateway de barrières signé et la
lecture réelle du corpus/cache sur les mêmes WordPress/MariaDB jetables.
Le primaire Hub, les permissions dédiées, l'ACK et la transaction locale sont
réels ; les identités, preuves et clés sont fictives. Aucun nouveau contrat Hub.

Le routeur est exclusivement un MU-plugin de recette sous bail POSIX isolé,
avec marqueur local, clés de test et écoute loopback. Aucun endpoint n'est
ajouté au plugin distribué ou à un site ordinaire.

## Résultat du 13 octobre 2026

Arbre exécuté `1e1aa0e9c2e540743300afe7854356f1c318c54a`, commit source
`1391adab21e3e0e0ec8d68be6e026924580c2806` : **727 contrôles réussis**,
dont **28 B6pr**, **18 B6tx** et **18 B6join**. Le
[rapport expurgé](../evidence/fans-hof-b6-joined-integration/wordpress-checks.json)
confirme le nettoyage final. Cette preuve concerne la branche de #203,
pas le `main` consolidé ni les sites.

La recette ouvre une nouvelle origine fictive par le vrai client et gateway
signé, sans adopter une origine enregistrée auparavant avec une autre clé.
Elle vérifie l'action persistée avant HTTP, le refus sans ACK, un corpus signé
complet vide, un pack sans point, puis une consommation officielle fictive
alimentant les projections Fan/Créateur et sa correction partielle attestée.
Le nombre de consommations reste inchangé pendant la reconstruction.

Le cache et la barrière sont lus dans la même transaction propriétaire :
rollback commun, COMMIT réel, révocation de confiance, fermeture locale avant
ACK et refus d'un ancien acquittement sont vérifiés. La livraison doit exiger
`active` ; la façade de gouvernance expose aussi `closing` et `closed`.

## Corrections de recette et historique

- L'horloge Python publie atomiquement un fichier privé et partage son minimum
  entre toutes les closures. **Six tests déterministes** couvrent les écritures
  concurrentes et les copies de fixture. Aucun délai du plugin n'est augmenté.
- `lookup_operation` obligatoire est persisté avec tous les champs avant HTTP.
- Une action réseau propre à la nouvelle origine remplace la tentative
  d'adoption incompatible avec la règle de reprise. Les contrats et la
  persistance de production restent inchangés.
- Les échecs précédents sont conservés séparément :
  [ancienne recette partielle](../evidence/fans-hof-consolidation-partial/README.md).
  Ils ne constituent aucune preuve de succès de la jonction.

Les sources métier et les tests PHP hors fixtures restent identiques à l'arbre
qualité `4fdb52453b6da61aac1d3a91ab497c661819b7bf` : **722 tests / 7 483
assertions**, HoF **84 / 291**, PHPStan cible 8.3 sans erreur et deux
dépréciations historiques. Les nouveaux PHP de recette sont lintés séparément.
Voir les [conditions et limites de preuve](../evidence/fans-hof-b6-joined-integration/README.md).

## Ce qui reste à raccorder

Cette jonction ne prouve pas encore B1/B2 complet dans la livraison B6, les
barrières de participation/Créateur/pays, les choix d'intention, les filiations
Identity, la visibilité dans cette transaction ni l'UI publique. La recette
B1/B2 dispose d'une [preuve distincte](../evidence/fans-hof-barrier-consolidation/README.md).
Une fence atteste son instant primaire, jamais une fraîcheur future continue.

Aucun vrai SSO, classement de site, achat réel, remboursement monétaire,
dimensionnement de production ou publication officielle n'est attesté.
Les diagnostics contiennent uniquement états et motifs techniques, sans clés,
wire, identités ou contenu privé. #150 et #161 restent distinctes.

# B5b — persistance privée des projections de session

Recette du 10 octobre 2026 sur copie LF immuable de l'arbre
`ea94572ad500641d44937d5d35668d22358ad0fa`, verrou Composer exact,
WordPress 7.1.2, PHP 8.5.4 et MariaDB 11.8.6. Deux WordPress et bases
distinctes, clés fictives, socket SQL privé, racine 0700/bail 0600, HTTP
loopback. Les comptes et achats sont fictifs ; aucun site n'est consulté.

```sh
python3 tests/TokenEngine/recipe/run.py --b5-sessions \
  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
  --output /var/tmp/fans-hof-b5-checks.json
```

**592/592 contrôles WordPress/MariaDB**, dont 16 B5b, 28 B4b et 30 sur le
lecteur durable. Préfixe H0–H3 propriétaire, barrières, consommation 0.3,
snapshots 2.0 et corpus exhaustif ; les anciennes recettes HTTP H3/H4 et
F1a demeurent des jobs distincts. Fixture, bases et serveurs détruits.
Rapport [expurgé](wordpress-checks.json), sans clé, signature, identité ou fait.

Suite complète **641 tests / 7 073 assertions**, 69 tests HoF / 239 assertions,
PHPStan complet cible PHP 8.3, aucun échec ou erreur, deux dépréciations
historiques. Lint des deux PHP, compilation de quatre Python, égalité LF
de sept fichiers exécutables, scan ciblé et `git diff --check`.

## Résultats obtenus

- Une consommation propriétaire fictive alimente deux sessions et les deux
  familles persistantes, avec une seule ligne de consommation Hub.
- Le document local format 2 conserve les mêmes tables B4. Format ancien,
  hash apparemment valide et génération incohérente refusés ; reconstruction
  explicite depuis le corpus courant, sans migration de site.
- Remplacement atomique session/général/catégories/mois ; INSERT refusé,
  COMMIT incertain et processus tués avant/après COMMIT conservent des octets
  cohérents et une reprise sur lecture primaire.
- Rapprochement incomplet rend toutes les projections indisponibles ;
  correction partielle, litige, résolution et annulation totale corrigent
  chaque session sélectionnée et le score persistant. Les anciens points
  annulés ne reviennent pas.
- Claims historiques inchangés, aucun ledger PF Fans, vainqueur, récompense,
  statut de présence ou montant financier ajouté.

## Reprise et limites mesurées

Des essais préalables ont atteint le délai HTTP existant de huit secondes,
en retournant un résultat inconnu avant les tests nouveaux. Leur cause exacte
n'est pas isolée et n'est pas attribuée au code ou à l'environnement. Les
scénarios positifs reprennent maintenant les états inconnus autorisés avec
le même identifiant/clé persistés ; chaque appel natif conserve son budget.
Les scénarios négatifs et les observations des pannes restent sur l'appel
natif, sans cacher un refus terminal. Aucun délai réseau du plugin n'est changé.
Le diagnostic de recette ne conserve que phase/durée/code, aucun corps privé.

La recette finale complète passe. Mesures pour 101/102 faits : mutex de source
3,75/3,67 s ; matérialisation 4,02 s ; fence finale 4,37 s. Cette proximité
avec la borne HTTP et le coût de reconstruction complète exigent encore une
validation de dimensionnement ; aucune extrapolation de capacité de production.

Ce cache est exact à l'instant primaire attesté, sans garantie de fraîcheur
future. Origine ouverte, gouvernance/barrières B2, choix avant attribution,
lecture et visibilité B6, vrai SSO et producteur d'achat restent distincts.
Aucun score public, classement actif, flag ou publication ; #150 et #161 ne
sont pas résolues par cette recette. Aucun média ou changement visuel.

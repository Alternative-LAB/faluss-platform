# B3c2b1 fermé — source exhaustive propriétaire

Recette WordPress 7.1.2 / MariaDB 11.8.6 / PHP 8.5.4 / WP-CLI 2.12.0 dans une
enclave jetable 0700, bail physique H3 0600 et socket primaire privé. Toutes
identités, clés, preuves et attributions fictives. Dépendances du verrou exact.
Aucun site, vrai SSO ou capacité de production.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-corpus-source \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/hub-pf-b3-corpus-source-checks.json"
```

**38 scénarios nouveaux satisfaits**, après les 320 contrôles historiques et B3
précédents : **358 au total, zéro échec**. Service historique inchangé ; enclave
supprimée. La suite PHPUnit complète vérifie aussi 587 tests / 6 699 assertions ;
analyse statique au niveau PHP 8.3 de la CI, 13 PHP et deux Python modifiés valides.

| Lecture isolée | Faits / allocations | Octets canoniques | Verrou global détenu |
| --- | --- | --- | --- |
| Première génération courante | 101 / 101 | 171 802 | 2 298,666 ms |
| Relecture après corrections | 102 / 102 | 173 513 | 2 527,558 ms |

Ces durées bloquent les écritures propriétaires pendant la lecture. Elles
montrent le coût réel de cette composition locale, sans attester un budget de
latence acceptable en production. Le [rapport expurgé](wordpress-checks.json)
contient les seuls noms de contrôles et mesures, aucune identité ou clé.

Scénarios : origine vide explicitement admise, plus de cent consommations réelles
du protocole fermé, nouveau membre inconnu de Fans, pack seul/ancien fait exclus,
attribution multi-lots, net H4 partiel/total/litige/résolution et rejeu ancien.
Fragment inachevé = origine indisponible ; ordre/date/contexte conservés et faits
à zéro présents. Permissions/origines/audiences erronées, lecture hors transaction,
mutex global sans bail, écritures économiques et transactions imbriquées refusées.
Concurrence avec consommation puis correction, relecture après rapprochement,
époque et digest contradictoires, exactitude après restauration des seules
métadonnées fictives altérées. Aucun correctif économique automatique.

Les acquisitions/libérations effectives du mutex global sont chronométrées dans
l'adaptateur wpdb **de recette**, avec le nombre de faits/allocations et taille
canonique. L'attente des écrivains est attestée au point d'acquisition, avant
libération du lecteur. Ce ne sont ni un débit de production, ni un benchmark
à charge distribuée ; aucune extrapolation à la borne fermée de 1 000 reçus.
Les fenêtres de quota sont franchies en avançant uniquement l'horloge de connexion
SQL fictive, sans diminuer les quotas ni modifier des confirmations stockées.

Cette étape mesure une lecture courante. Matérialisation immuable/page/fence,
reprise par clé et obsolescence pendant transfert restent le sous-lot suivant.
La source exhaustive ne constitue pas, à elle seule, un classement ou une livraison
réseau. Pas de politique #150, flag, admission réelle ou ledger parallèle.
Voir le [contrat approuvé](../../modules/HUB-PF-B3-RANKING-CORPUS.md).

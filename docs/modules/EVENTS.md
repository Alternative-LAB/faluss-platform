# Module Faluss Events

## Portée

Le module `events` reprend Faluss Events `0.3.1` sur les rôles `me` et `hub`.
Il conserve les catalogues EVT, l’outbox, l’inbox, les deliveries consommateurs,
les tombstones de rétention, les leases, les retries et les trois workers
WP-Cron historiques. Les contrats `faluss.event-source-catalog`, `faluss.event`
et `faluss.event-acceptance` restent en version `1.0.0`; le schéma privé reste
en version `2`.

Le module n’est ni un moteur métier ni une autorité d’identité ou économique.
Il ne crée aucun événement au chargement, n’enregistre aucun producteur ou
consommateur libre et ne remplace pas les décisions de Portal, Link, Analytics,
Quêtes, Progression, Identity, Subscriptions ou Token Engine.

Le contrat normatif complet reste documenté dans
[`docs/FALUSS_EVENTS_CONTRACT.md`](../FALUSS_EVENTS_CONTRACT.md). Les trois
schémas JSON historiques sont conservés dans `contracts/`.

## Activation explicite

Le rôle et l’opt-in sont définis hors Git :

```php
define('FALUSS_PLATFORM_ROLE', 'me'); // ou 'hub'
define('FALUSS_PLATFORM_EVENTS', true);
```

Sans `FALUSS_PLATFORM_EVENTS === true`, aucune classe Events, table, route,
planification ou intégration Federation du module n’est chargée. Si une classe
du plugin Faluss Events historique est déjà présente, Platform refuse la
coexistence afin d’éviter deux runtimes et deux registres actifs.

## Stockage et migration

Les noms et le DDL historiques restent inchangés :

- `*_faluss_events_catalogs` ;
- `*_faluss_events_events` ;
- `*_faluss_events_outbox` ;
- `*_faluss_events_inbox` ;
- `*_faluss_events_consumer_deliveries` ;
- `*_faluss_events_tombstones`.

Le chemin frais crée six tables InnoDB temporaires, les vérifie puis les promeut
par un seul `RENAME TABLE`. Le seul chemin de migration accepte un schéma `1`
exact, conserve ses cinq tables et toutes leurs lignes, puis ajoute uniquement
la table de tombstones avant de déclarer le schéma `2`. Un schéma partiel ou
divergent échoue fermé; aucune réparation automatique et aucun `dbDelta()` ne
sont utilisés.

La bascule réelle exige donc une sauvegarde et un rapprochement des six tables,
des compteurs, des hooks Cron et des options sur chaque site. Ce lot ne réalise
aucune copie de données et ne modifie aucune instance WordPress.

## Transport et workers conservés

Les noms d’événements, destinations et opérations Federation historiques sont
inchangés. Le transport intersite continue de passer exclusivement par
`Faluss_Federation_Client::event_publish()` et par l’adaptateur signé
`event.publish`. Tant que le module Federation n’est pas migré et validé, son
plugin historique reste la dépendance de transport.

Les hooks suivants sont conservés :

- `faluss_events_run_outbox` ;
- `faluss_events_run_consumers` ;
- `faluss_events_run_retention`.

Chaque worker garde des lots de 50, des leases de 300 secondes, huit tentatives
au plus et les délais historiques. Les sorties réseau et callbacks s’exécutent
hors transaction. La désactivation retire uniquement ces trois hooks; elle ne
supprime aucune table, option, ligne, outbox, inbox, delivery ou tombstone.

## Validation avant bascule

Les contrats automatisés couvrent installation et migration synthétiques,
canonicalisation, événements locaux et inbound, doublons, conflits, rollback,
bornes de stockage, leases, retries, panne de transport, reprise, consumers,
expiration et purge. Ils protègent aussi la parité de contenu des classes et
schémas historiques.

Ils ne remplacent pas une recette WordPress/MariaDB réelle. Avant toute bascule,
il reste obligatoire de tester les deux sites avec leurs vraies politiques
Federation, les catalogues Portal/Link, le consumer Analytics, une panne réseau,
la récupération des leases, les trois Cron et le rapprochement des lignes sans
événement ni effet consommateur dupliqué.

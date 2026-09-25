# Faluss Platform

Plugin WordPress modulaire centralisant progressivement l’identité, les profils, le portail, les abonnements, les droits, les points, les applications et les intégrations de l’écosystème Faluss.

Le projet est au début de sa construction. Les plugins Faluss actuellement en production restent les sources de vérité jusqu’à la validation et à la migration explicite de chaque module.

## Configuration

Le plugin reste inactif tant que le rôle du site n’est pas défini dans une configuration non versionnée :

```php
define('FALUSS_PLATFORM_ROLE', 'me'); // 'hub' sur faluss.com, 'fans' sur un site Fans
```

Le socle n’active que le module d’administration : il ne remplace aucun plugin en production. Voir [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

Le rôle `fans` admet l'administration commune, un [client SSO optionnel et isolé du Hub](docs/modules/FANS-SSO.md), des [profils créateurs structurés](docs/modules/FANS-PROFILES.md), un [suivi local minimal](docs/modules/FANS-FOLLOWERS.md) et un [catalogue classable sans achat](docs/modules/FANS-STORE.md), chacun avec son opt-in. L'état des autres domaines et portes commerciales est détaillé dans [`docs/modules/FANS.md`](docs/modules/FANS.md) ; aucune vente n'est activée.

Le module optionnel des jetons visuels de `faluss.me` est décrit dans [`docs/modules/THEME-TOKENS.md`](docs/modules/THEME-TOKENS.md). Il reste désactivé sans activation explicite.

Le module optionnel du catalogue de thèmes de cartes est décrit dans [`docs/modules/CATALOG.md`](docs/modules/CATALOG.md). Sa bascule exige une validation séparée de Faluss Link et du Connector ; il reste lui aussi désactivé par défaut.

Le module optionnel Faluss Portal du Hub est décrit dans [`docs/modules/PORTAL.md`](docs/modules/PORTAL.md). Il exige Identity Client et Apps Registry, conserve les surfaces historiques et reste désactivé par défaut.

Le module critique Faluss Identity de `faluss.me` est décrit dans [`docs/modules/IDENTITY.md`](docs/modules/IDENTITY.md). Il reste désactivé par défaut et ne peut jamais être chargé avec l’ancienne autorité d’identité.

Le [Studio Faluss V2 natif](docs/modules/ME-STUDIO.md) fournit l’édition et l’onboarding Atomique au-dessus des contrats Identity, Link, Catalog et Apps Registry. Il conserve le parcours Simple et le Studio interne comme fallback, et exige son propre opt-in `FALUSS_PLATFORM_ME_STUDIO_V2` après migration et recette.

Le module commun Faluss Events est décrit dans [`docs/modules/EVENTS.md`](docs/modules/EVENTS.md). Il conserve les contrats, tables, outbox/inbox, workers et règles de rétention historiques sur les rôles `me` et `hub`, derrière un opt-in explicite et sans coexistence avec l’ancien runtime.

Le module privé Faluss Analytics est décrit dans [`docs/modules/ANALYTICS.md`](docs/modules/ANALYTICS.md). Il conserve sur le rôle `hub` le consumer Events, les reçus d’idempotence, les agrégats anonymisés et leur rétention, sans activer de producteur ni de tracking.

Le module Faluss Federation est décrit dans [`docs/modules/FEDERATION.md`](docs/modules/FEDERATION.md). Il conserve sur les deux rôles le transport Ed25519, les politiques de pairs, l’anti-rejeu et les diagnostics bidirectionnels derrière un opt-in explicite, sans créer de clé, de pair ou de politique.

Le retrait de l’outil historique Faluss Production Reset est documenté dans [`docs/modules/PRODUCTION-RESET.md`](docs/modules/PRODUCTION-RESET.md). Son runtime destructif n’est ni copié, ni chargé, ni exposé par Platform ; toute intervention sur les sites réels reste une décision de production séparée.

## Contribution

- Les règles applicables aux agents sont définies dans [`AGENTS.md`](AGENTS.md).
- Le workflow de contribution est décrit dans [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md).
- Les changements en cours sont suivis dans [`CHANGELOG.md`](CHANGELOG.md).

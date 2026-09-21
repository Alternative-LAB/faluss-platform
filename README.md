# Faluss Platform

Plugin WordPress modulaire centralisant progressivement l’identité, les profils, le portail, les abonnements, les droits, les points, les applications et les intégrations de l’écosystème Faluss.

Le projet est au début de sa construction. Les plugins Faluss actuellement en production restent les sources de vérité jusqu’à la validation et à la migration explicite de chaque module.

## Configuration

Le plugin reste inactif tant que le rôle du site n’est pas défini dans une configuration non versionnée :

```php
define('FALUSS_PLATFORM_ROLE', 'me'); // ou 'hub' sur faluss.com
```

Le socle n’active que le module d’administration : il ne remplace aucun plugin en production. Voir [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

Le module optionnel des jetons visuels de `faluss.me` est décrit dans [`docs/modules/THEME-TOKENS.md`](docs/modules/THEME-TOKENS.md). Il reste désactivé sans activation explicite.

## Contribution

- Les règles applicables aux agents sont définies dans [`AGENTS.md`](AGENTS.md).
- Le workflow de contribution est décrit dans [`docs/CONTRIBUTING.md`](docs/CONTRIBUTING.md).
- Les changements en cours sont suivis dans [`CHANGELOG.md`](CHANGELOG.md).

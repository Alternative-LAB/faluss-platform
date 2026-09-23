# Module Faluss Federation

## Portée

Le module `federation` reprend Faluss Federation `0.3.0`, schéma `1`, sur les
rôles `me` et `hub`. Il conserve le transport privé serveur-à-serveur Ed25519,
les politiques locales de pairs, les registres de providers, la route signée,
l’anti-rejeu, les limites, l’audit technique et les diagnostics dans les deux
sens. Le contrat normatif et le guide historique sont conservés dans
[`docs/FALUSS_FEDERATION_CONTRACT.md`](../FALUSS_FEDERATION_CONTRACT.md) et
[`docs/FALUSS_FEDERATION.md`](../FALUSS_FEDERATION.md).

Federation n’est ni une autorité d’identité ou économique, ni un RPC générique,
ni un transport de paiement, de profil ou de média. Les cinq opérations restent
fermées : `diagnostic.read`, `manifest.read`, `read_model.read`,
`event_catalog.read` et `event.publish`.

## Activation explicite et secrets

Le module reste entièrement désactivé sans configuration non versionnée :

```php
define('FALUSS_PLATFORM_ROLE', 'me'); // ou 'hub'
define('FALUSS_PLATFORM_FEDERATION', true);
```

Les constantes historiques de nœud, application, origine, clé, période et seed
restent inchangées. Le seed Ed25519 demeure exclusivement dans la configuration
protégée du serveur ; il n’entre jamais dans Git, une option WordPress, un log ou
une interface. Le module ne génère aucune clé et n’enregistre aucun pair. Sans
Sodium natif, configuration exacte, schéma prêt et pair exploitable, il échoue
fermé et n’enregistre aucune route opérationnelle.

Si l’une des huit classes publiques du plugin historique est déjà chargée,
Platform ne charge pas une seconde instance. Les classes compatibles conservées
sont `Faluss_Federation`, `Faluss_Federation_Admin`,
`Faluss_Federation_Client`, `Faluss_Federation_Crypto`,
`Faluss_Federation_Policy`, `Faluss_Federation_Providers`,
`Faluss_Federation_Schema` et `Faluss_Federation_Server`.

## Inventaire conservé

| Surface | Contrat conservé |
| --- | --- |
| Option | `faluss_federation_schema_version`, valeur exacte `1` |
| Tables | `*_faluss_federation_peers`, `*_faluss_federation_request_bindings`, `*_faluss_federation_nonces`, `*_faluss_federation_audit` |
| Route | `POST /wp-json/faluss-federation/v1/exchange`, uniquement lorsque le transport est prêt |
| Hooks | `rest_api_init`, `rest_pre_serve_request`, `admin_menu`, `admin_post_faluss_federation_manage`, `faluss_federation_ready` |
| Administration | Outils → Faluss Federation, capacité `manage_options`, mutations POST avec nonces et confirmations exactes |
| Shortcodes, widgets, Cron, assets | aucun |

Le schéma frais crée quatre tables InnoDB temporaires, les vérifie puis les
renomme atomiquement. Un schéma existant exact est conservé ; une structure
partielle ou divergente n’est ni réparée ni adoptée. L’activation ne supprime,
ne réécrit et n’élargit aucune clé, route, table, paire ou politique.

## Sécurité et diagnostics

Les octets JSON transmis sont hachés et signés sans réencodage. Les signatures,
les identités, la clé, la requête, la réponse, la fraîcheur et les politiques
sont contrôlées cumulativement. Nonce et liaison de requête sont consommés dans
une transaction InnoDB sous verrou de bucket ; un rejeu, une collision, une
libération de verrou incertaine ou une erreur SQL échoue fermé.

Le diagnostic sortant relit exclusivement l’origine et l’identité du pair en
base. Le receiver fournit le diagnostic entrant signé sur la même route. Les
tests automatisés couvrent les deux directions, les en-têtes canoniques, les
signatures, l’altération, l’expiration, les rejouements, la concurrence, les
limites, les réponses nullables, les traces bornées et les politiques de pairs.
La trace locale reste désactivée sauf si
`FALUSS_FEDERATION_DIAGNOSTIC_TRACE === true` et n’écrit que côté et étape
allowlistés, sans URL, payload, en-tête, identité ni secret.

## Consommateurs et condition de retrait

L’inventaire statique Platform prouve que Federation est encore appelé par :

- Apps Registry pour les validateurs de manifeste, la sélection du pair et la
  lecture distante ;
- Events pour les catalogues, `event.publish`, les workers et l’identité locale ;
- Portal et Link pour leurs manifestes et catalogues Events ;
- Analytics pour l’identité Hub et l’enregistrement de son consumer.

La condition « aucun appel ne dépend encore de Federation » est donc fausse à
ce stade. Aucun retrait n’est effectué. Supprimer le contrat, une route, une
table, une clé ou une configuration exigera un nouvel inventaire exact, une
sauvegarde vérifiée, la migration prouvée de chacun de ces consommateurs et une
autorisation explicite de production.

## Bascule et retour arrière

Une bascule réelle doit sauvegarder les quatre tables et la configuration hors
Git, rapprocher option, DDL, pairs, états, périodes, clés publiques, politiques,
compteurs et diagnostics sur `faluss.me` et `faluss.com`, puis tester les deux
sens sans modifier les permissions. Platform et le plugin historique ne doivent
jamais être actifs ensemble. Le retour arrière consiste à désactiver l’opt-in
Platform et à réactiver la même version historique avec les mêmes tables et la
même configuration, sans migration inverse ni suppression.

Les contrats locaux sont des harnais PHP et des vérifications statiques. Ils ne
constituent pas une recette WordPress/MariaDB réelle, ne prouvent aucun échange
entre les domaines de production et n’autorisent aucune activation ou écriture
de données réelles.

La bascule Platform a été réalisée sur les deux sites le 23 septembre 2026.
Les quatre tables, les pairs, les politiques et les clés configurées hors Git
ont été conservés. Un diagnostic signé `me → hub` puis `hub → me` a répondu
`success` après désactivation des deux plugins historiques.

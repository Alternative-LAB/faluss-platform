# Token Engine

## Périmètre et activation

Le module `token-engine` reprend Token Engine `0.4.1` sur le rôle `hub`. Il reste inactif sans activation explicite :

```php
define('FALUSS_PLATFORM_ROLE', 'hub');
define('FALUSS_PLATFORM_TOKEN_ENGINE', true);
```

L’ancien plugin et ce module ne doivent jamais être actifs ensemble. Le bootstrap refuse l’enregistrement dès qu’une classe historique est chargée et `TokenEngineModule` répète la garde avant tout include. Les tables existantes restent l’unique source de vérité; aucun backfill, seed, recalcul ou renommage n’est exécuté par le module.

## Matrice historique conservée

| Surface | Contrat conservé |
|---|---|
| Options | `token_engine_schema_version` version `5` et `token_engine_settings` |
| Tables InnoDB | projets, règles, ledger générique ALB, ledger PF, jetons Connector, définitions de droits et octrois |
| Ledger générique | crédits/débits entiers, solde dérivé, verrou transactionnel, idempotence et refus d’un solde négatif |
| Ledger PF | append-only, classes `earned`, `funded`, `promotional`, compensation liée et unique, sans lecture ni conversion du ledger ALB |
| Projets et accès | clients publics, empreintes de secrets, versions, jetons de cinq minutes et permissions `wallet.read`, `reward.claim`, `entitlements.read` |
| REST privé | neuf routes Connector bornées sous `token-engine/v1`, HTTPS, authentification et permissions; aucune route d’écriture générique du ledger |
| Droits | définitions `theme`, octrois manuels idempotents, révocation et historique |
| Administration | configuration, projets, règles, ledger, droits et ajustement manuel sous `manage_options` et nonces |
| Surfaces absentes | aucun cron, shortcode, AJAX public, solde mutable, paiement, Stripe, WooCommerce, Elementor ou identité locale |

Les six classes historiques et les deux assets d’administration sont conservés octet pour octet. `TokenEngineContract` est le contrat Platform étroit : Portal peut seulement demander le statut du gain Hub et réclamer ce gain fixe avec une preuve serveur. Portal ne lit pas les balances par classe, le ledger ou la récompense Me. Les façades historiques restent disponibles pour Connector et les consommateurs en transition.

## Invariants économiques

Le ledger générique `token_engine_ledger` et le sous-ledger officiel `token_engine_pf_ledger` demeurent séparés. L’historique ALB n’est jamais interprété comme PF. Une balance est toujours dérivée des lignes immuables; une correction PF ajoute une compensation de même classe liée à l’entrée d’origine et ne modifie jamais celle-ci.

Les gains Hub `20 PF earned` et Me `75 PF earned` restent cumulables mais ne sont accessibles qu’aux façades serveur historiques et à leurs preuves respectives. Un retry, un doublon ou une concurrence doit retrouver l’écriture idempotente déjà commise. Aucune activation, page, requête anonyme ou valeur navigateur ne crée une transaction.

## Validation et retour arrière

Avant bascule, il reste obligatoire de tester sur une copie représentative du Hub : migration MySQL/InnoDB v1→v5, conservation et rapprochement de tous les projets/règles/permissions/droits/octrois/jetons et des deux ledgers, concurrence réelle, soldes par classe, compensations, routes Connector HTTPS, rotation de secret, administration responsive et round-trip avec les Connectors déployés. Ces contrôles ne sont pas une autorisation de production.

Pour revenir en arrière : remettre `FALUSS_PLATFORM_TOKEN_ENGINE` à `false`, désactiver Faluss Platform si nécessaire, puis réactiver l’ancien plugin contre les mêmes tables et options. Ne supprimer, réécrire, recalculer, fusionner ou convertir aucune ligne de ledger. Toute opération sur des données réelles exige sauvegarde, inventaire exact, rapprochement et autorisation de production séparée.

La bascule Platform a été réalisée le 23 septembre 2026. Les sept tables, le schéma version `5`, les définitions et les octrois ont conservé leurs compteurs. Le Connector de `faluss.me` a ensuite obtenu un jeton court et validé les trois permissions historiques ainsi que deux définitions de droits.

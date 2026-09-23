# Migration de production du 23 septembre 2026

## Résultat

`faluss-platform` est l'unique plugin Faluss actif sur `faluss.me` et
`faluss.com`. Les anciens plugins ont été désactivés après des contrôles dans
des processus WordPress indépendants, puis retirés des répertoires de plugins.
Leurs sources restent archivées avec les sauvegardes SQL et de configuration.

| Site | Modules Platform actifs |
|---|---|
| `faluss.me` | Theme, Catalog, Token Engine Connector, Apps Registry, Identity, Link, Events, Federation |
| `faluss.com` | Identity Client, Apps Registry, Portal, Subscriptions, Token Engine, Events, Analytics, Federation |

Les options, tables, identifiants, profils, cartes, abonnements, ledgers,
événements, clés et politiques existants restent les sources de vérité. Aucun
stockage métier parallèle n'a été créé.

## Preuves de bascule

- les quatre profils publics réels répondent HTTP 200 et conservent leurs
  contenus, médias et assets Link ;
- Link consomme Identity, Catalog et Token Engine Connector par leurs contrats
  Platform, sans réclamer de récompense pendant la recette ;
- le portail membre répond HTTP 200 et projette le montant Token Engine ;
- les huit tables Subscriptions et les sept tables Token Engine conservent
  leurs compteurs ;
- le Connector rejoint Token Engine avec `wallet.read`, `reward.claim` et
  `entitlements.read` ;
- Federation réussit un diagnostic signé dans les deux sens ;
- un événement synthétique `faluss-hub.portal.viewed` a traversé Events, créé
  une livraison consumer `processed` et produit une métrique Analytics unique ;
- les conteneurs WordPress et MariaDB des deux sites sont sains.

## Incidents rencontrés

La première tentative Link n'avait pas écrit son opt-in. Le plugin historique
a été réactivé immédiatement, sans mutation de données. L'issue #29 et le
rapport dédié décrivent la correction de procédure.

La première tentative Identity relançait des migrations privilégiées pendant
une requête publique. Le rollback a été exécuté, l'issue #31 a produit le
correctif fusionné par la PR #32, puis la seconde bascule a réussi sans changer
les huit tables ni leurs compteurs.

## Limites externes constatées

Stripe reste en mode `test` mais ses six paramètres de test sont absents de la
configuration serveur. Checkout, webhook signé et Customer Portal échouent
donc fermés. Cette panne existait avant la bascule et aucun secret n'a été
inventé ou copié. Sa remise en service exige des identifiants TEST provenant de
la source Stripe autorisée, suivis de la recette du module Subscriptions.

Les anciens plugins et les deux anciennes copies de déploiement Platform sont
archivés sous `/opt/backups/faluss-platform-migration-20260923` sur l'hôte du
conteneur. Les archives `legacy-plugins-me.tar.gz` et
`legacy-plugins-com.tar.gz` permettent un retour arrière avec les bases et
configurations sauvegardées avant migration.

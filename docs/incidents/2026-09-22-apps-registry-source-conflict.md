# Conflit de source Apps Registry après la bascule Identity Client

## Détection

Le 22 septembre 2026, après la bascule de Faluss Identity Client vers son
module Platform sur `faluss.com`, une lecture réelle de
`Faluss_Apps_Registry::read_for_member()` a retourné
`faluss_apps_registry_unavailable`. L'ancien plugin Apps Registry était encore
actif : le défaut vient de l'intégration du nouvel Identity Client avec ce
registre pendant la coexistence.

## Impact

La source `faluss-me` et la source `faluss-hub` sont présentes, mais le
registre est marqué en conflit. Toute lecture membre échoue donc fermée. Le
SSO, les quatre liaisons Identity et les pages publiques restent disponibles ;
la composition applicative utilisée par Portal est indisponible.

## Cause confirmée

`IdentityClientAppsRegistryAdapter::boot()` est exécuté pendant le hook
`plugins_loaded` à la priorité 20. Il ajoute son enregistrement à la priorité
40 puis l'exécute aussi immédiatement parce que `did_action('plugins_loaded')`
est déjà vrai. WordPress exécute ensuite la callback de priorité 40 : la même
source `faluss-me` est enregistrée deux fois et le registre ferme toutes les
lectures.

## Correction attendue

Pendant l'exécution de `plugins_loaded`, différer l'enregistrement à la
priorité 40. L'appel immédiat doit rester réservé au cas où le hook est
entièrement terminé. Un test doit reproduire le démarrage du module à la
priorité 20 et garantir un seul enregistrement de source.

La bascule d'Apps Registry est suspendue jusqu'à validation et déploiement du
correctif. Après déploiement, vérifier une lecture membre réelle, les manifests
Hub et Me, la Fédération et le rendu Portal avant de poursuivre.

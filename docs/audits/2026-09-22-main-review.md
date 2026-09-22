# Revue de `main` au 22 septembre 2026

## Périmètre vérifié

`main` est passé de `f16d3b5` à `2d5f4df` en 21 commits linéaires, 303 fichiers
et environ 52 000 lignes ajoutées. Aucune PR fusionnée après la #14 n'apparaît
dans GitHub : ces commits ont été poussés directement sur `main`. Au moment de
la revue, l'API GitHub indiquait « Branch not protected » et aucun ruleset.
Les deux workflows qualité du dernier commit étaient verts. Les trois premiers
essais de correction du job Federation sans Sodium avaient échoué sur `main`
avant la quatrième correction ; un contrôle exécuté après push ne constitue pas
un verrou préalable à la fusion.

Le dépôt ajoute, derrière des constantes d'activation explicites, les modules
Connector, Identity Client, Apps Registry, Portal, Link, Subscriptions, Token
Engine, Identity, Events, Analytics et Federation. Il conserve les classes et
schémas historiques dans plusieurs couches de compatibilité, ajoute des
contrats et des tests, et documente le retrait envisagé de Production Reset.
La seule modification des modules préexistants concerne la compatibilité
Catalog. Aucun commit de cette série ne prouve une bascule réelle sur les sites.

La liste des répertoires de plugins des conteneurs WordPress réels ne contient
pas `faluss-platform` sur `faluss.me` ni sur `faluss.com` à cette date. Les
anciens plugins sont toujours présents sur les deux sites. Cette vérification
est une lecture de répertoires, pas un inventaire des états d'activation.

## Contrôles réalisés

| Contrôle | Résultat |
| --- | --- |
| `composer validate --strict` | Réussi |
| `composer analyse` | Réussi, zéro erreur |
| Syntaxe PHP de `src/` et `tests/` | Réussie |
| `composer test` après `composer install` | 131 tests, 2 088 assertions, réussis |
| Contrats historiques Subscriptions, Identity, Events, Analytics, Federation | Réussis localement |
| JavaScript local | Non exécuté : `node` absent de l'hôte ; le workflow JavaScript GitHub du dernier commit est vert |

La première exécution de PHPUnit a échoué sur `stripe_sdk_invalid` car le
`vendor/` local ne contenait pas encore `stripe/stripe-php`. `composer install`
a installé la version verrouillée `21.3.0` et la suite est passée sans changement
du code métier. Une archive déployée sans `vendor/` rencontrerait le même échec ;
la procédure de livraison doit donc inclure `composer install --no-dev`.

## Risques et travaux restants

1. **Gouvernance critique** : l'absence de protection GitHub a permis de passer
   outre `AGENTS.md` et la PR. Activer le ruleset décrit dans
   `docs/CONTRIBUTING.md`, sans acteur de contournement, puis vérifier qu'un
   push direct est rejeté. Le workflow de gouvernance renforcé doit être fusionné
   via PR avant d'être effectif sur la branche de base.
2. **Parité non démontrée sur les sites** : les tests de contrats et copies de
   sources ne remplacent ni l'essai sur données représentatives, ni les parcours
   WordPress/Elementor, ni un rollback exécuté. Reprendre l'ordre de bascule à
   partir de Theme et Catalog ; ne déclarer aucun autre module migré en
   production à ce stade.
3. **Chaîne des dépendances** : valider, sur préproduction des deux sites, le
   Connector, Identity Client, Apps Registry, Portal et Link avant les autorités
   Subscriptions, Token Engine et Identity. Rapprocher leurs tables, options,
   identités et API avant chaque bascule.
4. **Contrôles à haut risque** : Stripe TEST avec Checkout, webhook signé,
   duplications et portail client ; rapprochement intégral des ledgers et
   essais de concurrence ; OAuth PKCE et sessions ; Events intersites et
   Analytics avec métriques réellement produites ; diagnostics Federation dans
   les deux sens. Ces vérifications n'ont pas été établies par la CI.
5. **Transport Events** : la politique des pairs observée lors de la précédente
   vérification n'autorisait que `diagnostic.read` et `manifest.read`. La
   publication intersites doit être éprouvée avec un événement synthétique
   avant toute activation métier. Ne pas retirer Federation tant que ses
   consommateurs sont présents.

Cette revue n'a activé aucun module, modifié aucune donnée de production ni
utilisé de secret Stripe ou Federation.

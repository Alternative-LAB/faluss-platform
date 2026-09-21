# Migration de Faluss Portal

Faluss Portal est la surface membre privée du rôle `hub`. Le module conserve le shortcode `[faluss_portal]`, la route `/mon-faluss/`, les sections, les onglets, les retours du Customer Portal, les cartes d’applications et les catalogues descriptifs historiques. Il ne devient propriétaire d’aucune identité, offre, souscription, règle de points, donnée Analytics ou donnée métier d’une application.

## Frontières du module

| Besoin Portal | Contrat consommé | Comportement fermé |
|---|---|---|
| Session membre | `Faluss_Identity_Client::current_linked_subject()` | accès invité si la session n’est pas celle d’un unique `subscriber` lié |
| Composition d’apps | `Faluss_Apps_Registry::read_for_member($faluss_id, 'portal', '1.0.0')` | aucune décision Hub/Me inventée |
| Abonnement et facturation | `PortalSubscriptionsAdapter` | offre et Customer Portal indisponibles |
| Récompense quotidienne | `PortalTokenEngineAdapter` | action PF indisponible, sans solde ni écriture locale |
| Destination Me | contrat public Identity Client Apps Registry | carte non navigable |
| Route événementielle | `PortalAnalyticsAdapter` et contrats Events existants | aucune route si l’état exact n’est pas prêt |

Le contrat Identity Client ne retourne que le `faluss_id` opaque et les dates locales de création et de dernière preuve. Il n’expose ni e-mail, ni `wp_user_id`, ni rôle, ni secret. Portal ne lit plus directement la table de liaison.

La lecture Apps Registry est réalisée une seule fois pour le shell, puis la même projection est transmise aux deux vues Apps. Hub et Me restent les seules décisions d’exécution du registre ; Date, Fans et Pro restent des présentations indisponibles tant qu’aucun manifeste propriétaire ne les décrit.

## Autorités économiques et Analytics

Subscriptions fournit la décision d’offre, son libellé, l’intervalle et la disponibilité du Customer Portal. Portal ne stocke et ne recopie aucun prix ni droit. Token Engine fournit le montant et l’état de la récompense quotidienne : le navigateur ne transmet que l’intention fixe et le nonce ; le sujet et les données économiques restent côté serveur. Portal ne lit aucun ledger et n’écrit aucune donnée PF.

Le catalogue `faluss-hub.events` et sa route locale historique restent descriptifs. Le module ne produit, n’accepte et ne suit aucun événement. Leur enregistrement échoue fermé sans identité Hub exacte, schémas et validateurs Events prêts, catalogue propriétaire identique et consumer Analytics dans l’état exact attendu. Les harnais de contrat ne prouvent pas un transport WordPress/MariaDB opérationnel.

## Parité de présentation

La feuille de style et les cinq images effectivement utilisées par le plugin historique sont conservées octet pour octet. Le JavaScript conserve la navigation progressive, l’historique, le profil, le comportement réduit et l’appel AJAX authentifié. Sa seule adaptation fonctionnelle lit le montant retourné par Token Engine au lieu de recopier une valeur économique dans Portal ; avec la décision historique, le rendu reste identique.

## Activation contrôlée et coexistence

Portal ne s’enregistre que sur le rôle `hub`, avec ses deux dépendances publiques explicitement activées :

```php
define('FALUSS_PLATFORM_ROLE', 'hub');
define('FALUSS_PLATFORM_APPS_REGISTRY', true);
define('FALUSS_PLATFORM_IDENTITY_CLIENT', true);
define('FALUSS_PLATFORM_PORTAL', true);
```

Ces constantes appartiennent à une configuration non versionnée. Si une classe historique Portal est déjà chargée, le module ne s’enregistre pas ; son démarrage direct refuse également la collision. Pour revenir en arrière, **désactiver d’abord `FALUSS_PLATFORM_PORTAL`, charger une nouvelle requête, puis réactiver l’ancien plugin**. Aucun schéma ou transfert de données Portal n’est requis.

## Preuves et porte de bascule

Les tests automatisés couvrent les dépendances et classes historiques, l’absence d’endpoint AJAX public, le contrat Identity positif et négatif, l’unique lecture Apps Registry, les replis fermés, un montant Token Engine non codé en dur, la projection Subscriptions sans prix, l’état Analytics exact et la parité des assets non économiques.

La porte de bascule reste fermée avant une recette dédiée sur une copie représentative du Hub : ordre réel de chargement WordPress, shortcode Elementor Canvas, session SSO, Federation/Apps Registry, Subscriptions et Stripe TEST, Token Engine et concurrence, AJAX, route retour, historique navigateur, responsive, accessibilité et absence effective d’événements. Aucun test de ce lot n’active le module, ne déploie un plugin ou n’écrit dans une instance WordPress réelle.

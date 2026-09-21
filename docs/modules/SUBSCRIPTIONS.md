# Faluss Subscriptions

## Périmètre et activation

Le module `subscriptions` reprend Faluss Subscriptions `0.2.7` sur le rôle `hub`. Il reste inactif tant que les deux constantes suivantes ne sont pas définies explicitement :

```php
define('FALUSS_PLATFORM_ROLE', 'hub');
define('FALUSS_PLATFORM_SUBSCRIPTIONS', true);
```

L’ancien plugin et le module ne doivent jamais être actifs ensemble. Le bootstrap refuse d’enregistrer le module si une façade historique Subscriptions est déjà chargée; `SubscriptionsModule` répète cette garde avant tout chargement. L’ancienne base reste la source de vérité jusqu’au rapprochement complet et à une autorisation de bascule distincte.

## Matrice historique conservée

| Surface | Contrat conservé |
|---|---|
| Option | `faluss_subscriptions_schema_version`, version `2` |
| Tables InnoDB | `faluss_subscriptions`, `faluss_subscription_trials`, `faluss_entitlements`, `faluss_subscription_events`, `faluss_subscription_audit`, `faluss_billing_customers`, `faluss_billing_checkout_sessions`, `faluss_subscription_notifications` |
| Administration | capacité `manage_faluss_subscriptions`, menu et onglets Configuration, Catalogue, Membre, Abonnements, Événements, Sandbox test, Audit et Diagnostics |
| Mutations privées | `admin_post_faluss_subscriptions_admin` et `admin_post_faluss_subscriptions_sandbox_checkout`, capacité, nonce, validation, audit et PRG |
| Webhook | `POST /wp-json/faluss-subscriptions/v1/stripe/webhook`, corps brut et signature Stripe, réponse `no-store`, déduplication par ID d’événement |
| Cron | `faluss_subscriptions_daily`, rappels et réconciliation sous verrou serveur |
| Retours | contrôleur Checkout sans décision de droit; Customer Portal limité au Customer du même Faluss ID |
| Façades historiques | schéma, catalogue, dépôt, essais, entitlements, résolution, Stripe, facturation, webhooks, notifications, retours, audit, diagnostics et administration |
| Surfaces absentes | aucun shortcode, widget Elementor, AJAX public ou stockage d’e-mail/carte/payload Stripe |

Les seize fichiers métier historiques, la feuille d’administration et la licence Stripe sont conservés à l’identique. Le module ajoute uniquement le chargement ordonné, le cycle activation/désactivation et `SubscriptionsContract`, contrat étroit consommé par Portal. Les façades historiques restent disponibles pour les consommateurs en transition.

## Autorité et sécurité Stripe

Les offres, essais, abonnements, entitlements, clients, événements et audits restent centralisés sur le Hub. Faluss Identity demeure l’autorité du `faluss_id`; Subscriptions ne lit ni ne stocke d’e-mail. Portal affiche seulement la projection fournie par `SubscriptionsContract` et ne calcule aucun prix ni droit.

Les frontières SUB-01B restent inchangées : ce module n’écrit rien dans Token Engine et ne modifie aucune fonction Link. Le raccordement produit FL-21 demeure un lot séparé, soumis à son propre contrat et à une décision de bascule explicite.

`stripe/stripe-php` est verrouillé en `21.3.0` dans le Composer racine. Une livraison installable doit exécuter `composer install --no-dev --classmap-authoritative` afin d’embarquer `vendor/`; aucun secret n’est versionné. Le mode `test` reste la valeur par défaut. Le mode live échoue fermé sans `FALUSS_STRIPE_LIVE_ENABLED === true`, et les secrets/Price/Product/Portal IDs proviennent uniquement de constantes serveur hors Git. Aucun appel réseau Stripe n’est exécuté par les contrats automatisés : ils injectent un adaptateur déterministe.

## Migration, validation et retour arrière

La migration v2 est additive, verrouillée, rejouable et vérifie les huit tables avant d’écrire l’option. Un schéma partiel ou divergent échoue fermé. Les livraisons de webhook sont idempotentes; une décision relit les ressources Stripe courantes au lieu de faire confiance au navigateur ou au snapshot d’événement.

Avant bascule, il reste obligatoire de valider sur une copie représentative du Hub : MySQL/InnoDB, activation et désactivation WordPress, Stripe TEST avec webhook réellement signé, Checkout, essai avec carte, paiement, doublon, retard, échec, résiliation, Customer Portal, cron, e-mail transactionnel fourni par une intégration de confiance, administration responsive et rapprochement de toutes les tables. Aucun de ces contrôles n’est une validation live.

Pour revenir en arrière : remettre `FALUSS_PLATFORM_SUBSCRIPTIONS` à `false`, désactiver Faluss Platform si nécessaire, vérifier que le cron du module est absent, puis réactiver l’ancien plugin sans supprimer les tables, l’option, les audits ni les références Stripe. Ne supprimer ou migrer aucune donnée sans sauvegarde, inventaire exact et autorisation de production.

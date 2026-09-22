# Module Faluss Analytics

## Portée

Le module `analytics` reprend Faluss Analytics `0.1.1`, schéma `1`, uniquement
sur le rôle `hub`. Il conserve le consumer `faluss-analytics.aggregate-v1`, les
six validateurs AN-01, les reçus d’idempotence, les agrégats journaliers, le
read-model PHP privé, la suppression d’un sujet et le Cron de rétention.

Analytics reste un consommateur. Il ne produit aucun événement, n’accepte aucun
catalogue à lui seul, ne modifie aucune politique Federation et ne fournit ni
route REST/AJAX, écran, asset, cookie, pixel ou tracking. Les règles métier qui
reconnaissent une vue, une ouverture ou une récompense restent chez Portal,
Link et Token Engine.

Le contrat normatif est conservé dans
[`docs/FALUSS_ANALYTICS_CONTRACT.md`](../FALUSS_ANALYTICS_CONTRACT.md). Les six
schémas de payload et le schéma `analytics.summary` `1.0.0` restent dans
`contracts/`.

## Activation explicite

Analytics dépend du module Events sur le Hub. Les deux opt-ins sont définis
hors Git :

```php
define('FALUSS_PLATFORM_ROLE', 'hub');
define('FALUSS_PLATFORM_FEDERATION', true);
define('FALUSS_PLATFORM_EVENTS', true);
define('FALUSS_PLATFORM_ANALYTICS', true);
```

Sans l’opt-in Analytics exact, aucune de ses classes, tables ou planifications
n’est chargée. La présence d’une classe du plugin Faluss Analytics historique
empêche Platform de charger une seconde autorité. Federation peut encore être
fourni par son plugin historique pendant la transition, mais jamais en même
temps que le module Platform. Sans identité Federation Hub exacte, schéma prêt
et runtime Events disponible, le consumer reste fermé sans créer de métrique.

## Stockage et anonymisation

Le DDL historique reste inchangé et crée atomiquement trois tables InnoDB :

- `*_faluss_analytics_receipts` ;
- `*_faluss_analytics_daily_metrics` ;
- `*_faluss_analytics_daily_objects`.

Une structure partielle ou divergente n’est ni réparée ni adoptée. Le schéma
ne contient aucune donnée initiale. Les reçus ne conservent pas l’enveloppe ni
le Faluss ID brut. Le sujet est remplacé avant verrou et écriture par :

```text
SHA-256("faluss-analytics:subject:v1\n" + lowercase_faluss_id)
```

Le consumer recalcule la clé d’idempotence Events, revalide l’enveloppe et la
sémantique AN-01, puis incrémente agrégat, breakdown et reçu dans une transaction
unique. Un retry exact retrouve le reçu sans second effet ; une divergence est
refusée et une indisponibilité SQL reste retryable.

## Métriques fermées

Les seules correspondances v1 sont :

| Événement | Métrique |
| --- | --- |
| `faluss-hub.portal.viewed` | `hub.portal.raw_views` |
| `faluss-hub.app.opened` | `hub.app.opens` |
| `faluss-hub.daily-reward.claimed` | `hub.daily_reward.claims` |
| `faluss-me.card.viewed` | `me.card.raw_views` |
| `faluss-me.link.clicked` | `me.link.clicks` |
| `faluss-me.collection.opened` | `me.collection.opens` |

`analytics.summary` omet une métrique absente : il ne fabrique jamais un zéro.
Les visiteurs uniques restent `not_supported`; aucun identifiant visiteur,
cookie ou fingerprint n’est dérivé pour les rendre disponibles.

## Rétention et suppression

Le hook historique `faluss_analytics_run_retention` reste horaire, borné à des
lots de 50 et protégé par verrou. Les reçus expirent après exactement 30 jours
et les agrégats journaliers après 25 mois. La suppression d’un sujet retire ses
agrégats, mais conserve ses reçus jusqu’à leur expiration pour empêcher qu’un
retry tardif ne recrée les métriques. La désactivation retire uniquement le
hook ; elle ne supprime aucune table ni donnée.

## État AN-01B.3 et preuve disponible

Les routes fermées historiques de Portal et Link, leurs catalogues et leurs
validateurs producteurs sont conservés. AN-01B.3 n’est toutefois pas considéré
comme recetté : aucun producteur métier réel n’est activé par cette migration et
aucune politique Federation n’est modifiée.

Les contrats automatisés utilisent uniquement des événements synthétiques non
sensibles. Ils prouvent qu’un événement AN-01 accepté par les validateurs de
production et livré au consumer crée la métrique attendue, qu’un rejeu exact ne
l’incrémente pas deux fois et qu’un read-model vide n’invente aucun zéro. Ils
testent aussi les rollbacks, collisions, bornes, anonymisation et purges.

Cette preuve reste un harnais PHP contrôlé. Elle ne remplace pas une recette
WordPress/MariaDB/Federation réelle. Avant toute bascule, il faudra sauvegarder
et rapprocher les trois tables et l’option de schéma, vérifier le Cron unique,
livrer les six événements depuis leurs véritables points de commit métier et
prouver le trajet Events → consumer → agrégat sur les deux sites sans doublon.

# AN-01A — Contrat des Analytics Faluss et des premiers événements métier

## Statut et frontière

AN-01A fixe exclusivement le contrat documentaire du futur moteur Analytics et
des six premiers faits métier Faluss. Il ne crée aucun plugin, ZIP, table,
migration, catalogue installé, provider, producteur, consommateur, politique
réelle, route, appel réseau, cookie, fingerprint, tracking, cron, worker
supplémentaire, événement, écran, graphique ou comportement WordPress.

Les mots **DOIT**, **NE DOIT PAS** et **PEUT** sont normatifs. AN-01B reste le
lot d'implémentation. Aucune donnée réelle n'est créée, transmise, agrégée ou
modifiée par le présent contrat.

La topologie future est fermée :

- `faluss.com` héberge l'autorité centrale `faluss-analytics` sur `hub-node` ;
- Faluss Hub produit localement ses faits sur `hub-node` ;
- Faluss Me produit sur `me-node`, puis publie vers `faluss.com` par Federation ;
- Faluss Me pourra lire ses propres agrégats par un read-model privé signé ;
- Faluss Link, Portal et Master Profile ne stockent aucune copie Analytics.

Le moteur propriétaire (`faluss-portal` ou `faluss-link`) reconnaît et commet
le fait original. Faluss Events journalise et route l'enveloppe sans posséder
le fait. Federation transporte seulement entre nœuds approuvés. Analytics
agrège les livraisons autorisées sans devenir producteur ni source métier.

## Sources propriétaires réservées

Deux capacités CAP `event_source`, et seulement celles-ci, sont réservées pour
AN-01 :

| Source | Nœud | Application / owner | Moteur propriétaire | Capacité | Catalogue |
| --- | --- | --- | --- | --- | --- |
| Faluss Hub | `hub-node` | `faluss-hub` | `faluss-portal` | `faluss-hub.events` | `1.0.0` |
| Faluss Me | `me-node` | `faluss-me` | `faluss-link` | `faluss-me.events` | `1.0.0` |

La seule destination admise par chacune des six définitions AN-01 est
`analytics.events`. `quests.events` et `progression.events` restent des noms
EVT réservés mais sont inactifs et invalides pour ces six définitions. Un
catalogue, une capacité ou un binding ne constitue jamais seul une
autorisation d'émettre.

Le futur moteur Analytics est distinct : owner `faluss-analytics`, nœud
`hub-node`, application d'hébergement `faluss-hub`, destination consommée
`analytics.events` et consumer key `faluss-analytics.aggregate-v1`. Il ne
devient jamais owner des événements sources et ne commande aucune action dans
Portal, Link, Token Engine, Quêtes ou Progression.

## Contrats de payload 1.0.0

Les six schémas Draft 2020-12 sont fermés, minimaux et autonomes :

- [`faluss-hub-portal-viewed.schema.json`](../contracts/faluss-hub-portal-viewed.schema.json), document `faluss-hub.portal-viewed` ;
- [`faluss-hub-app-opened.schema.json`](../contracts/faluss-hub-app-opened.schema.json), document `faluss-hub.app-opened` ;
- [`faluss-hub-daily-reward-claimed.schema.json`](../contracts/faluss-hub-daily-reward-claimed.schema.json), document `faluss-hub.daily-reward-claimed` ;
- [`faluss-me-card-viewed.schema.json`](../contracts/faluss-me-card-viewed.schema.json), document `faluss-me.card-viewed` ;
- [`faluss-me-link-clicked.schema.json`](../contracts/faluss-me-link-clicked.schema.json), document `faluss-me.link-clicked` ;
- [`faluss-me-collection-opened.schema.json`](../contracts/faluss-me-collection-opened.schema.json), document `faluss-me.collection-opened`.

Chacun annonce son `$id`, son titre, son `x-document-type` et sa
`x-contract-version` exactement `1.0.0`. `portal.viewed` contient uniquement
`surface_key`, limité à `hub`, `apps` ou `explore`. Les cinq autres payloads
sont exactement `{}` : leur sujet, acteur, objet, source et identité de fait
restent dans les contextes de l'enveloppe EVT et ne sont jamais répétés.

## Faits Faluss Hub

### `faluss-hub.portal.viewed`

Le sujet est le membre Faluss authentifié courant et l'acteur est ce même
membre. L'événement est admissible une fois par rendu serveur qualifié d'une
surface `hub`, `apps` ou `explore`. AJAX, REST, cron, préchargement, asset ou
rendu non membre n'émettent rien. La date, la surface et le sujet sont résolus
côté serveur.

### `faluss-hub.app.opened`

Le sujet et l'acteur sont le membre courant. `object_context.object_type` vaut
`app` et `object_reference` est une référence opaque serveur de la cible.
L'événement atteste une ouverture effectivement acceptée par le propriétaire,
jamais un affichage, une URL, une destination libre ou une valeur choisie par
le navigateur. Son payload est `{}`.

### `faluss-hub.daily-reward.claimed`

Le sujet et l'acteur sont le membre courant. Le fait ne peut être construit
qu'après confirmation du commit PF par Token Engine pour la règle existante
Hub `20 PF earned`, catégorie `daily_accrual`. Le payload est `{}` et ne porte
ni montant, solde, classe PF, référence ledger, clé d'idempotence économique ou
UUID d'écriture. Un retry qui retrouve l'écriture PF existante ne produit pas
un second événement. Analytics ne crédite, compense ni modifie jamais de PF.

## Faits Faluss Me

Pour les trois événements Me, le sujet est obligatoirement le propriétaire de
la carte publiée. L'acteur vaut exactement :

```json
{
  "actor_type": "anonymous",
  "actor_faluss_id": null,
  "anonymous_reference": null,
  "anonymous_scope": null
}
```

Une session Faluss du visiteur ne change pas cette règle. Aucune identité,
référence anonyme persistante, cookie ou empreinte visiteur n'est créée.

### `faluss-me.card.viewed`

Une vue est comptée à chaque rafraîchissement public qualifié de la carte
publiée. Elle est une vue brute, jamais un visiteur unique. La consultation par
le propriétaire, un administrateur, Studio, un preview, un brouillon ou une
carte non publiée n'émet rien. L'objet est nul et le payload est `{}`.

### `faluss-me.link.clicked`

Le clic doit d'abord être reconnu et accepté par Faluss Me.
`object_context.object_type` vaut `link`; la référence opaque représente le
lien sans exposer UUID, URL, domaine, titre ou libellé. Le payload est `{}`.

### `faluss-me.collection.opened`

L'ouverture doit d'abord être reconnue et acceptée par Faluss Me.
`object_context.object_type` vaut `collection`; la référence opaque n'expose
ni UUID, nom, slug ou contenu de collection. Le payload est `{}`.

## Références opaques et idempotence

Une référence d'objet AN-01 suit exactement l'une des formes
`an01_app_<sha256-hex-minuscule>`, `an01_link_<sha256-hex-minuscule>` ou
`an01_collection_<sha256-hex-minuscule>`. La référence d'occurrence suit
exactement `an01_event_<sha256-hex-minuscule>`. Ces séparateurs `_` rendent les
valeurs compatibles avec la référence opaque EVT ; toute ancienne forme
`an01:<type>:<digest>` est invalide, sans transition ni double format.

Le digest d'objet est calculé côté propriétaire ainsi :

```text
SHA-256("faluss-an01:v1\n" + owner_app_key + "\n" + object_type + "\n" + canonical_owner_object_identifier)
```

`canonical_owner_object_identifier` est l'identifiant interne canonique détenu
et résolu par le propriétaire. Sa valeur source n'est jamais placée dans
l'enveloppe, un payload, un log ou le read-model. Le préfixe namespacé empêche
les rapprochements entre types ou propriétaires ; le digest est stable et non
réversible dans le contrat. Une référence brute, aléatoire à chaque requête ou
issue du navigateur est invalide.

`source_event_reference` reste l'identité opaque et stable de l'occurrence
métier selon EVT-01A. Son digest conserve strictement la préimage
`"faluss-an01:event:v1\n" + owner_app_key + "\n" +
canonical_occurrence_identifier`; seul le séparateur de la valeur finalement
exposée est `_`. Pour le daily reward, elle dérive côté serveur de l'occurrence
PF déjà commise sans exposer sa clé ledger. Le retry strictement identique
conserve événement et référence ; il ne crée pas une deuxième vue, ouverture,
récompense ou conséquence Analytics.

## Read-model privé `analytics.summary`

[`faluss-analytics-summary.schema.json`](../contracts/faluss-analytics-summary.schema.json)
définit le document `analytics.summary` `1.0.0`. Il est privé et accessible
uniquement au propre sujet authentifié, résolu et réautorisé côté serveur. Le
document omet lui-même tout `faluss_id`.

Les états fermés sont `ready`, `empty`, `unavailable` et `not_supported`. Le
document porte les périodes UTC demandée et produite côté serveur, les totaux
de métriques autorisées, une série quotidienne bornée, des breakdowns bornés
par type d'événement et référence d'objet opaque, la fraîcheur, la source et la
compatibilité. `ready` exige au moins un total ; les trois autres états ne
portent aucun total, point ou breakdown.

Les métriques v1 sont exclusivement :

- `hub.portal.raw_views` ;
- `hub.app.opens` ;
- `hub.daily_reward.claims` ;
- `me.card.raw_views` ;
- `me.link.clicks` ;
- `me.collection.opens`.

`measurement_semantics.raw_views` vaut `qualified_event_count`. Les visiteurs
uniques sont explicitement `not_supported`, avec total `null` et série vide.
Aucun cookie, fingerprint ou identifiant anonyme persistant ne peut être
inventé pour les rendre disponibles.

Le read-model n'expose jamais Faluss ID, e-mail, handle, URL, IP, User-Agent,
session, cookie ou identité visiteur ; enveloppe EVT, `event_id`, hash, inbox,
outbox, delivery ou donnée Federation ; score, PF, classement, entitlement ou
facturation. Il n'est ni une source économique ni une autorisation.
`analytics.summary` est réservé comme futur module privé compatible Master
Profile, sans être activé par MP-01B ni par AN-01A.

## Confidentialité et rétention futures

Le futur runtime respectera les plafonds suivants :

- faits EVT bruts Analytics : 90 jours maximum ;
- reçus d'idempotence Analytics : au moins 30 jours et une durée couvrant tous
  les retries ;
- agrégats journaliers : 25 mois maximum ;
- totaux propres au membre : supprimables avec la suppression de son identité ;
- identité visiteur : collecte et conservation interdites.

Aucune donnée Analytics n'est publique. Publicité, profilage inter-applications
et revente sont interdits. Ce contrat ne prétend ni établir une base légale ni
obtenir une exemption de consentement. Il fixe une collecte minimale sans
cookie ; la conformité juridique et l'information utilisateur devront être
validées avant toute ouverture publique.

## Politiques Federation futures

Sans modifier une politique réelle, AN-01A réserve les contrôles cumulatifs :

- Me vers Hub exige l'opération exacte `event.publish` ;
- la capacité entrante est exactement `faluss-me.events` ;
- la lecture du catalogue Me exige `event_catalog.read` et cette même capacité ;
- aucun wildcard n'est accepté ;
- `owner_apps` et `audiences` n'autorisent jamais `event.publish` ;
- aucun plugin ou migration ne crée ni ne modifie automatiquement la politique.

Signature, fraîcheur, anti-rejeu, pair, catalogue, manifeste CAP, binding et
validateur spécialisé restent indépendants et obligatoires. Federation ne
devient ni producteur, ni propriétaire métier, ni moteur Analytics.

## Portée vérifiable

Le `x-an01a-scope` des sept nouveaux schémas annonce exactement 16 chemins :
nature `documentary-contract-only`, neuf ajouts et sept documents mis à jour.
Le test AN-01A contrôle ce manifeste et les scénarios positifs et négatifs sans
constituer un runtime JSON Schema ou WordPress.

Les neuf ajouts sont le présent document, six schémas de payload, le schéma
`analytics.summary` et le test AN-01A. Les sept mises à jour sont
`FALUSS_EVENTS_CONTRACT.md`, `FALUSS_CAPABILITIES_CONTRACT.md`,
`FALUSS_FEDERATION_CONTRACT.md`, `MASTER_PROFILE_CONTRACT.md`,
`ARCHITECTURE.md`, `DATA_MODEL.md` et `ROADMAP.md`.

AN-01A ne modifie aucun fichier sous `plugins/`, schéma EVT existant, manifeste
CAP runtime, politique Federation, table, option, donnée ou configuration. Il
ne fournit aucune recette WordPress parce que rien n'est installable.

## AN-01B.1 — moteur et premier consommateur réel

AN-01B.1 matérialise séparément Faluss Analytics `0.1.0`, schéma `1`, uniquement
sur `hub-node` / `faluss-hub` / `https://faluss.com`. Le plugin reste fermé sans
fatal lorsque cette identité Federation, Faluss Events ou son propre schéma est
indisponible. Il ne crée ni catalogue, provider, route, producteur, événement,
politique, endpoint, UI, cookie, pixel ou tracking.

Trois tables privées InnoDB conservent respectivement les reçus minimaux pendant
exactement 30 jours, les métriques quotidiennes et les breakdowns quotidiens
d'objets pendant au plus 25 mois. Le Faluss ID est remplacé avant verrou et
écriture par `SHA-256("faluss-analytics:subject:v1\n" + lowercase_faluss_id)` ;
ni l'identifiant brut ni sa préimage ne sont journalisés.

Le consumer `faluss-analytics.aggregate-v1` vise uniquement
`analytics.events`, sur Hub, depuis les tuples exacts `faluss-hub.events` et
`faluss-me.events` `1.0.0`. Il recalcule la clé d'idempotence Events, revalide
les six sémantiques AN-01, sérialise événement et sujet par des verrous hachés,
incrémente les agrégats et crée le reçu dans une transaction unique. Un reçu
exact rend le retry sans effet ; toute divergence est permanente et toute
indisponibilité SQL est retryable.

La façade PHP privée `analytics.summary` utilise un snapshot cohérent, une
période serveur de 800 jours au plus et la fenêtre conservée de 25 mois. Elle
omet les métriques absentes, borne les objets à 200, n'expose aucune identité ou
donnée de transport et conserve `unique_visitors = not_supported`. Elle n'est
enregistrée ni dans Federation, Portal ou Master Profile. La suppression d'un
membre retire seulement métriques et objets ; ses reçus restent jusqu'à leur
expiration. Le hook unique `faluss_analytics_run_retention` exécute les purges
UTC par lots bornés sous verrou consultatif.

### AN-01B.1.1 — round-trip canonique

Faluss Analytics `0.1.1`, schéma `1`, conserve strictement les quatre clés de
l'acteur anonyme et valide chacune par son nom et sa valeur, indépendamment de
l'ordre du tableau PHP. Les trois événements Me restent donc valides après le
tri canonique Faluss Events, le stockage JSON et le redécodage. Toute clé
absente ou supplémentaire, tout acteur non anonyme, Faluss ID, référence ou
scope anonyme non nul reste refusé. Aucun contrat, schéma, stockage ou runtime
Events n'est modifié par ce correctif.

## AN-01B.2 — capacités et catalogues propriétaires

Faluss Portal `0.1.23` publie dans le manifeste Hub `1.0.0` la capacité
`faluss-hub.events`; Faluss Link `0.3.20` publie dans le manifeste Me `1.0.0`
la capacité `faluss-me.events`. Chacune déclare uniquement l'interface
`event_source` et le binding `analytics.events`. Le Hub conserve en outre, sans
changement, `faluss-hub.daily-reward`; Me ne déclare aucune autre capacité.

Les deux catalogues `faluss.event-source-catalog` `1.0.0` décrivent exactement
les trois événements AN-01 de leur propriétaire. Leurs providers sont locaux,
fermés sur l'identité Federation et le contexte `event_catalog.read`, et
enregistrés dans l'unique registre Faluss Events existant. L'enregistrement ne
vaut ni acceptation de catalogue ni autorisation de transport : aucune ligne,
route, politique, production, émission ou métrique n'est créée.

## AN-01B.3 — validateurs producteurs et routes fermées

Portal `0.1.24` lie le tuple Hub exact à `analytics.events` en mode local, vers
le consumer Analytics Hub déjà enregistré. Link `0.3.21` lie le tuple Me exact
à la même destination en mode Federation, avec la cible immuable
`hub-node/faluss-hub`. Les deux routes exigent identité, schéma et catalogue
propriétaire résolu ; la route Hub exige en plus Analytics prêt sans conflit,
et la route Me un transport Federation prêt.

Sur Me uniquement, trois validateurs producteurs fermés acceptent les contrats
`card-viewed`, `link-clicked` et `collection-opened` `1.0.0`, leur mapping exact
d'événement, la source Me exacte et un payload vide. L'ordre des clés est sans
effet, mais toute clé, valeur, source, version, alias ou donnée supplémentaire
est refusée. Ce lot n'accepte aucun catalogue, n'autorise aucune opération et
ne produit encore aucun événement réel.

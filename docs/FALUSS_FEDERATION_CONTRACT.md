# FED-01A.1 — Contrat du transport privé fédéré Faluss

## Statut et frontière

FED-01A.1 ferme uniquement le contrat du futur transport privé fédéré entre des
nœuds Faluss approuvés : `faluss.com`, `faluss.me` et un domaine futur déclaré
explicitement. Il ne livre aucun plugin, route, table, migration, option,
écran, clé, appel réseau, donnée membre, cache durable, asset ou archive.
L'implémentation est réservée à FED-01B, après ce contrat et avant CAP-01B.

Le transport est bidirectionnel et strictement serveur-à-serveur. Il ne passe
jamais par le navigateur, ne partage aucune session WordPress, ne donne aucun
accès direct aux tables d'un autre moteur et n'est ni un registre métier, ni un
broker, ni un relais d'impersonation, ni un canal de code exécutable. Il ne
porte aucune mutation économique ou commerciale générique.

FPR, Token Engine Connector, Faluss Identity (secret client, codes, PKCE et
session) et Stripe/Faluss Subscriptions gardent leurs transports spécialisés.
Ils ne sont ni encapsulés, ni routés, ni remplacés par Federation.

## Nœuds, clés et politique locale

Chaque nœud possède sa propre paire Ed25519. La clé privée reste hors Git et
hors base/options WordPress exportables, dans une configuration protégée du
serveur. La clé publique et ses métadonnées peuvent être enregistrées par le
runtime futur seulement. Il n'existe aucune clé partagée globale et aucune clé
FPR ne peut être réutilisée.

Un `key_id` est opaque, versionné et borné. Son état local fermé est `active`,
`rotating`, `revoked` ou `expired`. Une rotation accepte au plus une ancienne
et une nouvelle clé pendant une fenêtre bornée ; une clé `revoked` est refusée
immédiatement. Ed25519 est l'unique algorithme : HMAC, RSA, crypto maison,
signature absente et mécanisme de repli sont interdits. Si Sodium est absent
dans un runtime futur, ce runtime échoue fermé sans erreur fatale et n'émet ni
n'accepte aucun échange.

La confiance est une politique locale attachée à une clé publique : identité
exacte du nœud et de l'application émetteurs, nœud et application destinataires
exacts, origine HTTPS canonique exacte du pair, opérations admises,
applications propriétaires, capacités exactes, audiences maximales, état de clé
et période de validité. L'URL sortante vient exclusivement de cette
configuration locale approuvée, jamais d'un navigateur, d'une requête, d'un
manifeste distant ou d'un payload. Aucun wildcard global, rôle WordPress,
authentification navigateur, DNS, IP, `Referer` ou `Origin` n'élargit cette
politique. Un manifeste distant n'installe pas une clé et ne crée pas de
confiance.

La politique applique quatre contrôles séparés et cumulatifs : cryptographie,
politique locale, manifeste signé accepté/compatible et contrat spécialisé. La
réussite de l'un ne remplace jamais les trois autres. Pour
`diagnostic.read`, elle exige l'opération, l'émetteur, la clé et le destinataire
exacts, sans sujet ni paramètre. Pour `manifest.read`, `parameters.app_key`
doit être dans `owner_apps` et être exactement `recipient.app_key`. Pour
`read_model.read`, `owner_app_key` doit être dans `owner_apps` et être
exactement `recipient.app_key`; la capacité, l'interface, le type de document,
la version et l'audience doivent être déclarés par le manifeste signé, accepté
et compatible du propriétaire. Aucune capacité ou interface absente d'un
manifeste ne peut être demandée ou inventée.

## Transport réservé et limites

La seule route réservée au runtime futur est :

```text
POST /wp-json/faluss-federation/v1/exchange
```

Elle n'est pas enregistrée par FED-01A. Toute cible future utilise HTTPS au
port canonique, sans query string, fragment, userinfo ni redirection, avec
`sslverify` actif et `Content-Type: application/json`. CORS ne confère aucun
droit. La résolution d'hôte, les redirections, `Origin`, `Referer` et l'adresse
IP ne sont pas des preuves d'identité.

FED-01B bornera explicitement taille brute de requête/réponse, profondeur JSON,
nombre de champs et d'éléments, taille de chaîne, délai de connexion, délai
total, débit par clé/opération et erreurs cryptographiques. Les plafonds
contractuels sont 65 536 octets, profondeur 16, 128 champs/éléments, chaînes
de 4 096 octets, connexion 3 secondes et total 10 secondes. Les réponses
porteront `Cache-Control: private, no-store` et `X-Content-Type-Options:
nosniff`; aucun payload métier n'est durablement mis en cache.

Chaque réponse porte exactement une fois les en-têtes `Content-Type:
application/json`, `Cache-Control: private, no-store`,
`X-Content-Type-Options: nosniff`, `X-Faluss-Federation-Key-Id`,
`X-Faluss-Federation-Content-SHA256` et
`X-Faluss-Federation-Signature`. Les trois derniers sont chacun uniques : le
premier égale `responder.key_id`, le deuxième est le SHA-256 hexadécimal
minuscule des octets bruts exacts de la réponse et le troisième est la
signature Ed25519 détachée base64url canonique sans padding. Ils ne sont jamais
dans le corps JSON. Leur absence, duplication, format invalide ou contradiction
est un refus fermé.

## Enveloppe de requête

Le schéma autonome Draft 2020-12
[`faluss-federation-request.schema.json`](../contracts/faluss-federation-request.schema.json)
impose une enveloppe fermée :

- `protocol_version` vaut `1` et `message_type` vaut `request` ;
- `request_id` est un UUID v4 ;
- `operation` appartient exclusivement à `diagnostic.read`, `manifest.read`,
  `read_model.read` ou `event_catalog.read` ;
- `sender` contient le `node_id`, l'`app_key` et le `key_id` exacts ;
  `recipient` contient le nœud et l'application exacts ;
- `issued_at` et `expires_at` sont RFC3339 UTC ; la durée maximale est cinq
  minutes et la dérive d'horloge serveur maximale est 60 secondes ;
- `nonce` est exactement `random_bytes(32)` encodé base64url canonique sans
  padding sur 43 caractères, non rejouable ;
- `subject_context` est soit `null`, soit seulement un `subject_faluss_id`
  UUID v4 ;
- `parameters` est fermé par opération.

Pour `read_model.read`, `parameters.audience` est l'unique audience demandée et
l'unique valeur de politique, manifeste/interface, filtrage producteur et
contrat spécialisé. `event_catalog.read` ne possède aucune audience.
`requested_audience` et toute deuxième audience sont invalides.
`subject_context` est nul pour `diagnostic.read`, `manifest.read` et
`event_catalog.read`. Pour
`read_model.read`, le contrat spécialisé décide si le contexte sujet est
présent : il l'est uniquement lorsqu'il lie le document à un membre. Le Faluss
ID ne vient jamais d'un navigateur, n'apparaît jamais dans une URL, le DOM, un
cache public ou un journal, et ne constitue jamais seul une autorisation. Le
producteur le résout et le réautorise côté serveur.

| Opération | Paramètres admis | Résultat interdit |
| --- | --- | --- |
| `diagnostic.read` | objet vide | sujet, secret, chemin serveur, version PHP, table, option ou configuration |
| `manifest.read` | `app_key`, `requested_manifest_version` nullable | Faluss ID, audience, membre ou octets d'asset |
| `read_model.read` | application propriétaire, capacité exacte, type de document, version de contrat, audience exacte | wildcard, action déléguée ou mutation |
| `event_catalog.read` | `owner_app_key`, `capability_key`, `catalog_version` exacts | audience, Faluss ID, événement, payload, destination, URL ou clé |

`diagnostic.read` ne rend que protocole, nœud, clé, permissions et horloge.
`manifest.read` ne rend qu'un manifeste non-membre et ses métadonnées ; un
asset éventuel reste une référence, jamais ses octets. Pour
`read_model.read`, le producteur peut réautoriser, réduire ou refuser et le
transport ne transforme jamais le read-model reçu.

## Canonicalisation, signature et anti-rejeu de requête

Le corps JSON est sérialisé une seule fois, comme chaîne UTF-8 sans BOM. Ses
octets bruts réellement transmis sont hachés SHA-256 hexadécimal minuscule,
envoyés et vérifiés sans décodage, normalisation ni réencodage JSON. Aucune
nouvelle canonicalisation JSON n'existe. La signature Ed25519 détachée et le
nonce sont base64url canoniques sans padding : le nonce se décode en exactement
32 octets et sa réencodage est identique; la signature se décode en exactement
64 octets et sa réencodage est identique. Une simple expression régulière ne
suffit jamais.

Les en-têtes de requête obligatoires, uniques et hors corps sont :

```text
X-Faluss-Federation-Key-Id
X-Faluss-Federation-Content-SHA256
X-Faluss-Federation-Signature
```

La chaîne canonique de requête est formée dans cet ordre fixe, séparée
exclusivement par l'octet LF `0x0A` :
`protocol_version`, méthode HTTP, chemin exact, `sender.node_id`,
`recipient.node_id`, `sender.key_id`, `issued_at`, `expires_at`, `nonce` et
SHA-256 hexadécimal des octets bruts. La méthode est exactement `POST`, le
chemin exactement `/wp-json/faluss-federation/v1/exchange`. Il n'y a ni CR,
ni LF final, ni CR/LF dans une valeur composante. Les valeurs de l'enveloppe,
des en-têtes, de la chaîne et du corps doivent toutes correspondre ; le
vérificateur reconstruit la même chaîne à partir des données reçues et de ses
attentes locales, avant dispatch.

Le nonce provient de `random_bytes` dans le runtime futur. Après vérification
crypto et avant dispatch, sa consommation est atomique sur le triplet
`(sender_node_id, key_id, nonce)`. La liaison du `request_id` est conservée par
émetteur : `(sender_node_id, request_id) → request_body_sha256`. Un même
émetteur ne peut jamais le réutiliser avec un autre corps, même après rotation
de clé; un autre nœud reste isolé dans son propre espace. La rétention
anti-rejeu est au minimum la durée de la requête plus la dérive admise. Une
réémission automatique ne contourne pas ces contrôles.

## Opérations closes et interdictions

La liste précédente est exhaustive. Sont notamment interdits :

- tout transport d'enveloppe événementielle autre que l'opération fermée
  `event.publish` enregistrée par Faluss Events ;
- RPC arbitraire, action déléguée générique, écriture de profil ou de registre ;
- claim, PF, débit, crédit, achat, paiement, entitlement, cosmétique, Fans,
  Shop, progression ou quête ;
- upload, proxy média, contenu binaire, copie de contenu privé ou lecture de
  table distante ;
- partage/réutilisation FPR, Identity ou Token Connector, HMAC, RSA, crypto
  maison, repli non signé, navigateur ou session WordPress.

Toute demande invalide, non autorisée, expirée, incompatible ou non conforme
échoue fermée. Elle n'entraîne ni accès direct, ni cache ancien, ni valeur
inventée, ni deuxième transport de secours.

## Coordination avec EVT-01A et EVT-01B.2B

`event.publish` est la cinquième et seule opération d'écriture transportée. Elle
accepte exactement `parameters.event` et `subject_context = null` sur la route
signée existante. Elle n'est pas un RPC ni un bus générique. Source node/app/
owner, destinataire, capacité, catalogue accepté et consommateurs doivent tous
correspondre exactement au pair et aux registres locaux.

EVT-01A ajoute les contrats documentaires
[`faluss.event`](../contracts/faluss-event-envelope.schema.json) et
[`faluss.event-source-catalog`](../contracts/faluss-event-source-catalog.schema.json).
EVT-01B.1 conserve sa lecture `event_catalog.read`. EVT-01B.2B enregistre par
Faluss Events trois callables distincts : validation de requête, réception
inbound et validation d'accusé. Le succès porte exclusivement
`faluss.event-acceptance` 1.0.0, avec l'ID, le SHA-256 canonique et la disposition
`accepted` ou `existing`. Une politique existante ne contenant pas
`event.publish` continue de refuser ; aucune politique n'est migrée.

Cette extension ne réutilise aucun secret FPR, Faluss Identity, Token Engine
Connector, Stripe ou session WordPress.

### Politique future AN-01A

AN-01A documente sans mutation la publication future de Faluss Me vers Hub.
Elle exigera l'opération exacte `event.publish` et la capacité entrante exacte
`faluss-me.events`. La lecture du catalogue Me exigera séparément
`event_catalog.read` et cette même capacité. Aucun wildcard n'est admis ;
`owner_apps` et `audiences` ne participent jamais à l'autorisation de
`event.publish`.

Aucun plugin, installateur ou migration ne peut créer ou élargir cette
politique automatiquement. Le pair, la clé, la source, le destinataire, le
catalogue, le manifeste CAP, le binding `analytics.events` et le validateur de
payload restent des contrôles cumulatifs. Federation transporte seulement :
elle ne devient ni propriétaire des six sources, ni moteur Analytics, ni
autorité d'identité visiteur.

## Enveloppe, signature et fraîcheur de réponse

Le schéma autonome Draft 2020-12
[`faluss-federation-response.schema.json`](../contracts/faluss-federation-response.schema.json)
impose `protocol_version: "1"`, `message_type: "response"`, le `request_id`
et le hash de la requête, l'identité complète du répondeur, le destinataire,
des dates UTC bornées, un statut fermé, `payload_contract`, `payload` et
`error`.

Les statuts sont exclusivement `success`, `empty`, `not_available`,
`not_authorized`, `incompatible`, `temporarily_unavailable`, `invalid_request`
et `replay_rejected`. Un succès porte obligatoirement un type et une version de
contrat de payload exacts avec un payload non vide. `empty` porte exactement
`{}` et aucun contrat inventé. Pour chaque autre statut,
`payload_contract` est `null`, `payload` est exactement `{}` et `error` est
obligatoire, non nul, borné, sans donnée sensible ni retour ligne, avec
`error.code` strictement égal au statut. Les branches sont exprimées dans le
schéma JSON, pas seulement dans un helper.

La réponse est sérialisée une seule fois et signée Ed25519 hors corps. Sa chaîne
UTF-8 sans BOM est séparée exclusivement par LF `0x0A`, sans CR, sans LF final
ni CR/LF de composante. Elle lie dans cet ordre : `protocol_version`, statut HTTP
numérique réellement reçu,
`request_id`, hash du corps de requête, `responder.node_id`,
`recipient.node_id`, `responder.key_id`, `generated_at`, `expires_at` et
SHA-256 hexadécimal du corps brut de réponse. Le consommateur reconstruit la
même chaîne à partir des données reçues et de ses attentes locales.

Une réponse n'est valide que si son `request_id` et son hash correspondent à la
requête originale, si `responder.node_id`/`responder.app_key` égalent le nœud et
l'application `recipient` de la requête, si son destinataire égale l'émetteur
initial et si l'en-tête de clé correspond à une clé du nœud/application attendus
à l'état et dans la période valides. Le consommateur vérifie aussi signature,
hash brut, en-têtes, contrat spécialisé et fraîcheur. À l'instant de validation,
`expires_at > generated_at`, la durée vaut au plus 300 secondes,
`generated_at` ne dépasse pas 60 secondes dans le futur, une expiration au-delà
de 60 secondes est refusée et `generated_at` ne précède pas
`request.issued_at` de plus de 60 secondes. Une signature valide ne remplace
aucun de ces contrôles.

## Confidentialité, audit et coordination

Un audit futur peut contenir seulement identifiant de requête, nœuds, opération,
capacité, résultat non sensible, instant UTC, durée et code de diagnostic
opaque. Il n'enregistre jamais Faluss ID, corps, payload, signature, matériau
de clé, nonce brut, e-mail, session, URL membre, paiement ou contenu privé.
Aucune réponse de diagnostic ne divulgue secret, chemin, table, option,
configuration ou information d'infrastructure.

Les manifestes signés et acceptés sont la source du futur registre :
applications, capacités, bindings et actions n'en sont qu'un sous-ensemble.
Le registre ne peut rien ajouter qui soit absent d'un manifeste ; il revalide
version et compatibilité. L'origine d'un manifeste ne crée aucune confiance
cryptographique. FED authentifie et borne l'échange seulement ; CAP-01B
résoudra le registre et ses bindings.

Federation ne devient jamais propriétaire d'un module Master Profile. MP-01A
conserve contrat spécialisé, audience, fraîcheur, absence et mode fantôme. Une
enveloppe serveur peut porter un Faluss ID uniquement dans le contexte ci-dessus
et le retire avant rendu. Aucun transport n'autorise table directe ni copie
persistante ancienne de read-model.

## Portée vérifiable

`x-fed01a-scope` conserve historiquement les neuf artefacts de FED-01A. Le
correctif FED-01A.1 modifie exactement quatre fichiers : ce contrat, les deux
schémas et son test. Il ne modifie aucun autre document, fichier sous
`plugins/`, transport existant, runtime CAP-01B/EVT/MP, interface, migration,
option, clé, appel réseau, donnée WordPress réelle ou asset. Il ne fournit
aucune recette WordPress, puisqu'aucun runtime n'est installé.

## Implémentation FED-01B

FED-01B et EVT-01B.1 matérialisent ce contrat dans le plugin autonome
[`FALUSS_FEDERATION.md`](FALUSS_FEDERATION.md). Le runtime reste limité aux
quatre opérations de lecture fermées, au transport Ed25519, aux politiques
locales et aux quatre tables techniques dédiées. Le registre de catalogues EVT
est séparé des manifestes CAP et read-models ; aucun provider Hub/Me réel, clé
de production ou publication d'événement n'est livré. Les exigences normatives
du présent contrat prévalent sur toute future extension de provider.

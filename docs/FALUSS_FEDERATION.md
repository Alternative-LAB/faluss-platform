# Faluss Federation 0.3.0 — transport privé fermé

## Frontière

`plugins/faluss-federation/` matérialise FED-01A.1 sur un nœud WordPress approuvé. Il transporte exclusivement `diagnostic.read`, `manifest.read`, `read_model.read`, `event_catalog.read` et `event.publish`, par HTTPS serveur-à-serveur, Ed25519 et politique locale fermée. `event.publish` accepte uniquement une enveloppe `faluss.event` via l'adaptateur générique enregistré par Faluss Events. Federation n'est ni un RPC générique, ni un bus libre, ni un transport de paiement, claim, entitlement, profil ou média.

En version 0.3.0, `diagnostic.read` reste intégré et les quatre autres opérations conservent des registres séparés. Faluss Events 0.3.0 enregistre les trois callables distincts du publish : validateur de requête, receiver et validateur de réponse. L'absence ou le doublon de cet adaptateur ferme l'opération. Aucun catalogue, producteur, route ou consommateur Hub/Me/Analytics/Quêtes/Progression n'est livré.

## Préconditions et configuration locale

Le plugin demande WordPress 6.4 et PHP 7.4. L'extension PHP Sodium **native**, ses constantes Ed25519 et toutes les primitives requises, dont `sodium_memzero`, sont nécessaires pour rendre le transport opérationnel. Les fonctions homonymes fournies uniquement par `sodium_compat` ne satisfont jamais cette précondition. Sodium n'est cependant pas nécessaire pour activer le plugin : sans extension native valide, l'écran d'administration reste entièrement affichable, indique `Transport indisponible : Sodium absent ou invalide`, aucune route n'est enregistrée et toutes les façades échouent fermées.

Tout échec de nettoyage mémoire, qu'il prenne la forme d'une `SodiumException` ou d'une autre `Throwable`, est contenu. Les variables sensibles sont rendues inaccessibles après la tentative et l'auto-test, l'identité locale ou la signature concernée échoue fermée ; un nettoyage impossible ne peut donc pas être converti en succès.

Avant d'activer réellement le transport, définir exclusivement dans la configuration protégée du serveur, jamais dans Git ni dans la base :

```php
define( 'FALUSS_FEDERATION_LOCAL_NODE_ID', '...' );
define( 'FALUSS_FEDERATION_LOCAL_APP_KEY', '...' );
define( 'FALUSS_FEDERATION_LOCAL_ORIGIN', 'https://example.invalid' );
define( 'FALUSS_FEDERATION_LOCAL_KEY_ID', '...' );
define( 'FALUSS_FEDERATION_LOCAL_KEY_VALID_FROM', '2026-01-01T00:00:00Z' );
define( 'FALUSS_FEDERATION_LOCAL_KEY_VALID_UNTIL', '2027-01-01T00:00:00Z' );
define( 'FALUSS_FEDERATION_PRIVATE_SEED', 'base64url-canonique-de-43-caracteres' );
```

Le seed est exactement 32 octets en base64url canonique sans padding. Il sert uniquement à dériver ponctuellement la paire Ed25519 et est effacé en mémoire. Le plugin ne l'affiche, ne l'exporte, ne le journalise et ne le persiste jamais. L'origine doit être HTTPS canonique et identique à l'origine WordPress.

Les identités initiales attendues sont documentées, jamais déduites depuis le hostname :

| Site | node_id | app_key | origine |
| --- | --- | --- | --- |
| faluss.com | `hub-node` | `faluss-hub` | `https://faluss.com` |
| faluss.me | `me-node` | `faluss-me` | `https://faluss.me` |

Tout nœud futur définit ses propres constantes explicites.

## État et schéma

Le transport est `ready` seulement avec schéma 1 exact, extension Sodium native chargée, constantes et primitives Ed25519 requises présentes, auto-test valide, origine locale exacte, période de clé active, seed dérivable et au moins un pair exploitable. L'activation ne génère ni clé ni pair et n'appelle aucun domaine.

L'installation fraîche, sous verrou MariaDB borné, crée exclusivement quatre tables InnoDB préfixées WordPress :

| Table | Contenu et rétention |
| --- | --- |
| `faluss_federation_peers` | clés publiques et politiques locales exactes ; aucune clé privée |
| `faluss_federation_request_bindings` | liaison `(sender_node_id, request_id)` vers hash du corps ; au moins 15 minutes |
| `faluss_federation_nonces` | hash de nonce, clé émettrice, opération et consommation ; au moins 15 minutes |
| `faluss_federation_audit` | audit technique allowlisté ; maximum 30 jours |

Le schéma partiel ou divergent échoue fermé : aucune réparation automatique, aucun `dbDelta()`, aucune table existante modifiée. La purge est opportuniste, bornée et sans cron.

## Administration et pairage

Le seul écran est **Outils → Faluss Federation**, réservé à `manage_options`. Il utilise un nonce WordPress et POST pour chaque mutation, sans CSS ni JavaScript personnalisé. Il présente les métadonnées publiques locales, l'état du schéma/Sodium, les compteurs techniques et un bundle public copiable ; il ne présente jamais seed, clé secrète, nonce, signature, payload ou Faluss ID.

Échanger les bundles publics par un canal approuvé puis saisir le pair exact : nœud, application, origine HTTPS, `key_id`, clé publique, période, opérations, applications propriétaires, capacités et audiences. Les opérations et audiences sont des listes fermées et n'acceptent aucun wildcard. Créer une nouvelle clé active pour le même couple fait passer l'ancienne active à `rotating`; une seule clé de chaque état peut coexister. L'enregistrement exige la phrase exacte `ENREGISTRER LE PAIR FEDERATION`; la révocation exige `REVOQUER LA CLE FEDERATION`.

Un pair `active` ou `rotating` expose séparément sa politique actuelle et une action POST protégée par un nonce dédié. Cette action accepte exclusivement les listes `operations`, `owner_apps`, `capabilities` et `audiences`, exige `METTRE A JOUR LA POLITIQUE FEDERATION`, refuse toute clé supplémentaire et n'accepte aucun JSON libre. Les valeurs sont validées, dédupliquées par refus des doublons et triées avant stockage. Une révision SHA-256 opaque de la politique canonique protège contre les formulaires obsolètes. Sous transaction, la même ligne est verrouillée par son identifiant interne avec `SELECT … FOR UPDATE`; seules les quatre colonnes de politique et `updated_at` peuvent changer. Une politique identique retourne `unchanged` sans UPDATE ni audit. Une modification confirmée crée seulement l'audit `peer_policy_updated`. Identité, origine, clé publique, `key_id`, période et état du pair restent inchangés.

La mise à jour n'ajoute jamais `event.publish` aux politiques existantes. L'opérateur doit l'autoriser explicitement pour le pair concerné. Pour cette opération seulement, `capabilities` énumère exactement les capacités sources distantes publiables ; `owner_apps` et `audiences` ne participent pas à la décision. Le receiver exige que nœud, application et owner de la source correspondent au pair authentifié, que le destinataire soit local et que la capacité soit présente sans wildcard.

Le diagnostic distant est volontaire et utilise exclusivement l'origine du pair déjà enregistrée côté serveur. Il ne prend aucune URL du navigateur.

Pour un pair actif ou en rotation dont la politique autorise `manifest.read`, l'action d'administration **Tester le manifeste** est une lecture distante sans stockage. Le navigateur ne transmet que l'identifiant interne du pair. Le serveur relit son nœud, son application et son origine, puis fixe la version demandée à `1.0.0`. Après transport, signature et validation CAP complets, l'écran ne restitue que `app_key`, `manifest_version`, `product_state` et le nombre de capacités. Un échec reste générique.

## Transport

La route unique, disponible seulement lorsque le transport est prêt, est :

```text
POST /wp-json/faluss-federation/v1/exchange
```

Le receiver lit et hache les octets bruts une seule fois. Il récupère chacun des trois en-têtes cryptographiques uniques par `WP_REST_Request::get_header_as_array()` avec son nom HTTP public ; WordPress canonicalise ainsi la casse et traite tirets et underscores de manière identique. Une absence, plusieurs valeurs, une valeur ambiguë ou fusionnée par virgule reste refusée génériquement. Le receiver vérifie ensuite la forme JSON bornée, la clé/politique, la signature et la fraîcheur avant de consommer nonce et binding dans la même transaction InnoDB. Toute date d'enveloppe est émise et acceptée uniquement au format UTC canonique `Y-m-d\TH:i:s\Z`, sans fraction ni décalage. Un résultat provider doit contenir exactement `status`, `payload_contract`, `payload` et `error`; la présence est contrôlée indépendamment de la valeur afin de conserver les `null` contractuels, puis chaque branche reste validée selon son statut. Les refus pré-authentification restent génériques. Les réponses post-authentification sont sérialisées une fois, signées sur les octets servis, privées (`Cache-Control: private, no-store`) et limitées à 65 536 octets. Les plafonds JSON sont profondeur 16, 128 champs ou éléments et 4 096 octets par chaîne.

Les limites par clé/opération/minute restent 30 pour `diagnostic.read`, 60 pour `manifest.read` et 600 pour les autres opérations, avec une limite explicitement fixée à 600 pour `event.publish`. Avant la transaction, le receiver acquiert pendant au plus une seconde un verrou consultatif MariaDB propre au tuple émetteur, clé et opération. Son nom de 64 caractères dérive du préfixe WordPress et du tuple par SHA-256 tronqué, sans identifiant brut. Le verrou reste détenu jusqu'après commit ou rollback, puis sa libération doit être confirmée avant tout dispatch. Une requête plafonnée consomme toujours nonce et binding après commit confirmé ; une libération absente ou ambiguë échoue fermée. La purge opportuniste ne commence qu'après la sortie confirmée de la section verrouillée. Deux buckets distincts utilisent des verrous distincts.

La façade interne sortante expose aussi `event_publish()` ; seul le worker Events l'appelle avec le pair et l'événement relus depuis ses tables et son registre. La sélection du pair prépare séparément le nœud, l'application et les états `active` puis `rotating`; elle essaie d'abord les lignes actives exploitables, puis les lignes en rotation, et échoue fermée sur toute erreur SQL. Elle force HTTPS, `sslverify`, zéro redirection, une durée totale effective de trois secondes et `limit_response_size` à 65 536 octets avec les seuls arguments supportés par l'API HTTP WordPress. Elle contrôle ensuite la taille reçue, l'identité, la liaison requête/réponse, les en-têtes, hash, signature, fraîcheur, statut et validateur spécialisé avant de rendre une réponse. L'accusé `faluss.event-acceptance` 1.0.0 doit lier exactement l'ID et le SHA-256 canonique de l'événement, avec `accepted` ou `existing`.

L'audit d'un publish omet `request_id` et ne contient ni event ID, référence source, enveloppe, payload, URL, IP, User-Agent, signature, nonce, clé, e-mail ou paiement. Il conserve seulement les nœuds, l'opération, la capacité si sa largeur est compatible, le résultat technique, la durée et l'horodatage technique de la table existante.

## Trace locale temporaire

La trace opérateur est désactivée par défaut et ne s'active que si `FALUSS_FEDERATION_DIAGNOSTIC_TRACE` vaut strictement `true` dans la configuration locale protégée. Elle écrit exclusivement avec `error_log()` des lignes de forme `[Faluss Federation trace] side=server stage=server_headers` ou `[Faluss Federation trace] side=client stage=client_http_4xx`. Le côté et le stage appartiennent à des listes fermées ; aucune URL, domaine, enveloppe, payload, en-tête, signature, hash, nonce, `request_id`, seed, clé, identité, IP, erreur réseau détaillée ou donnée WordPress n'est ajouté.

Cette trace n'altère aucune validation, canonicalisation, politique, réponse HTTP ni décision d'acceptation. Elle n'écrit dans aucune réponse, administration, table, option ou transient et ne crée aucun audit pré-authentification. Retirer la constante immédiatement après le diagnostic restaure le silence complet.

## Recette WordPress à exécuter ultérieurement

Sauvegarder les deux sites, mettre à jour Faluss Federation 0.3.0 puis Faluss Events 0.3.0 avec les mêmes ZIP sur `faluss.com` et `faluss.me`, et vérifier les deux schémas 1 et l'état `ready`. Ne modifier aucune clé, pair, origine ou période. Vérifier les diagnostics et manifestes existants. Ajouter `event.publish` uniquement à la politique du pair prévu pour le futur test EVT, avec sa capacité exacte ; ne jamais ajouter de wildcard. Vérifier les deux tâches Cron Events uniques. Aucun événement réel ne doit être produit tant qu'AN-01 n'a pas livré ses enregistrements métier.

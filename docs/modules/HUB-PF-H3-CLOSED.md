# Hub ↔ Fans — H3 fermé

## Accord et limites

Le 5 octobre 2026, **ALB-Origine** valide D3 de R1 pour H3 fermé uniquement :
reçus privés signés/versionnés, permissions PF dédiées et délégation explicite
Fan/Créateur. [Accord humain rapporté dans #147](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-5992165128).
Identités, achats, pairs et clés sont fictifs sur deux WordPress/MariaDB jetables.
H4, F1, réduction après remboursement #149 et conservation #150 restent ouverts.
Aucune autorité d'achat réelle, paire de production, migration automatique,
activation, score, paiement, déploiement ou flag de production.

H3a livre les DTO/codecs publics de `TokenEngine/PurchasedPf/Protocol`, sans hook,
route ou persistance. H3b raccordera le ledger H2, les reçus, la persistance
anti-rejeu et une recette HTTP impossible à charger en configuration ordinaire.
Ces DTO sont le contrat étroit de lecture côté Fans : aucun accès aux tables Hub.

## Formats H3a

- Contrat `fans.hub-purchased-pf/0.2.0` ; types/domaines séparés receipt, context,
  request et response sous `faluss.hub-purchased-pf.* /1` (sans espace).
- JSON canonique [JCS RFC 8785](https://www.rfc-editor.org/rfc/rfc8785) **restreint**
  aux clés ASCII et valeurs textuelles ASCII, booléens, null, objets/listes.
  Quantités/révisions en chaînes décimales exactes ; nombres JSON et Unicode
  refusés. Aucune donnée éditoriale n'entre dans ces preuves. Tri ASCII récursif,
  sans espaces/échappements alternatifs ; octets non canoniques et doublons refusés.
  Ce n'est pas un sérialiseur JCS général. Federation garde ses octets bruts.
- Signature [Ed25519 RFC 8032](https://www.rfc-editor.org/rfc/rfc8032) par les
  méthodes publiques existantes `Faluss_Federation_Crypto::sign/verify`, via
  `FederationBridge`. Sodium natif requis ; aucune dépendance nouvelle. Le
  dispatcher, les cinq opérations, limites et contrats historiques ne changent pas.
- Enveloppe exacte : `payload_base64url`, `key_id`, `payload_sha256`,
  `signature_base64url`. Pas de clé publique venant du payload. Le domaine,
  `kid:<key_id>` et `sha256:<digest minuscule>` sont signés, séparés par LF,
  sans LF final. Key ID 1–64 caractères ASCII ; clé locale de pair explicite,
  active et dans sa période au moment de vérification. Inconnue/révoquée : refus.
- Reçu canonique ≤32 KiB ; payload de transport ≤48 KiB, enveloppe HTTP ≤64 KiB.
  L'enveloppe transporte le digest des octets canoniques, jamais une URL.

## Délégation et permissions

Permissions distinctes `pf.reserve`, `pf.confirm`, `pf.release`, `pf.lookup` et
`pf.context.delegate`, jamais déduites de `wallet.read`, `reward.claim`,
`read_model.read` ou `event.publish`. Pairs `fixture.*` uniquement en H3 fermé.

Le contexte contient contrat/type, émetteur/audience exacts, opération, nonce,
clé d'idempotence hachée, opération recherchée, intention immuable et dates UTC.
TTL ≤60 s ; expiration exclue, tolérance d'horloge future ≤5 s. Le contexte est
signé dans un domaine distinct et lié à la requête. Client de l'intention =
délégataire admis ; auto-attribution et UUID invalides refusés.
Le serveur Fans doit résoudre le membre depuis une session/lien vérifiés et le
créateur depuis son propriétaire actif ; une signature n'atteste pas, seule,
le véritable SSO Me. Cette résolution sera exercée avec des liaisons fictives
dans la recette H3b, sans réutiliser de code SSO ni fournir un UUID arbitraire.

## Reçu privé historique

Version/révision, émetteur/audience, consommation/réserve/attribution, identités,
quantité, politique, référence de débit et date de confirmation. Allocations
triées par `allocation_id`, chacune liée à lot/preuve/révision et référence
d'achat propriétaire. L'identifiant stable de l'allocation est le SHA-256 ASCII
de `attribution_id|lot_id` ; pas un nouvel identifiant d'achat ni une seconde
allocation économique. Lot unique, 1–32 allocations, somme exacte et bornée.

Le reçu prouve un fait passé ; il ne prouve **pas** l'état courant après litige,
remboursement ou correction H4. Aucune projection HoF/PC, inbox de score ou
réconciliation économique n'est créée par H3a.

## Scénarios écrits avant code

Positifs : vecteurs ASCII canoniques, signature native et domaine distinct,
reçu exact, contexte de 60 s et permissions explicites. Négatifs : clés et
champs inconnus, encodage/duplicate/float/Unicode, signature/digest/payload
altérés, audience/émetteur/opération/nonce/clé différents, contexte expiré ou
futur, auto-attribution, identité invalide, allocations incohérentes et
permissions historiques seules. H3b ajoutera concurrence/anti-rejeu SQL,
réponses HTTP perdues après COMMIT et lookup sans second débit.

Rollback : revert du lot ; aucun chargement normal ou changement de données
réelles. Aucun secret, reçu privé, identifiant de personne ou payload dans les
logs/rapports. La recette isolée ne vaut ni admission réseau de production,
ni validation TLS/SSO réel, ni autorisation d'achat ou de score.

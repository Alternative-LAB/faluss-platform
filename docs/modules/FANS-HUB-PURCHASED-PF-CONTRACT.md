# Fans ↔ Hub : PF achetés et réconciliation

## Statut et autorité

- Contrat de production **proposé, non ratifié intégralement par Hub** :
  `fans.hub-purchased-pf/0.1.0`. Les accords fermés H1/H2/H3 sont distincts ;
  aucune capacité économique n'est ouverte sur les sites.
- Base examinée : `634eee2a8556f4b8458822641a03114e88334c3e`.
- Référence produit : [ADR 0018](../adr/0018-fans-pf-pc-hof-v3.md).
- Ce document est soumis au propriétaire Hub ; **sa validation n’est pas obtenue**.
  Les opérations ci-dessous sont des exigences et noms logiques proposés, pas des
  endpoints, permissions ou capacités opérationnelles. Aucune URL n’est publiée.
- Le 5 octobre 2026, le porteur du projet désigne **ALB-Origine** comme responsable
  habilité Hub / Token Engine. Les décisions précises et l'accord d'implémentation
  sont soumis dans [#147](https://github.com/Alternative-LAB/faluss-platform/issues/147).
  La [recette H0 du module actuel](../../tests/TokenEngine/recipe/README.md)
  sur WordPress/MariaDB Hub jetable avec données fictives est autorisée.
- Accord ultérieur du 5 octobre : **D1/D2/D4 seulement pour le modèle fermé H1**,
  décrit dans [HUB-PF-H1-MODEL.md](HUB-PF-H1-MODEL.md). Validateurs, schéma additif
  et persistance de recette ne ratifient pas les opérations économiques de ce
  document ni la proposition R1 complète de #147. Les accords D6/H2 et D3/H3
  limités à la recette fermée sont consignés ci-dessous ; D5/H4 et F1 restent proposés.
  L'ordre de réduction après remboursement et la conservation de 24 mois ne sont
  pas approuvés : décisions séparées [#149](https://github.com/Alternative-LAB/faluss-platform/issues/149)
  et [#150](https://github.com/Alternative-LAB/faluss-platform/issues/150) avant H4/ouverture.
- Aucun producteur d'achat réel, crédit/débit nouveau sur site, API/transport installé sur site,
  ledger parallèle Fans, paiement, score persistant, migration automatique sur
  un site réel, UI, flag de production ou activation. Les anciens claims restent inchangés.

## Capacités réellement disponibles

Accord limité supplémentaire : **D3 pour H3 fermé seulement**, décrit dans
[HUB-PF-H3-CLOSED.md](HUB-PF-H3-CLOSED.md). Cet accord permet les reçus et une
recette de transport/délégation Hub ↔ Fans jetable, pas une admission de production,
un achat réel ou la ratification de H4/F1, #149 et #150.
Le wire de cette recette utilise `fans.hub-purchased-pf/0.2.0`, distinct du draft
de production ci-dessous. Reçus atomiques, nonces SQL, inbox privée et HTTP ont
leurs tests ; aucun ledger Fans, score, migration automatique ou route normale.

Accord limité ultérieur : [H2 fermé](HUB-PF-H2-CLOSED.md) et garanties D6 nécessaires
(transaction, journal, lookup primaire et clé stable) sont autorisés dans la seule
recette Hub jetable. Cet accord H2 seul ne ratifiait ni D3, ni les remboursements/rétention, ni ce
protocole réseau. Aucun producteur réel ou consommation utilisable sur les sites.

| Surface relue | Disponible | Manquant pour ce contrat |
| --- | --- | --- |
| [TokenEngineContract](../../src/TokenEngine/TokenEngineContract.php) | `hubDailyStatus`, `claimHubDaily` | Tout le protocole acheté/réservé/consommé décrit ici |
| [Service PF propriétaire](../../src/TokenEngine/Legacy/includes/class-token-engine-points-service.php) | Classes `funded`, `earned`, `promotional`, claims historiques et compensation interne | `pf_pack_purchase`, `pf_pack_bonus`, `fans_support`, `cosmetic_redemption`, `manual_adjustment` refusés avec `pf_feature_not_enabled` |
| [Token Engine](TOKEN-ENGINE.md) | Ledger PF append-only distinct d’ALB, idempotence et verrous internes | Preuve d’achat par lot, filiation lot→fan→tranche→attribution, réservation et reçu Fans |
| Compensation PF | Montant intégral, même classe, une compensation par original | Compensations partielles successives, allocation des corrections et reprise distribuée |
| Connector historique | Permissions `wallet.read`, `reward.claim`, `entitlements.read` | Aucune de ces permissions ne doit devenir implicitement un droit de consommation PF Fans |

Préserver `20 PF earned` Hub et `75 PF earned` Me, leurs preuves et comportements.
Aucun renommage PC, conversion de classe ou reprise des anciens soldes. Un solde
`funded` ne prouve pas à lui seul l’achat et l’allocation nécessaires au nouveau HoF.

### Précisions issues de la revue du code propriétaire

Voir la [revue ciblée Hub](../audits/2026-09-28-hub-pf-contract-review.md) pour les
preuves et la validation encore attendue. Le propriétaire de domaine est Hub /
Token Engine. L’approbation de son responsable sera requise avant l’implémentation
du protocole, pas avant la fusion de cette proposition documentaire. Aucun compte
ou équipe n’est désigné artificiellement ; cette revue ne vaut pas ratification.

L’idempotence existante retrouve une écriture par clé **sans comparer le payload**.
La détection de conflit par empreinte proposée ci-dessous est donc une capacité
nouvelle, pas un acquis du ledger actuel. Les verrous sujet/classe et claims ne
constituent pas un protocole de réservation par lot. Le schéma ne garantit aucun
rattachement lot/tranche/attribution. Un échec de COMMIT n’est pas une preuve
d’absence de consommation : état inconnu et rapprochement propriétaire nécessaires.
La compensation standard peut aussi refuser une restitution faute de solde de
classe ; elle ne prouve pas un remboursement total de pack déjà consommé.

Les preuves serveur booléennes des claims sont internes à leurs adaptateurs ;
elles ne sont ni des attestations d’achat réseau ni des signatures réutilisables.
Le faux serveur ne valide aucune de ces capacités sur Hub réel. L’approbation
documentaire attendue ne vaudra pas autorisation d’implémentation ou d’activation.

## Responsabilités proposées à valider par Hub

| Autorité | Responsabilité exclusive |
| --- | --- |
| Preuve économique d’achat (producteur à désigner) | Confirmation effective de l’achat et états de contestation/remboursement ; jamais un retour navigateur |
| Hub / Token Engine | Validation de cette preuve ; lot, titulaire Faluss, quantité achetée, tranches, réservation, consommation unique, état économique, révisions et attestations |
| Fans | Identité du fan et propriétaire réel du profil créateur via les contrats autorisés ; intention canonique d’attribution ; références de reçus et traitements, jamais balance PF |
| Future projection HoF | Consommer le fait attesté une fois par projection admissible ; 1 PF acheté/attesté/attribué = 1 point ; politiques de classement encore fermées |

Hub doit approuver schémas, garanties atomiques, autorités économiques, durée des
réservations, rétention d’idempotence, crypto/rotation, états, pagination cohérente
et capacité de correction avant version stable. Il ne s’agit pas d’une autorisation
de changer son moteur. Une capacité propriétaire manquante reste bloquante.

## Filiation et preuve d’achat proposées

Lot privé : `lot_id`, référence immuable de preuve d’achat et son autorité, révision,
état, date, quantité PF achetée entière, `member_faluss_id`, provenance `purchased`,
classe `funded`, version de politique. Un lot appartient à un fan résolu côté serveur.
Classes `earned`/`promotional`, cadeaux gratuits, bonus et paiements EUR directs
ne sont pas admis. Les montants d’achat restent chez l’autorité économique ; aucune
équivalence EUR ni revenu ne figure dans un reçu de score ou une API créateur.

Allocation explicite par tranche : `allocation_id`, lot, quantité et attribution.
Invariant : quantité réservée non expirée + quantité consommée ne dépasse jamais
la quantité achetée éligible du lot. Correction et disponibilité exigent la politique
propriétaire ; ne pas ajouter des PF disponibles au seul vu d’une correction de score.
Allocation multi-lots : ventilation fournie par Hub, jamais reconstruite depuis
un prix moyen ou le score ; politique de choix des lots encore à approuver.

La référence métier Fans `attribution_id` lie définitivement fan, créateur réel et
quantité. Le profil public n’est pas un Faluss ID. Refus de l’auto-attribution et
des identités absentes/forgées ; cette vérification ne peut pas se limiter au SSO
du navigateur. Aucune consommation au nom d’un fan arbitraire fourni par client.

## Opérations logiques proposées (toutes indisponibles en production)

| Opération | Précondition / effet attendu |
| --- | --- |
| `reserve` | Requête serveur authentifiée, intention et clé d’idempotence, preuve du sujet ; Hub choisit/alloue les tranches et réserve atomiquement. Aucun score, PC ou reçu définitif. |
| `confirm` | Même attribution et réservation encore valide ; vérification propriétaire sous verrou du lot. Une consommation commise et un reçu unique, en transaction avec la trace/outbox propriétaire. |
| `lookup` | Lecture privée par clé/intention dans le périmètre du client ; état propriétaire et reçu déjà commis. Aucun effet économique. |
| `release` | Libération idempotente d’une réservation non consommée. Un reçu confirmé interdit cette opération ; passer par le protocole de correction propriétaire. |
| `lot-attributions` | Snapshot complet attesté du rattachement des consommations à un lot, pagination bornée et révision cohérente. Pas de lecture SQL Fans. |
| `correction-snapshot` | Lecture/rejeu des corrections émises par l’autorité Hub après preuve économique ; Fans ne peut pas déclarer un remboursement. |

États proposés : `reserved → confirmed`, `reserved → expired/released`. Confirmer
une réservation expirée/libérée échoue. La course confirmation/expiration/libération
doit avoir un seul ordre de commit atomique Hub. Un timeout ou client déconnecté
ne signifie pas libération ; l’horloge et le TTL viennent du serveur propriétaire.
TTL de 30 secondes dans le faux serveur = constante de test, pas décision produit.

Idempotence : portée `(client, version majeure, opération, clé)`, empreinte canonique
des champs immuables. Rejeu identique retrouve l’état/résultat commis ; changement
de payload = conflit, jamais nouvelle consommation. En plus, unicité métier de
`attribution_id` et de la tranche/reçu : une nouvelle clé ne redébite pas la même
attribution. Confirmation idempotente par réservation/intention. Les tombstones
doivent survivre aux reprises et à la durée des litiges ; durée à décider.

Échecs logiques fermés : mauvais pair/version/audience, sujet ou achat non prouvé,
classe non admissible, conflit, quantité insuffisante, réservation fermée, indisponibilité,
révision périmée et rattachement incomplet. Aucun code HTTP opérationnel attribué ici.
Backoff borné sur contention/indisponibilité ; pas de retry automatique d’un conflit.

## Reçu authentifié et anti-rejeu — exigences non implémentées

Reçu : version du contrat/politique, émetteur Hub, audience Fans, identifiant unique,
attribution, réservation, références des tranches/lots et preuves d’achat, identités
canoniques privées, quantité achetée effectivement consommée, état, révision et dates.
Reçu original immuable ; les corrections le référencent, sans l’écraser. Une attribution
peut alimenter plusieurs classements sans confirmer une nouvelle consommation.

Transport authentifié serveur-à-serveur à admettre explicitement via les contrats
existants, TLS et autorisations étroites. Enveloppe signée, encodage canonique,
identifiant/rotation/révocation des clés, émetteur/audience/version exacts et liaison
à la requête ; algorithme et politique de fraîcheur à valider avec Hub avant
implémentation. Échec de signature, clé inconnue/révoquée ou révision régressive :
traitement fermé, quarantaine opérateur. Répéter une signature valide n’ajoute pas
un score : inbox future unique `(autorité, référence, révision)` et comparaison du
contenu. Le reçu prouve une consommation passée ; son actualité doit être obtenue
par l’état/corrections Hub, pas par un ancien reçu rejoué après remboursement.

Aucun pseudo-secret, email ou token dans les URL/logs. Les références et identités
privées restent dans les services autorisés, sans API wallet/revenu créateur.
Le score public peut permettre une estimation économique indirecte.

## Reprises après timeout et panne

1. Fans conserve son intention et sa clé avant l’appel (persistance future, pas ici).
2. Perte de réponse à `reserve` : `lookup` puis rejeu de **la même clé et payload**.
   Pas de nouvel achat ni d’attribution de secours. Absence non autoritative ou
   lookup indisponible : rester `unknown`, traitement fermé.
3. Perte de réponse à `confirm` : retrouver le reçu par l’intention ; ne jamais
   interpréter le timeout comme absence de débit. Retry retrouve le même reçu.
4. Consommation commise mais livraison événement échouée : outbox propriétaire
   durable puis inbox Fans idempotente ; reconciliation par snapshot. Pas de double
   confirmation pour chaque classement ni de seconde dépense lors d’une reprise.
5. Lot mis en litige pendant confirmation : Hub sérialise l’état du lot et la
   consommation ; si consommation déjà commise, inclure cette attribution dans
   le snapshot de corrections, sinon refuser/libérer la réservation.

## Plan de réconciliation des lots

### Détection et snapshot

Déclencheurs proposés : preuve propriétaire de litige/remboursement, trou de révision,
timeout durable et tâche opérateur de rapprochement. Aucun cron n’est ajouté.
Hub fige une révision de lot, bloque les nouvelles réservations inadmissibles et
fournit toutes les tranches consommées, leurs reçus/attributions et états. La
pagination doit garder la même révision, un curseur opaque, total et digest de
complétude ; page absente, doublon contradictoire, lien manquant ou révision mouvante
rend le rapprochement incomplet. Fans ne reconstruit pas les liens depuis une somme.

### Correction et convergence

Après complétude prouvée, Hub produit des corrections attestées pour chaque
attribution affectée : référence originale, révision monotone, lot et preuve
économique source, motif, quantité annulée cumulative et état. Litige : contribution
concernée non comptabilisable ; résolution attestée plus récente restaure seulement
le net valide. Un ancien événement ne restaure pas la contribution. Remboursement
total terminal ne se transforme pas en résolution confirmée. Corriger toutes les
projections de la même attribution, y compris sessions clôturées, sans seconde
compensation PF et sans choisir une politique de victoire/titre/suspension.

Les corrections partielles exigent une ventilation exacte par tranche et attribution,
sans prorata EUR→points inventé. **Ce parcours reste bloqué** : manque Hub d’un
protocole de compensation partielle cumulée, bornée, répétable et réconciliable.
Même une correction totale attestée est ici une exigence future, pas une nouvelle
API économique. Pas de remboursement effectué par un calcul Fans.

### Rattachement incomplet / traitement opérateur

État `review_required` : suspendre le traitement automatique et les nouvelles
opérations du lot, alerter l’autorité Hub ; conserver preuves, dernière révision
complète et références manquantes. Ne pas publier de corrections calculées au jugé,
ne pas déclarer le rapprochement réussi, ne pas restituer des PF par ledger parallèle.
Si une attribution concernée n’est pas identifiable, la politique conservatoire
sur les projections doit être définie avant leur activation ; ne pas inventer un
retrait global de points. Reprise seulement sur snapshot complet attesté, idempotent.

Critère de clôture : toutes les attributions du snapshot ont une correction/revision
prise en compte, aucun reçu orphelin, sommes de quantités cohérentes par lot/tranche,
accusés des consommateurs et revue des écarts. Montants économiques vérifiés chez
leur propriétaire, jamais déduits du score. Conserver les traces et tombstones ;
rétention, procédures opérateur et SLA à approuver avant ouverture.

## Faux serveur et limites des preuves

Les [tests](../../tests/Fans/PfContract/PurchasedPfContractTest.php) interrogent un
[faux Hub en mémoire](../../tests/Fans/PfContract/FakeHub.php), uniquement sous `tests/`.
Lot synthétique préchargé, identités courtes fictives, une seule autorité/client,
pas de réseau HTTP, WordPress, MariaDB ou Token Engine réel. `correctLot` est un
contrôle propriétaire de fixture, pas une commande de remboursement accessible à Fans.

Ils vérifient refus, shape fermée, rejeux, conflit clé/intention, timeout avant/après
commit, expiry/libération, altération de reçu, rattachement de deux attributions,
litige/résolution/révisions tardives et blocage partiel ou lien manquant. Deux Fibers
entrelacent les réservations autour d’un verrou en mémoire ; les confirmations sont
sérialisées dans le modèle. **Ce n’est pas une preuve de concurrence InnoDB, multi-processus,
de crash durable ou de linéarisation d’un vrai serveur.** Le test de façade confirme
que seules les deux méthodes quotidiennes sont réellement disponibles.

HMAC avec clé publique de test détecte les altérations du modèle ; ce n’est ni le
signataire proposé de production, ni une validation de TLS, PKI, rotation, révocation
ou authentification réelle. Pagination, multi-lots, outbox/inbox durables, résolveur
d’identité, garanties de rétention et workflow opérateur ne sont pas implémentés ni
prouvés. Le batch de correction de test n’est pas le format final signé par Hub.

## Portes et lot propriétaire requis

1. Revue/accord explicite du propriétaire Hub sur ce draft ; désigner l’autorité
   de preuve d’achat et approuver allocation, TTL, anti-rejeu, états et rétention.
2. Lot Hub séparé et autorisé : capacités manquantes, protocole signé et transactionnel,
   preuve de conservation des claims historiques. Aucun changement de ce type ici.
3. Recettes réelles Hub et inter-applications : concurrence, panne après commit,
   restauration, multi-lots, pages manquantes, rotation/révocation et compensation.
4. Contrat de correction partielle et policies HoF/PC/Shop toujours fermés tant que
   leurs arbitrages et capacités ne sont pas validés. Achat, attribution, remboursement
   et événements dérivés produisent toujours zéro PC ; pack seul zéro score.

Rollback de ce lot : retirer docs et faux serveur/tests ; aucun schéma, donnée,
consommateur ou config à restaurer. Aucune activation autorisée par le passage des tests.

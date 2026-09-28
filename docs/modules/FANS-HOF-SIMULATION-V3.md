# Fans HoF — simulateur hors runtime v3

## Contrat et statut

`fans.hof-simulation/3.0.0`, classe pure `Progression\HofSimulationV3`, applique
sur fixtures les règles de l’[ADR 0018](../adr/0018-fans-pf-pc-hof-v3.md).
La [v2 historique](PROGRESSION-SIMULATION.md), sa classe et ses tests sont conservés
sans modification, recalcul ou migration. Aucun appelant runtime, hook, route,
ledger, flag, paiement, session active, badge ou activation n’est ajouté.

**1 PF acheté, attesté et effectivement attribué = 1 point HoF.** Pack seul : zéro.
Unité de sortie : points entiers, pas les centièmes historiques v2. Aucune conversion
EUR, donnée de revenu ou équivalent monétaire créateur. Le score public peut
néanmoins permettre une estimation économique indirecte.

## Entrée fermée `project(facts, projections, derivedEvents = [])`

Chaque argument est une liste de 0 à 1 000 éléments. Tous les objets refusent les
champs inconnus, notamment montants, revenus, crédits PC, gagnant ou suspension.
Les limites sont des bornes techniques de calcul, pas des barèmes produit.

| Champ du fait | Valeur et rôle |
| --- | --- |
| `version` | Exactement `3.0.0` ; aucune conversion v2. |
| `reference` | UUID v4 canonique minuscule du fait. Autorité unique fictive dans ce batch. |
| `revision` | Entier 1 à 1 000 000 ; révision cumulative du même fait. |
| `member` | UUID v4 fictif de l’identité du fan. |
| `creator` | UUID v4 fictif de l’identité créateur pour attribution, null pour pack. |
| `source` | `purchased_pf_pack` ou `purchased_pf_allocation` exclusivement. |
| `economic_class` | `funded` exclusivement ; insuffisant sans les références ci-dessous. |
| `purchase_attestation` | UUID v4 fictif d’attestation d’achat, obligatoire. |
| `consumption_receipt` | UUID v4 fictif de tranche consommée, obligatoire pour attribution, null pour pack. |
| `pf` | Quantité entière 1 à 1 000 000. |
| `cancelled_pf` | Quantité cumulative annulée, entre 0 et `pf`. |
| `status` | `pending`, `failed`, `confirmed`, `disputed` ou `refunded`. `refunded` exige annulation totale. |

`direct_eur_support`, `free_gift`, classes `earned`/`promotional`, preuve manquante
et auto-attribution sont rejetés par `InvalidArgumentException`, sans résultat
partiel. La comparaison des identités fictives ne résout pas de vrais Faluss IDs :
le futur moteur devra comparer les identités réelles via leurs propriétaires,
pas les UUID publics de profils, e-mails ou comptes WP de domaines différents.

Les UUID d’attestation et de reçu sont **des descriptions de fixtures, pas des
preuves authentifiées**. Le calcul ne vérifie ni signature, achat réel, provenance,
provision, lot ni consommation effective. Aucun booléen navigateur ne vaut preuve.
La présence du pack dans le batch n’est pas nécessaire et ne prouve pas l’allocation.
Un même reçu ne peut être utilisé par deux références d’attribution, même en litige.

Une projection a exactement `reference`, `session`, `dimension` (UUID v4 fictifs),
`closed` (booléen) et `facts` (liste de 0 à 1 000 références présentes dans le batch).
Ces sélections sont fournies par la fixture : **aucune règle d’éligibilité de session,
territoire, victoire, suspension ou titre n’est déduite**. Une référence répétée
dans une projection compte une fois. Répéter une projection identique est sans effet ;
réutiliser son identifiant avec une définition différente est refusé. Des projections
distinctes peuvent sélectionner la même attribution : leur score n’est pas à sommer
pour déterminer une consommation PF.

Les événements dérivés ont exactement `reference` (UUID v4), `fact_reference`
(fait existant) et `kind` parmi `badge_notice`, `ranking_notice`, `refund_notice`.
Ce sont des notifications fictives sans effet. Répétition identique sans effet,
référence conflictuelle refusée. Ils ne créent ni score supplémentaire ni PC.
Un événement inconnu ou une tentative d’injecter `pc_delta` est rejeté.

## Révisions et corrections

Même référence/révision et contenu canonique identique = rejeu sans effet, quel
que soit l’ordre des champs ; contenu différent = conflit. Les révisions sont
triées et la plus récente est retenue indépendamment de l’arrivée. Seuls révision,
statut et quantité annulée changent ; les autres champs sont immuables. L’annulation
cumulative ne peut diminuer entre révisions fournies. Sans historique fourni,
le calcul ne peut pas détecter une régression par rapport à un état externe.

Pour une attribution confirmée : points = `pf - cancelled_pf`. Tous les autres
statuts donnent zéro. Exemple : 300 attribués, puis 100 annulés donnent 200 points
dans chaque projection ; annulation totale donne zéro. Un litige donne zéro ;
une résolution confirmée de révision supérieure restaure seulement le net valide.
Une ancienne confirmation arrivée ensuite ne restaure rien. Une annulation totale
reste à zéro même après résolution. Les révisions utilisées sont retournées pour
inspection, sans journal durable ni authentification de décision.

Les mêmes corrections s’appliquent aux projections `closed=true` : le classement
fictif d’origine est recalculé ; aucune contribution n’est transférée vers une
nouvelle session. Ce mécanisme ne définit pas une politique de clôture ou le sort
des titres, toujours ouverts et hors simulateur.

**Les corrections partielles sont exclusivement des fixtures de calcul.** Le
Token Engine garde sa compensation standard intégrale et unique par écriture ;
ce lot ne prouve ni n’ajoute de remboursement partiel. Une correction de pack
ne propage pas automatiquement une correction aux attributions : celles-ci doivent
être fournies, déjà allouées, sans inventer de proportion EUR/PF. Le simulateur
n’assure pas la réconciliation pack/tranches ni la cohérence économique d’un batch.

## Sortie fermée

- `simulation=true`, `policy=fans.hof-simulation/3.0.0`.
- `creator_points[projection_reference][creator]` : points entiers. Un pack seul
  ne crée pas d’entrée créateur ; une attribution non confirmée donne zéro.
- `pc_delta=0` : achat, attribution, remboursement, litige, résolution et événements
  dérivés ne créditent jamais de PC. Ce champ n’est pas un solde ou moteur PC.
- `fixture_receipt_count` : nombre de références uniques de reçus dans les faits
  canoniques, y compris ensuite corrigés ou contestés. C’est un diagnostic de fixture,
  **pas un débit exécuté ni un solde net** ; ajouter des projections ne le modifie pas.
- `revisions[reference]` : dernières révisions utilisées. Les maps sont triées
  pour rendre les résultats indépendants de l’ordre d’entrée.

Aucune API créateur n’est créée. Ces diagnostics privés de tests ne doivent pas
être exposés tels quels. Aucun montant EUR, wallet ou revenu créateur dans la sortie.

## Preuves et limites

Les tests v3 couvrent pack zéro, ratio unitaire, multi-projections sans reçu dupliqué,
delta PC nul et notifications dérivées, refus des sources/classes non admissibles,
preuves manquantes, auto-attribution, rejeux, conflits, régressions de corrections,
corrections partielles/totales, litige, résolution tardive et session clôturée.
Les tests v2 continuent de caractériser le modèle historique.

Calcul uniquement en mémoire : aucune recette WordPress/MariaDB, concurrence SQL,
livraison Events, protocole PF réel, paiement ou preuve de sécurité du transport.
Politiques de classement et suspensions, protocole PF, titres, propriétaire/barèmes
PC, claims historiques, vendeur Shop et remboursements partiels restent fermés.
Aucune modification Token Engine ni migration. Retour arrière : retirer uniquement
la classe v3 et ses tests/docs ; v2 et données existantes restent intacts.

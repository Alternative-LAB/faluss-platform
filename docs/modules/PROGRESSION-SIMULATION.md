# Progression / HoF — simulation hors runtime v2

## Implémenté et frontière

`Progression\SupportSimulation` est un calcul pur sur fixtures fictives. La politique
`fans.support-simulation/2.0.0` remplace v1, qui est rejetée sans conversion implicite.
Aucun module, hook, route, paiement, ledger, session HoF active, score persistant,
badge ou revenu n'est créé. Il ne faut pas brancher ce calcul sur des données client :
`owner`, `funding` et la référence d'allocation sont des descriptions de fixture,
**pas des preuves authentifiées**. Aucun champ `verified=true` n'est accepté.

Pour `funded_coin_gift` et `direct_eur_support`, des UUID `member` et `creator`
identiques font rejeter le lot avec `InvalidArgumentException` (auto-soutien),
avant toute projection, quel que soit l'état du fait. Dans les fixtures, ces
champs désignent deux identités fictives comparables. Cette égalité ne remplace
pas la vérification de l'identité Faluss réelle par le futur moteur Fans : il
devra résoudre côté serveur le propriétaire du profil créateur et le donateur
via les contrats autorisés, comparer leurs `faluss_id` canoniques et refuser en
l'absence de preuve. L'UUID public du profil créateur est distinct du `faluss_id`
et du `wp_user_id` ; comparer directement ces identifiants de domaines différents
ne détecterait pas l'auto-soutien. Ni e-mail, ni pseudo, ni claim client ne suffisent.
Cette règle n'ajoute aucune exposition publique des identités SSO.

## Unités et sources distinctes

Les sorties privées sont `creator_score_centipoints` par créateur (centièmes de
point entiers) et `donor_consumed_eur_cents` par donateur (centimes EUR entiers).
Aucune sortie revenu : un score ne permet jamais de déduire un revenu créateur.

| Source v2 | Fixture admissible | Score | Dépense consommée |
| --- | --- | --- | --- |
| `pack_purchase` | `funding=donor`, classe `funded`, quantité et prix du pack | 0 | 0, même payé ; progression seulement à la consommation |
| `funded_coin_gift` | `funding=donor`, classe `funded`, pièces débitées et allocation EUR distincte | pièces nettes × 100 | allocation EUR nette fournie par la fixture |
| `direct_eur_support` | `funding=donor`, classe `none`, zéro pièce | centimes EUR nets = centièmes de point | centimes EUR nets |
| `free_gift` | `funding=none`, classe `none`, `earned` ou `promotional`, zéro EUR | 0 | 0 |

Exemples : 300 pièces et une allocation fictive de 725 centimes donnent **30 000
centièmes de point et 725 centimes de dépense**. 725 n'est ni un tarif ni un taux :
une fixture à 999 centimes produit le même score et une dépense de 999. Un soutien
de 1,25 EUR donne **125 centièmes de point et 125 centimes**. Pack puis cadeau ne
comptent la dépense qu'une fois. Aucun flottant, taux PF/EUR, commission ou assiette
fiscale n'est calculé. Une catégorie adulte ou un état non confirmé produit zéro.

## Forme fermée des fixtures

Chaque instantané contient exactement : `version=2.0.0`, `owner=faluss-fans`,
`reference`, `revision`, `member`, `creator`, `category`, `source`, `economic_class`,
`funding`, `allocation_reference`, `coins`, `amount_cents`, `refunded_coins`,
`refunded_cents`, `status`, `occurred_at` (UTC Unix).
Références, membre et créateur sont des UUID fictifs ; `allocation_reference` est
un UUID de **tranche consommée** pour un cadeau financé, sinon null. Deux références
métier ne peuvent utiliser la même tranche, même si elles sont en litige.
Des tranches différentes d'un même pack exigent des UUID différents. Le simulateur
ne constitue pas le registre de ces tranches et ne vérifie pas leur provision réelle.

Quantités, montants et remboursements sont des entiers entre 0 et 2 147 483 647,
révision et date strictement positives ; les remboursements sont bornés par leurs
origines. Au plus 10 000 faits par appel. Les états sont `pending`, `confirmed`,
`failed`, `refunded`, `disputed`. Les seules catégories sont celles du catalogue.
Une classe earned/promotional ne peut pas être déclarée comme cadeau funded.

## Révisions, corrections et sessions fictives

Clé métier : propriétaire + référence. Les instantanés de remboursement sont
cumulatifs, pas des deltas. Même révision identique = rejeu sans effet ; contenu
différent = erreur. La révision la plus récente gagne indépendamment de l'ordre
d'arrivée. Seuls état, révision et remboursements peuvent changer ; bénéficiaires,
date d'origine, source, classe, financement et allocation restent immuables.
Les remboursements de pièces et d'euros ne peuvent pas diminuer entre révisions.

Pour un cadeau de 300 pièces / 725 centimes, une fixture corrigeant 100 pièces et
200 centimes produit 20 000 centièmes / 525 centimes : l'allocation de remboursement
est fournie, jamais calculée par proportion depuis le score. Un soutien direct
125 centimes, remboursé de 25, laisse 100 centièmes et 100 centimes. Un état
`refunded` ou `disputed` met les deux projections à zéro ; une nouvelle révision
confirmée peut rétablir le net après litige, sans effacer les remboursements.

Le paramètre facultatif `session={start,end,closed}` sélectionne le score sur
`[start,end[` selon la date d'origine. `closed` décrit une fixture, pas une session
persistée : une correction plus récente recalcule aussi une session clôturée.
La dépense du donateur reste cumulée hors de cette fenêtre. Aucun moteur de sessions,
classement territorial ou procédure de clôture n'est ajouté. Sans fenêtre, le score
porte sur tout le lot. Aucun pseudonyme, badge ou donnée publique n'est projeté.

## Décisions encore ouvertes et scénarios non simulés

Un cadeau gratuit avec `funding=faluss` est **explicitement rejeté comme non pris
en charge**, pas assimilé à une opération non financée : les preuves, allocations
et règles de score du financement Faluss restent à définir. Cela ne retire pas
l'exception produit prévue par [le contrat des moteurs](FANS-ENGINE-OWNERSHIP.md).
Aucune reclassification des PF earned/promotional ; aucun montant financé par
Faluss ne doit devenir une dépense personnelle du donateur.

Restent hors simulation : allocation économique d'un pack/remise, réconciliation
entre packs et tranches, remboursement d'un pack déjà consommé et propagation à
tous ses cadeaux, classes mixtes, réserves, commission, financement Faluss et
assiette du soutien. Les fixtures de cadeaux doivent déjà contenir les corrections
allouées par le propriétaire économique ; rembourser seulement la fixture pack
ne corrige pas automatiquement les cadeaux. Aucun taux ni ventilation n'est inventé.

## Tests et limites des preuves

Les tests couvrent unités distinctes, pack puis cadeau sans double comptage,
allocation EUR indépendante du score, soutien fractionnaire, remboursements partiels
et complets, litige/résolution, rejeu, ordre inverse, session clôturée et bornes,
tranche réutilisée, conflits de révision, mutations interdites, remboursements
régressifs, classe/funding incohérents, cadeaux gratuits, refus adulte et
auto-soutien refusé séparément pour les deux sources monétisables.
Ils s'exécutent en mémoire : aucune concurrence SQL, livraison Events, preuve de
paiement, recette WordPress réelle ou sécurité de transport n'est démontrée.

Avant un moteur actif : reçus Hub/prestataire authentifiés, stockage transactionnel,
réconciliation et compensation, admission réseau, résolveur de droits et politiques
de visibilité (pseudo/badge au créateur concerné, public sur consentement).
La correction d'auto-soutien complète l'adaptation v2 de #73 ; les limites
d'intégration ci-dessus restent applicables. Rollback : retirer ces classes et
tests ; aucune donnée, migration ou configuration réelle à modifier.

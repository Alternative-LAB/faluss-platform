# Barrières territoriales1.2 — transport privé fermé

Contrat approuvé explicitement par ALB-Origine selon la
[proposition6239393497](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6239393497)
et son [accord consigné](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6239460605).
Uniquement Hub/Fans jetables, données, identités, achats et clés fictifs.

## Extension bornée

`hub.purchased-pf.ranking-barriers/1.2.0` ajoute le seul motif
`territory_admission_revoked`. Les messages signés register/close/lookup, leurs
domaines, permissions, audiences, propriétaire et limites ne changent pas.
Les nouvelles actions choisissent explicitement la version ; aucune négociation
ou conversion d'une ancienne action. Les contrats1.0/1.1 et leurs octets stockés
restent lisibles et continuent de refuser le nouveau motif. Le test de version
inconnue vise désormais1.3, non approuvée ; aucun scénario historique n'est retiré.

La référence close ne contient pas le type ni le territoire. Le propriétaire
Hub relit donc le descripteur immuable de la version sous ses verrous primaires,
vérifie origine, version et empreinte, puis exige `kind=admission`, une politique
territoriale non vide et une révision territoriale positive. Une admission
internationale, une session ou une barrière d'origine/pays/Créateur est refusée.
Fans applique aussi cette garde avant de préparer son action locale.

La fermeture se sérialise avec les confirmations par les verrous existants du
ledger officiel. Son instant vient du primaire après attente des verrous. Les
consommations antérieures et leurs corrections restent conservées. Ni montant,
débit, PF supplémentaire, score public ni ledger parallèle n'est créé.

Le motif1.1 `session_completed` reste accepté en1.2 avec ses mêmes contrôles :
session uniquement, échéance figée atteinte sur le primaire après les verrous.
Un arrêt anticipé reste une annulation explicite sans vainqueur.

## Reprise du transport

L'inbox privée existante conserve une action, une clé et la version explicite
avant HTTP. La fermeture locale interdit immédiatement les choix. Une réponse
perdue après COMMIT ne produit pas une nouvelle clé : lookup primaire, même
action, même contenu. Un ancien ACK ne modifie pas la version suivante.
Les requêtes et preuves restent privées ; aucun endpoint de site n'est ajouté.
Les gardes physiques de bail privé, socket et environnement restent obligatoires,
y compris si des constantes de recette sont copiées sur un WordPress ordinaire.

## Dépendance suivante, non livrée par ce seul transport

Le checkpoint métier doit encore être lié atomiquement à chaque modification,
retrait ou révocation de déclaration territoriale ; sa collecte exhaustive et
sa reprise par curseur borné doivent être raccordées à `TerritoryService` et aux
bindings de participation. Une nouvelle approbation devra retirer le contexte
précédent avant toute admission future sous le même consentement. Ce transport
seul ne rend donc pas le parcours territorial fonctionnel ni activable.

La perte du territoire indispensable d'un organisateur doit suspendre réellement
sa session avec le motif existant `session_suspended`. Elle n'est ni un retrait
volontaire de participant, ni une suspension globale du Créateur, ni une
révocation des pays autorisés aux Fans.

## Vérifications

Tests ciblés de syntaxe, signatures/version/audience, types stockés, anciennes
versions, nonce, origine/digest/version, concurrence, COMMIT incertain local et
Hub, lookup de la même clé et préservation du ledger. La recette utilise le
module applicatif existant des barrières HTTP ; aucun workflow, préparateur,
cache, délai ou runner n'est modifié. Les
[preuves isolées acquises](../evidence/hub-territory-barriers-12/README.md)
distinguent le transport final (383/0), les vraies courses du ledger fictif
(356/0) et le socle session/participation (564/0). Leurs baselines se recouvrent ;
la CI du head final puis du main intégré reste distincte.

Les tests ajoutent les courses avec une véritable confirmation dans le ledger
officiel fictif : fermeture avant débit, confirmation verrouillée avant fermeture,
lookup sans second débit et expiration après attente du mutex propriétaire.
Leur résultat physique reste distinct du simple test de syntaxe ou du transport.
La reprise des fragments et checkpoints métier territoriaux reste à vérifier dans
le lot de raccordement. Aucune durée ou purge applicable n'est ajoutée. Aucun site réel, SSO Me, pair de production, migration,
achat, activation ou publication n'est prouvé. #150 et RustFS #161 restent distincts.

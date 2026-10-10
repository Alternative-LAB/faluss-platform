# B3 — clôture normale attestée, contrat 1.1 fermé

## Accord et découpage

ALB-Origine a approuvé explicitement le 10 octobre 2026 la
[proposition 6096632541](https://github.com/Alternative-LAB/faluss-platform/issues/147#issuecomment-6096632541)
dans cette conversation. Instances Hub/Fans jetables et données fictives seulement.
Aucun site, pair réel, migration de site, flag ou opération économique réelle.
Conservation #150 et RustFS #161 demeurent distinctes.

Le premier sous-lot ajoute le **codec explicite**
`hub.purchased-pf.ranking-barriers/1.1.0`. Le défaut reste 1.0.0 ; son validateur
et `RankingBarrier::closeReference()` refusent toujours `session_completed`.
Les domaines de signature, permissions et champs ne changent pas. La version
fait partie du contexte signé et de la réponse liée à l'action/nonce/empreinte.
Ni négociation implicite ni interprétation rétroactive d'une ancienne preuve.

`BarrierCompletion::reference()` valide seulement la syntaxe de la nouvelle
raison, pas une échéance ou une admission. Ce codec n'est **pas encore raccordé**
au gateway, à la primitive propriétaire ou à la reprise Fans. Aucun appel 1.1
ne peut donc clôturer une session avec ce seul sous-lot.

## Règle approuvée à raccorder ensuite

La raison `session_completed` est réservée à une barrière de type session
dont l'échéance figée est atteinte, selon l'instant primaire Hub **après attente
des verrous**. Le rattachement exact origine/politique/type/version/empreinte
et les permissions actuelles restent exigés. Un arrêt anticipé est une
annulation explicite, sans vainqueur ; aucune date ouverte n'est raccourcie.

La même action et clé sont persistées avant envoi. État local `closing`
immédiat ; clôture effective seulement sur acquittement ou lookup primaire.
Ancien ACK d'ouverture, timeout ou réponse perdue ne créent aucune nouvelle
clé ni réouverture. Les contributions antérieures et corrections après clôture
restent conservées ; aucun titre, récompense ou avantage irréversible.

Sous-lots suivants : primitive distincte propriétaire et barrière temporelle ;
dispatch HTTP explicitement versionné ; persistance/reprise Fans ; raccordement
de la gouvernance B2. Le ledger, les claims, la version 1.0 et leurs écritures
restent inchangés. Aucun contrat supplémentaire n'est déduit de cet accord.

## Scénarios et limites de preuve

Positifs : close/lookup 1.1 signés, même action/clé, référence et raison exactes ;
1.0 reste le défaut et continue d'accepter ses opérations historiques.
Négatifs : raison nouvelle en 1.0, version inconnue, changement de version hors
contexte signé, réponse d'une autre version/action/raison ou champ de gagnant.

Les tests du codec ne prouvent pas l'heure primaire, les verrous, la panne
après COMMIT ou le véritable SSO. Ces recettes suivent dans leurs sous-lots,
avant toute déclaration de raccordement fonctionnel. Retour arrière : retirer
ce codec inerte, sans tables, hooks, routes, migration ou données à réécrire.

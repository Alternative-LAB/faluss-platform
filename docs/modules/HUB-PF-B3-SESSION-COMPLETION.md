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
raison, pas une échéance ou une admission. Le sous-lot propriétaire décrit ci-dessous
l'utilise explicitement ; le dispatch signé 1.1 décrit ci-dessous le raccorde
uniquement en isolation. La reprise durable Fans 1.1 est décrite ci-dessous ;
le raccordement à la gouvernance B1/B2 reste nécessaire.

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

Sous-lot suivant : raccordement
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

## Primitive propriétaire 1.1 fermée

`ClosedBarrierStore::completeSession()` et `lookupCompletion()` sont distincts
des entrées historiques. Ils exigent le contexte 1.1 authentifié par leur futur
appelant, la permission de fermeture et, pour lookup, celle de lecture primaire.
Le contexte lie action, clé, objet, origine et politique ; la version fait partie
de son empreinte. La composition ne vérifie pas une signature à la place du gateway.

Sous le mutex propriétaire et une transaction InnoDB, la version exacte et la
version courante sont verrouillées. Le descripteur stocké, son empreinte et son
type `session` sont vérifiés. L'heure UTC6 est ensuite lue sur cette connexion
primaire : avant `valid_until`, aucun état, action ni événement ne change ; à
l'échéance exacte ou après, état fermé, opération et audit sont atomiques.
Un arrêt anticipé reste `session_cancelled`. Une session déjà annulée ne devient
pas terminée. Aucun gagnant, score ou récompense n'est calculé ici.

Les rejeux et lookups conservent l'instant de la première clôture et la même
action/clé. La perte d'acquittement de COMMIT est inconnue, jamais un rollback
affirmé ; le primaire départage absence et résultat confirmé. La fraîcheur du
contexte est vérifiée avant et après attente, puis avant COMMIT. Aucun verrou
économique n'est pris dans une fermeture ; les consommations déjà confirmées
et corrections restent intactes. Les schémas historiques ne sont pas modifiés.

Recette `run.py --b3-barrier-completion` : avant/à/après échéance primaire,
mauvais type/origine/politique/permission, concurrence, fermeture après attente
d'une sélection en cours, contexte expiré, annulation anticipée, erreur d'audit,
perte de réponse et arrêt du processus avant/après COMMIT. Les tables du ledger,
claims, consommations, reçus et journaux sont comparées sans diffusion de données.
Cette recette propriétaire ne prouve ni le réseau 1.1 ni le véritable SSO.
La [preuve isolée](../evidence/hub-pf-b3-barrier-completion-owner/README.md)
joint 309 contrôles, dont 37 nouveaux, et la destruction de la fixture.
Retour arrière : retirer ces entrées fermées et la fixture ; aucun site migré.

## Dispatch HTTP 1.1 fermé

Le gateway authentifie la version explicite dans les deux domaines signés,
puis admet le nonce et choisit la primitive propriétaire. Seuls close et lookup
avec la raison `session_completed` en 1.1 appellent les entrées distinctes ;
le défaut 1.0 et ses usages continuent sans réinterprétation. La réponse signée
conserve la version, l'action, la clé, le nonce et l'empreinte du contenu.
Version inconnue, substitution de contexte ou réponse d'une autre version :
refus fermé. La fermeture n'effectue aucune consommation économique.

La recette `run.py --b3-barrier-completion-http` utilise les deux WordPress
jetables, leurs clés fictives distinctes et un HTTP POST loopback. Elle couvre
les signatures/versions/audiences/permissions invalides, avant et après
échéance primaire, huit nonces concurrents pour la même action, le rejeu d'un
nonce, la perte du corps HTTP après COMMIT puis lookup sur la même clé,
et la compatibilité du lookup d'ouverture 1.0 sans réouverture.
Un fichier privé 0600 dans la fixture fixe l'horloge SQL pour les bornes ; il
n'est ni un champ HTTP ni une option et reste derrière la barrière physique
de recette. La preuve d'attente réelle des verrous demeure celle du lot
propriétaire. Aucun véritable SSO, pair réel ou cycle de vie B2 n'est attesté.

La reprise durable Fans 1.1 persiste la version de l'action avant
le premier HTTP. Aucun code de ces lots ne lance une fermeture sur les sites.

## Reprise Fans 1.1 fermée

L'action est composée uniquement par le serveur de confiance. `prepare()`
requiert une version explicite pour la fin normale ; son défaut reste 1.0 et
refuse cette raison. Les anciennes lignes gardent exactement leurs octets.
Les nouvelles actions portent une enveloppe locale `contract/fields` hachée
dans la colonne existante : aucune migration ou réinterprétation d'un ancien
ACK. La version est relue et vérifiée à chaque checkpoint, signature de demande,
réponse et récupération historique avec confiance Hub actuelle.

La préparation de la fermeture et l'action/clé sont atomiques ; état `closing`
immédiat. Une ouverture 1.0 incertaine est d'abord résolue, sans réouvrir les
choix locaux. Un refus signé `pf_barrier_completion_not_due` reste pending,
sur la même action/clé : l'heure Fans ne remplace pas celle du primaire Hub.
Le nombre d'échanges par avance reste borné ; aucune boucle de fond ajoutée.
Absence primaire, livraison puis réponse perdue suivent le même protocole de
lookup ; aucune autre clé n'est créée pour contourner l'attente ou la panne.

Le schéma privé à quatre tables est inchangé. Les HTTP sont effectués après
enregistrement durable, hors transaction et mutex local. Version substituée,
nouvelle action concurrente, type autre qu'une session, preuve d'un pair/clé
non autorisé ou anciens octets corrompus : refus fermé. Une clé Hub révoquée
ne peut pas habiliter une preuve persistée. Les anciens ACK ne réouvrent pas.

Recette `--b3-barrier-completion-recovery` : préparation concurrente, demande
de mauvaise version, échéance non atteinte puis lookup/close, corps perdu
après COMMIT, erreur d'insertion, COMMIT local incertain et mort du processus
avant/après COMMIT ; reprise avec action, clé et version d'origine. Comparaison
des actions 1.0 historiques et du ledger. La preuve réseau est celle des deux
WordPress jetables et non celle du véritable SSO ou de la gouvernance B2.
La [preuve isolée de reprise](../evidence/fans-hof-b3-completion-recovery/README.md)
joint 385 contrôles, dont 28 nouveaux, et la destruction de la fixture.

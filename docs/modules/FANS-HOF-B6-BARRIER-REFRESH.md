# B6 — relecture primaire des barrières existantes

Périmètre fermé Hub/Fans jetable. Les messages approuvés
[`ranking-barriers/1.0.0` et `1.1.0`](HUB-PF-B3-BARRIER-TRANSPORT.md) sont
réutilisés sans champ, permission ou opération Hub supplémentaire. Aucun
code Hub, claim historique ou ledger modifié. Cette relecture ne consomme
aucun PF ; elle ne transforme pas un pack en points.

## Préparation, requête et reprise

L'acquittement durable d'une ouverture atteste sa mutation passée. Il ne
prouve pas à lui seul l'état primaire courant de la barrière. Une livraison
B6 doit préparer explicitement une relecture avant de se fier à cet état.

`ClosedBarrierPrimaryRead::prepare` ferme immédiatement sa livraison et
persiste une révision privée sous le mutex/transaction d'origine déjà
propriétaire de l'inbox. Une préparation concurrente ou répétée conserve
la révision en attente. La requête signée est persistée avant HTTP ; elle
utilise **lookup**, l'action, la clé, le contenu et la version d'origine.
Une requête GET, un paramètre de navigateur ou une nouvelle clé ne remplace
pas cette opération. Le client n'envoie qu'une lecture par appel, uniquement
à l'endpoint loopback de recette, hors transaction et mutex.

Un timeout ou une réponse inauthentique conserve l'état incertain. La reprise
utilise la même action/clé et un nouveau nonce de lecture. Seule la réponse
authentifiée liée au **dernier challenge durable** règle la révision. Les
nonces des anciennes révisions restent enregistrés et ne peuvent pas devenir
le nouveau challenge. Un COMMIT local incertain est inspecté par recover :
aucune nouvelle révision ou opération économique n'est créée pour le contourner.

`not_found` ou refus primaire ferme cette lecture : **aucun enregistrement
automatique** en secours. Les anciennes signatures et labels locaux restent
des éléments historiques, sans autoriser de nouvelles contributions.

## Livraison atomique et autorisations

`withCurrentAcknowledgement` relit le challenge, son historique, le digest,
la signature et la confiance actuelle, dans la transaction propriétaire de
la barrière. Le callback reçoit champs d'origine, résultat primaire actuel,
état local et révision de lecture ; aucune clé de reprise ou wire. Le callback
doit utiliser cette transaction, sans HTTP ni BEGIN imbriqué. Son rollback est commun.

Le résultat primaire peut être `active`, `closed` ou `superseded`. Ce contrat
est une preuve, pas une autorisation : le fournisseur B6 doit appliquer les
règles de l'origine/session et de visibilité. **Un état local closing reste
prioritaire**, même si la dernière réponse primaire était active. Une ancienne
réponse ne rouvre jamais le cycle de vie. La fin normale attestée 1.1 reste
distincte de l'annulation ; les faits antérieurs demeurent corrigibles.

`effective_at` reste la date de mutation d'origine ; il n'est pas réinterprété
comme date de cette lecture. L'attestation vaut à la lecture primaire réalisée,
sans TTL inventé ni promesse de fraîcheur future continue. Chaque livraison
nouvelle doit demander sa relecture explicite et celle du corpus complet.

## Stockage, compatibilité et scénarios

Schéma privé additif séparé : trois tables de métadonnées de lecture, historique
de nonces et version. Installation **explicite de recette seulement** ; aucun
hook d'activation, option, flag, cron, API ou migration de site. Le schéma
historique de barrières et ses écritures restent inchangés. Une installation
partielle, divergente ou MyISAM échoue fermée. Retour arrière : fermer ces
lectures et conserver les checkpoints avant revert, sans réouvrir les anciennes
preuves ni supprimer les actions originales. Aucun défaut de conservation réelle
introduit ; #150 demeure une décision distincte.

Recette `--b6-barrier-refresh`, deux WordPress/MariaDB réels jetables :
préparations concurrentes, requête durable avant HTTP, signatures/audiences et
expiration, challenges désordonnés, retrait de clé, réponse perdue après COMMIT,
reprise, rollback du callback, fermeture locale et primaire, not_found sans
enregistrement ni nouvel événement. Ledger officiel comparé, source privée
non économique. Les gardes physiques refusent un WordPress ordinaire avant SQL
ou HTTP, même avec des constantes copiées.

Le [rapport de branche](../evidence/fans-hof-b6-barrier-refresh/README.md)
réussit 426 contrôles, dont 27 nouveaux B6br ; fixtures nettoyées. Il ne
remplace pas la vérification finale du main après intégration.

Ce lot ne compose pas encore corpus, permissions de toutes les participations,
territoires et identités dans l'UI B6. Aucun score public, site, achat, admission
réelle ou publication. Les médias RustFS #161 restent indépendants.

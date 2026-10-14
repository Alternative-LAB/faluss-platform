# B1 — ouverture d'origine attestée, périmètre fermé

## Scénarios et périmètre

Instances WordPress/MariaDB Hub et Fans jetables, données fictives et HTTP
loopback uniquement. Origine préparée dans le registre B1, puis intervalle
figé explicitement par l'administrateur de recette. Aucune source d'achat,
route de site, bootstrap, cron, flag, migration ou opération PF.

Positifs : même action après appels concurrents ou panne, preuve primaire
authentifiée, application atomique, reprise après COMMIT incertain, idempotence.
Négatifs : invité, schéma partiel, absence d'ACK, intervalle remplacé, confiance
révoquée, ancien ACK pendant fermeture et origine finalement fermée.

## Dates retenues et modèle

`ClosedOriginOpening` conserve deux instants distincts, à six décimales en UTC :

- `primary_ack_at` : instant `effective_at` du register attesté par Hub ;
- `admissible_from` : maximum de cet instant et du début figé `valid_from`.

Si l'admission est programmée plus tard que son acquittement, aucune attribution
ne devient admissible avant ce début. Si l'acquittement arrive plus tard, le
début prévu n'est pas antidaté. Aucun horodatage WordPress de création de compte,
d'achat de pack, de requête navigateur ou de réception réseau ne sert d'origine.
L'origine reste fermée jusqu'à cette preuve et au début de son intervalle.

Deux tables privées InnoDB, installées explicitement sous la garde physique
`ClosedEnvironment`, conservent version du schéma et enregistrement d'origine :
action, octets/digest, état, acteurs locaux et instants attestés. Aucun ledger,
solde ou copie d'opération économique. La disponibilité ne crée aucune table.

Préparation sous mutex du registre, puis préparation de l'inbox hors transaction
locale : un arrêt dans cet intervalle reprend l'action enregistrée. Les clés de
reprise restent dans l'inbox. L'application de l'ACK réutilise sa façade, son
mutex d'origine et sa transaction ; preuve, confiance actuelle, référence,
version et octets immuables sont vérifiés avant l'écriture locale.

Chaque lecture de recette vérifie à nouveau ces contrôles, l'état actif de la
barrière et les bornes de l'intervalle sur le primaire local. Un ancien register
ne rouvre jamais une origine en fermeture ou fermée. L'historique d'ouverture
est conservé sans réécriture lors du retrait. Après COMMIT incertain, une lecture
primaire et la même action retrouvent l'état ; aucune clé de remplacement.

## Recette et limites

`tests/TokenEngine/recipe/run.py --b1-origin-ack` rejoue les barrières 1.0/1.1,
HTTP et reprise, puis l'enregistrement d'origine. La CI ajoute ce drapeau au job
existant, sans retirer de contrôle. La [preuve complète](../evidence/fans-hof-b1-origin-ack/README.md)
recense 417 contrôles WordPress/MariaDB, dont 18 nouveaux pour l'origine.

Ce service fermé est réservé à l'administration de recette et n'est pas une
API publique Fans. Le raccordement aux choix d'intention, barrières de Créateur
et participation B2, projections B4/B5 et lecture B6 demeure distinct. Aucun
véritable SSO, producteur d'achat, classement public ou environnement réel n'est
validé par cette preuve ; #150 et #161 restent séparées.

Retour arrière : retrait des classes inertes, conservation des tables privées
pour reprise compatible ; aucun DROP, purge ou changement de rétention automatique.

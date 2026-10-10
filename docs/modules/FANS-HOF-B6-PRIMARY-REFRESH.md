# B6 — relecture primaire explicite, périmètre fermé

**Point de pause du 10 octobre : implémentation en brouillon, fraîcheur non
validée.** La [recette partielle](../evidence/fans-hof-b6-primary-refresh/README.md)
a échoué après 599 contrôles achevés ; le prédicat fautif de la promotion de la
même génération reste à isoler. Les garanties ci-dessous décrivent le contrat
visé, pas une livraison B6 fonctionnelle attestée.

## Contrat et scénarios

Le corpus signé complet atteste un instant. Il ne prouve pas l'absence d'une
correction ou attribution ultérieure. `ClosedCorpusReader::refresh` réutilise
le message `finish` déjà approuvé, la même génération immuable, la même identité
de lecture et la même clé. Aucun nouveau contrat Hub, permission, champ réseau,
schéma SQL, route, cron ou flag. Le transport reste physiquement inaccessible
sur un site ordinaire.

La préparation locale ferme la lecture de la source et du cache dans la même
transaction. Le digest de la nouvelle requête signée est enregistré avant HTTP,
hors transaction. Seule une réponse authentifiée liée à ce digest peut régler
cette relecture. Un ancien succès ou refus, même encore valide, ne convient pas.
Un nonce déjà enregistré ne peut pas devenir le nouveau challenge.

Positifs : préparation concurrente, ACK primaire, réponse perdue, COMMIT local
incertain, reprise du même job, reconstruction après correction.
Négatifs : ancien ACK, nonce réutilisé, génération historique, primaire absent,
clé révoquée, faits modifiés, correction incomplète et budget invalide.

Une réponse perdue appelle de nouveau cette opération de lecture idempotente
avec un nonce frais ; aucun nouveau corpus, débit ou clé. Les refus attestés
sont terminaux. Seule une nouvelle demande explicite de rapprochement peut
ensuite préparer une génération distincte. La génération ancienne ne redevient
jamais actuelle sur la seule base d'une réception réseau ou d'un délai local.

La nouvelle preuve primaire change l'instant attesté : le cache B4/B5 doit être
reconstruit atomiquement avant lecture. Le résultat demeure exact **à cet
instant** ; il n'est pas une garantie de fraîcheur future. Le raccordement B6
doit en outre vérifier origine, sessions, identité et visibilité actuelles.

## Compatibilité et limites

Les progrès ordinaires conservés sont inchangés. Le champ privé optionnel
`fence_request_sha256` apparaît seulement après cette opération explicite ; un
ancien lecteur échoue fermé face à ce format au lieu de le mal interpréter.
Aucun site n'est migré. Retour arrière : garder les checkpoints privés pour
reprise ; ne pas supprimer de preuve ou rouvrir un cache devenu indisponible.

La recette `--b6-primary-refresh` utilise deux WordPress/MariaDB jetables, HTTP
loopback et données fictives. Elle rejoue H0–H4, corpus, B4 et B5. Les preuves
seront consignées après exécution ; aucun rendu UI ou véritable SSO n'est
revendiqué par ce sous-lot. #150 et #161 restent distinctes, version publiée
inchangée, aucun score public ou admission de production.

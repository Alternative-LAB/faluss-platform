# B6 — relecture primaire explicite, périmètre fermé

**Relecture validée en isolation sur la branche consolidée de #203.** La
[jonction du 13 octobre](FANS-HOF-B6-JOINED-INTEGRATION.md) au source
`1391adab21e3e0e0ec8d68be6e026924580c2806` réussit **727 contrôles**, dont
**28 B6pr**, **18 B6tx** et **18 B6join**. Les échecs de harnais antérieurs
restent documentés ci-dessous ; ils ne décrivent plus le résultat courant.
Cette preuve de branche ne démontre ni le `main` consolidé, ni une livraison
B6 complète, ni le fonctionnement des sites. Les vérifications finales sur
`main` et le raccordement des identités, permissions et vues restent nécessaires.

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
loopback et données fictives. La recette commune `--b6-joined-integration`
rejoue H0–H4, corpus, B4 et B5 puis compose les acquittements de barrières
avec le corpus/cache sur la même origine. Le
[rapport expurgé](../evidence/fans-hof-b6-joined-integration/wordpress-checks.json)
confirme `failed=0` et le nettoyage final. Aucun rendu UI, véritable SSO,
dimensionnement de production ou fraîcheur future n'est revendiqué.
#150 et #161 restent distinctes ; aucun score public ou admission de production.

## Historique des défauts du harnais, corrigés

La [recette partielle du 10 octobre](../evidence/fans-hof-b6-primary-refresh/README.md)
avait échoué après 599 contrôles achevés. Le diagnostic a isolé l'horloge SQL
figée du harnais, sans défaut runtime démontré. Les heads `69bf2cc` (#202) et
`f93ff15` (#203) ont ensuite révélé une lecture concurrente du fichier pendant
sa troncature (`decimal.InvalidOperation`). La publication utilise désormais
un remplacement atomique privé en 0600.

La reprise au tree `2f728925` passait 670 contrôles mais conservait une ancienne
copie du dictionnaire d'horloge. `RecipeClock` partage désormais son minimum
entre les closures et sérialise les publications. **Six tests Python** couvrent
la concurrence, les permissions, l'échec du remplacement et la copie ancienne.
La jonction complète citée en tête a ensuite réussi. Aucun délai du plugin,
contrat réseau ou contrôle CI n'a été réduit pour obtenir ce résultat.

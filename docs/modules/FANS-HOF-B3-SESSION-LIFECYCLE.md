# B2/B3 — cycle de vie fermé des sessions

## Périmètre et scénarios

Instances Hub/Fans WordPress/MariaDB jetables exclusivement, identités liées
localement et clés fictives. Aucun bootstrap, route, cron, flag, migration de site
ou opération PF. Ce pont ne prouve pas le véritable SSO, ni l'ouverture sur site.

Positifs : Créateur propriétaire d'une session examinée, préparation durable,
acquittement primaire, reprise sur les mêmes actions après panne, annulation,
suspension/réadmission et fin à l'échéance figée attestée.
Négatifs : invité/Fan/autre propriétaire, révision périmée, absence de preuve,
ancien acquittement/version, divergence des règles figées, panne du journal,
COMMIT incertain et fin demandée avant l'échéance primaire.

## Modèle et frontières

`ClosedSessionLifecycle` réutilise `SessionService`, ses révisions, quotas,
modération, profils approuvés et territoires examinés. Deux tables privées
InnoDB, installées explicitement sous garde physique `ClosedEnvironment`, lient
la session/version/opération à une action et aux octets immuables. Aucun ledger
PF n'y est créé. La disponibilité du schéma ne l'installe jamais.

La liaison est validée sous le mutex B2 avant préparation de l'inbox B3. Un arrêt
dans cet intervalle reprend l'action enregistrée. Une fermeture survenue avant
la première préparation conserve les deux actions, ouverture puis fermeture.
L'inbox garde les clés de reprise ; le pont n'en expose aucune.

HTTP intervient après COMMIT, hors de tout verrou/transaction local. L'application
de la preuve prend le mutex B2, puis celui de l'inbox et sa transaction : cet
ordre est unique. La façade vérifie à nouveau signature et confiance actuelle.
État B2, révision, journal et instant d'application sont écrits ensemble. Une
réapplication ne crée pas une seconde décision ; un résultat de COMMIT incertain
exige une lecture primaire locale et la même action, jamais un nouvel identifiant.

`opening` devient `open` seulement sur ACK actif authentifié, après nouvel examen
des permissions, profils, modération et quotas. `closing` ferme immédiatement
les nouveaux choix ; l'ancien ACK d'ouverture ne le rouvre jamais. Annulation et
suspension utilisent 1.0 ; `closed` exige 1.1, `session_completed` et l'échéance
figée vérifiée sur le primaire Hub. Les contributions antérieures et corrections
restent conservées ; aucun vainqueur ou avantage irréversible n'est ajouté.

Les champs éditoriaux restent locaux. Le message Hub porte un digest des règles,
leurs dates/portée/territoire admis, pas le titre, texte ou portrait du Créateur.
Une réadmission conserve les règles et augmente la version de barrière ; un
acquittement de l'ancienne version ne peut pas régler la nouvelle.

## Recette et limites

`tests/TokenEngine/recipe/run.py --b3-session-lifecycle` rejoue les primitives
historiques, H1–H4 et les barrières signées avant ce pont : deux vrais WordPress
locaux, MariaDB, HTTP loopback, données fictives. La CI ajoute ce test au job
existant sans supprimer de gate. La [preuve finale](../evidence/fans-hof-b3-session-lifecycle/README.md)
compte **436/436 contrôles**, dont **37 nouveaux** pour ce pont. La fixture a été
détruite. La suspension demandée par le propriétaire est refusée : l'action
administrative existante reste réservée à l'administrateur.

Restent distincts : origine réellement ouverte B1, barrières de participant et
visibilité, choix B3 au moment de l'intention, corpus exhaustif et projections
B4/B5, raccordement B6 et publication. Une ouverture restée non admise au-delà
de son échéance ne reçoit jamais de fin fictive : elle reste fermée aux choix,
avec refus à rapprocher. Ce sous-lot n'annonce pas la résolution de ce cas.

Retour arrière : retrait du pont inerte, tables privées conservées pour reprise ;
aucun DROP ou purge automatique. #150 et #161 restent séparées.

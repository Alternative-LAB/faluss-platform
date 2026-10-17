# B6 — checkpoints de révocation territoriale

Périmètre fermé du [contrat territorial approuvé](HUB-PF-TERRITORY-BARRIERS-12.md),
sur instances Hub/Fans jetables avec données et clés fictives exclusivement.
Ce lot ajoute le journal atomique, sa collecte et la remise des admissions ; il
ne déclare pas le cycle territorial complet, son interface ou son activation disponibles.

## Décision et reprise

`TerritoryService` reste propriétaire de la déclaration. Lorsqu'une modification,
un retrait ou une révocation invalide une approbation existante, le checkpoint
et la décision partagent le mutex métier, la connexion et la transaction InnoDB.
Un échec d'insertion annule les deux. Après résultat de COMMIT incertain, la
relecture retrouve une seule décision et son journal ; aucune nouvelle clé de
transport n'est inventée.

Le journal lie le Créateur, l'ancienne approbation, la politique et la nouvelle
révision territoriale. Les versions d'admission conservent leur descripteur
immuable et l'identité attestée. Ni les règles consenties ni la volonté de
participation ne sont réécrites en retrait volontaire.

Les sessions territoriales dont ce Créateur est organisateur accepté sont
capturées à leur version exacte, sous la limite approuvée de trois sessions
simultanées encore dans leur période. Une session ouverte ou en ouverture passe localement en fermeture
pour suspension dans cette même transaction. Une annulation ou une fin déjà en
cours conserve sa décision. Cet état ne prétend pas que Hub a fermé la barrière.

## Collecte exhaustive bornée

Un appel explicite traite au plus vingt versions avec un curseur stable
`(session_id, barrier_version)` et une ligne d'anticipation. Il vérifie les
empreintes et les descripteurs réels, puis retient uniquement l'ancienne
politique et approbation concernées. L'invalidation déjà commise empêche une
nouvelle admission sous cette approbation ; les admissions futures relèvent
d'une nouvelle version. Le curseur et les éléments ajoutés partagent leur
COMMIT. La reprise ne saute et ne duplique aucun fragment.

`collecting` signifie qu'il reste des versions à examiner. `draining` signifie
que la collecte est complète et que les éléments attendent leur remise :
**cela ne vaut pas acquittement de fermeture Hub**. Aucun appel HTTP n'est
effectué par la transaction, le GET ou un hook de production. `completed` exige
la fin de collecte et l'acquittement appliqué de tous les éléments concernés ;
une collecte sans élément concerné peut donc finir sans opération Hub.

## Remise des admissions et reprise future

La remise explicite retrouve le Créateur et les anciennes révisions à partir du
journal serveur, sans destinataire ou motif choisi par le navigateur. Elle lie
atomiquement l'élément à une action durable existante avant tout HTTP. Les
admissions déjà retirées conservent leur contrat, action, clé et acquittement ;
elles ne provoquent pas une deuxième fermeture.

Pour une ancienne admission encore active, la fermeture utilise le seul motif
1.2 approuvé. La réponse perdue après COMMIT reste incertaine, puis se reprend
par lookup primaire sous la même clé. Le rôle volontaire admis et son consentement
ne deviennent pas un retrait. Un retrait volontaire concurrent reste possible.
L'élément ne devient appliqué qu'après vérification de la preuve signée réelle,
du binding exact et de son instant primaire ; le réseau reste hors transaction.

Une déclaration en attente ne rétablit rien. Après une nouvelle approbation,
l'ancien acquittement appliqué est requis avant la création d'une version future,
avec nouvelle date serveur et les mêmes règles consenties. Une panne au COMMIT
se reprend à partir de cette version unique, sans avantage rétroactif. Une ancienne
réponse de fermeture ne peut modifier cette version suivante.

## Stockage, permissions et compatibilité

Trois tables de métadonnées privées `fans_hof_b6t_*`, aucun ledger ni quantité PF.
Installation explicite seulement derrière la garde physique des instances
jetables ; un schéma partiel ou divergent est refusé. La lecture de disponibilité
ne crée rien. Un site ordinaire sans protocole de recette conserve son chemin B2 ;
copier ses constantes ne permet pas de franchir la garde physique.

Seuls le propriétaire de la déclaration et les administrateurs autorisés peuvent
avancer son curseur. Aucun destinataire arbitraire, API réseau, cron ou migration
de site n'est ajouté. Les anciens claims et écritures Hub restent inchangés.
Retour arrière : arrêter cette recette puis détruire ses seules ressources ;
ne pas supprimer un journal incomplet pour prétendre une fermeture réussie.

## Vérifications et suite

Scénarios positifs : approbation réelle, admissions Hub acquittées, vingt-trois
versions historiques/courantes, collecte en plusieurs fragments et reprise.
Scénarios négatifs : schéma absent/partiel, membre non habilité, révision périmée,
échec d'insertion du journal ou d'un élément, COMMIT incertain et budget invalide.
Les services et acquittements sont réels dans la fixture ; aucune identité ou
opération économique réelle n'est utilisée.

La remise des sessions vérifie le checkpoint exact, le parent capturé et son
enregistrement immuable. Elle peut ainsi traiter la perte territoriale d'un
coorganisateur accepté sans lui donner un pouvoir général de propriétaire.
Elle utilise le motif existant `session_suspended`, jamais une suspension
globale du Créateur. Une fermeture déjà engagée conserve son motif et sa clé.
Un ancien acquittement ne peut fermer une nouvelle version de session.
Le parcours d'interface territorial, son masquage public par dimension et son
adaptation native restent à raccorder ; la remise privée ne les rend pas disponibles.

Les [résultats physiques exacts](../evidence/fans-hof-b6-territory-checkpoints/README.md)
sont acquis :611 contrôles sans échec, dont45 territoriaux, sur le code fermé.
La CI du futur head et du main intégré reste distincte. Aucun site,
SSO Me, délai de conservation, score réel, flag ou publication n'est validé par
ce document. #150 et RustFS #161 restent distinctes.

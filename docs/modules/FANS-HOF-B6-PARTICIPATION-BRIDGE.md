# B6 — reprise privée des participations (recette fermée)

Ce pont raccorde l'admission B2 aux barrières `kind=admission` du contrat Hub
déjà approuvé `ranking-barriers/1.0.0`. Aucun format, permission ou opération
économique Hub n'est ajouté. Il ne constitue pas encore une interface de
participation ou une sélection d'intention utilisable sur un site.

## Responsabilité et limites

`ClosedParticipationLifecycle` est réservé à la garde physique des instances
Hub/Fans jetables : environnement local, bail privé, socket MariaDB de recette,
base primaire exacte, pair fictif et HTTP loopback. Aucune factory de production,
route publique, tâche planifiée ou installation automatique ne le charge.
`ClosedParticipationSchema::installForRecipe()` installe explicitement trois
tables InnoDB de métadonnées, préfixées `fans_hof_b6p_`. Une installation partielle
ou divergente est refusée. Ces tables ne contiennent ni PF, ni solde, ni ledger.

## Admission et reprise

1. La demande volontaire reste distincte de la coorganisation. L'admission
   passe par les permissions, règles figées, modération et territoires de B2.
2. Le pont exige l'acquittement signé de la version de session actuellement
   ouverte. Une ouverture locale en cours n'autorise aucune admission Hub.
3. Dans la même transaction primaire que la décision B2, il lit le propriétaire
   actif par le contrat Profils puis son Faluss ID par le contrat SSO verrouillé.
   Le navigateur ne fournit jamais ce sujet. La décision, son audit, le descripteur
   et l'action d'enregistrement immuables sont validés ou annulés ensemble.
4. Les versions de barrière de participation ont leur propre compteur continu.
   Les révisions locales de demande, refus, retrait et recours ne sont pas ce
   compteur. Une nouvelle admission exige la fermeture acquittée de la précédente.
5. Après COMMIT seulement, `prepare()` retrouve l'action et laisse l'inbox créer
   sa clé d'idempotence durable. `advance()` reprend le transport existant :
   lookup primaire après une réponse incertaine, même action et même clé.
6. Si le résultat du COMMIT local est incertain, relire la session et la
   participation. Si l'admission existe, reprendre `prepare/advance` ; ne pas
   répéter une décision sur une ancienne révision ou générer une autre action.

Le descripteur conserve la version de session, la révision territoriale examinée
et la date d'admission. Le début admissible est le maximum du début de session
et de cette admission ; aucune contribution antérieure n'est rattachée.

## Retrait et historique

Le retrait B2 passe immédiatement le rôle admis à `closing`. Les futurs choix
doivent refuser ce rôle, même pendant une coupure. Le pont retrouve l'identité et
les règles immuables déjà liées, prépare la fermeture `participant_withdrawn`,
puis finalise `withdrawn` uniquement après preuve primaire. Une suspension du
profil n'empêche pas cette reprise. Un ancien ACK ne réouvre jamais un retrait
ou une admission plus récente. La réadmission utilise la version suivante sans
réécrire les versions et contributions antérieures.

Une admission issue d'un ancien appelant sans checkpoint du pont n'est pas
qualifiée implicitement : il faut utiliser l'admission atomique du pont. Aucun
Faluss ID n'est reconstitué pour fermer un rôle historique non lié.

Si un enregistrement n'a jamais été confirmé avant l'expiration de son intervalle,
la fermeture conserve son état en cours lorsque le protocole existant refuse
l'ouverture préalable. Aucun faux acquittement ni nouvelle clé ne contourne ce
cas ; une éventuelle extension Hub nécessiterait un accord distinct.

## Concurrence, intégrité et compatibilité

Ordre des verrous : domaine sessions, puis origine inbox, puis transaction
primaire. Aucun HTTP n'a lieu sous ces verrous. Les CAS de révision, descripteurs
canoniques et empreintes lient chaque ACK à sa participation et version exactes.
Une erreur d'audit ou de checkpoint annule toute la décision locale.

Les claims historiques, le ledger officiel, les projections B4/B5, les anciens
appelants B2 et les contrats Federation ne sont pas modifiés. Ni score public,
achat, source réelle, PC, indicateur financier Créateur, activation ou migration
de site n'est ajouté. Conservation #150 et stockage privé RustFS #161 restent
des décisions distinctes.

## Vérification

Scénarios ciblés : schéma absent/partiel, acteur non habilité, absence d'ACK de
session, admission concurrente, rollback des checkpoints, Faluss ID vérifié,
réponse perdue après COMMIT Hub, reprise sans double événement, retrait,
réadmission, ancien ACK, suspension, mort du processus avant/après COMMIT local.
[Recette consolidée acquise](../evidence/fans-hof-b6-participation/README.md) :
527 contrôles sans échec, dont37 participations, et40 contrôles historiques de
modération/recours. Aucune recette réelle du SSO Me, d'un site ou de la production
n'est revendiquée. Les preuves de branche restent distinctes de celles de main.

Suite : formulaires de demande/décision/retrait et recours, puis territoires et
choix d'intention sous attestations. Le raccordement B6 complet, sa recette
conjointe sur `main` et sa publication officielle restent nécessaires.
Le recours favorable utilise `admitAppeal()` : dossier réel, révision exacte,
décision et journal de recours, admission et checkpoint dans la même transaction.
Le contrat local `SessionAppeals::decideInTransaction()` conserve les contrôles
existants et exige transaction primaire et mutex sessions possédés par l'appelant.
Les décisions existantes restent autonomes via `decide()`. Aucun booléen fourni
par le navigateur ne remplace un dossier de recours et ses permissions.

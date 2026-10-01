# Notifications privées Fans

## Contrat et portée

La cloche des vues Fan/Créateur ouvre `/app/fan/notifications` ou
`/app/creator/notifications`. Lecture réservée à un compte local ordinaire lié
par SSO ; aucune lecture implicite de la boîte d’un membre pour un administrateur.
Les huit accès Créateur restent identiques. Le centre fournit une pagination de
20 événements, un filtre non lus, un compteur SQL exact et des formulaires natifs
lu/non lu avec nonce `fans_notifications`. GET ne marque jamais une notification
lue. Rejouer la même écriture ne décrémente pas un compteur artificiel.

Les nouvelles décisions sont capturées même si le flag UI est fermé. Il ne
s’agit pas d’un service de push : une page déjà affichée se rafraîchit lors de la
navigation/relecture. Aucun e-mail, SMTP, webhook ou permission Identity ajouté.

| Événement commité | Destinataire | Information communicable |
|---|---|---|
| Activation/suspension du profil | Propriétaire WP local du profil | État décidé ; aucune identité civile attestée |
| Approbation/refus/révocation de présentation | Propriétaire | Présentation approuvée/refusée/retirée ; motif fermé |
| Approbation/refus de publication ou image | Propriétaire | Type, décision, motif fermé |
| Demande de message | Créateur destinataire | Une demande existe, sans copie du texte |
| Nouveau message accepté par le serveur | Autre participant | Un message existe, sans expéditeur technique ni extrait |
| Acceptation/refus de demande | Fan demandeur | Décision du destinataire |
| Décision provisoire/finalisation de signalement | Les deux participants autorisés au dossier | Décision disponible ; aucun motif libre, preuve ou note interne |

`needs_revision` devient « Des modifications sont nécessaires avant approbation » ;
`prohibited_content` devient « Le contenu ne respecte pas les règles de publication ».
Les autres raisons ne sont pas transformées en texte libre. Réexamen interne,
conservation de litige et blocage personnel ne génèrent aucune alerte à l’autre
partie. Un recours reste consultable dans son état privé existant ; le centre ne
prétend pas qu’un canal externe ou une notification juridiquement suffisante a eu lieu.

## Persistance et atomicité

Table additive `{prefix}faluss_fans_notifications` InnoDB : identifiant local,
clé de déduplication SHA-256, destinataire local, type fermé, référence d’objet,
motif fermé, état non lu, date UTC. Ni corps de message/bio, ni e-mail, Faluss ID,
secret, nonce, identifiant d’expéditeur ou motif interne.

Préparation sur `init` priorité 1 des installations Fans avec SSO déjà préparé,
avant les transactions métier ; l’option de version est posée seulement après
vérification des colonnes, moteur et index. Aucun DDL dans les écritures métier.
Les domaines insèrent l’événement **avant leur commit**, dans la même transaction.
Un échec du stockage notification annule la décision ou le message et son journal.
Pas de notification pour une action refusée, une transaction annulée ou une
révision obsolète ; les rejeux des messages retournent la création déjà commise.
La clé unique couvre destinataire/type/objet/révision (séquence pour un message).
Une réinstallation ou un redémarrage ne réémet pas les décisions anciennes.

Le centre ne reçoit pas de paramètre destinataire. Tous les SELECT/UPDATE sont
contraints par l’utilisateur courant. Les liens sont recalculés avec les droits
actuels : objet expiré, fermé ou inaccessible → aucun lien actif. La vérification
d’un lien conversation ne charge pas son texte et ne purge rien pendant le rendu.
Publication/image/dossier ouvrent leur objet précis dans le parcours propriétaire.
Les routes de destination contrôlent encore les permissions après le clic.

## Conservation et exploitation

Les notifications d’une conversation sont purgées dans la même transaction que
ses messages ordinaires (12 mois après le dernier message, règle existante).
Celles d’un dossier suivent sa purge de preuve (12 mois après décision définitive,
recours compris, et conservation de litige existante). La purge d’une conversation
n’efface pas les notifications d’un dossier conservé séparément. Ces purges
continuent lorsque l’admission messagerie est fermée. Une erreur de suppression
est remontée dans la santé de rétention ; le back-office ne présente plus un
résultat `error` ou `more` comme une purge complète réussie.

Les métadonnées des décisions de profil/publication/image sont conservées avec
leur historique local ; aucune durée nouvelle n’est prétendue validée. L’exploitant
doit fixer leur archivage/effacement, celui du journal opérateur et des sauvegardes
dans sa politique avant activation. Les durées des messages/preuves ratifiées ne
sont pas modifiées. Aucun contenu effacé n’est reconstitué dans une notification.

Le back-office Modules indique l’état réel du stockage. Un schéma absent/invalide
est un incident à corriger avant les décisions émettrices ; ne pas désactiver une
protection pour contourner une erreur. La création additive peut être relancée
par `NotificationSchema::installOrVerify()` dans une maintenance autorisée, mais
elle ne convertit pas silencieusement une table incompatible.
Retour arrière : plugin précédent ; conserver table/option et journaux. Les
événements produits pendant un ancien code sans notifications ne sont pas
reconstitués. Aucun nouveau flag, secret ou droit ne doit être changé.

## Décisions humaines restant distinctes

- Canal externe éventuel (aucun actuellement), destinataires, événements, base
  de contact, délai, preuve de remise, retries et mécanisme d’opposition.
- Qui communique la motivation détaillée d’un dossier, par quel canal confidentiel,
  avec quels délais de recours, accès, procédure de réexamen et preuve de clôture.
- Conservation des seules métadonnées éditoriales et des journaux opérateur,
  traitement des comptes supprimés et des sauvegardes.

Le bouton de finalisation de dossier continue d’exiger l’attestation humaine
explicite ; la présence ou la lecture d’une notification ne coche rien à sa place.

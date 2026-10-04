# Notifications privées Fans

## Contrat et portée

La cloche des vues Fan/Créateur ouvre `/app/fan/notifications` ou
`/app/creator/notifications`. Lecture réservée à un compte local ordinaire lié
par SSO ; aucune lecture implicite de la boîte d’un membre pour un administrateur.
Les huit accès Créateur restent identiques. Le centre fournit une pagination de
20 événements, un filtre non lus et un compteur SQL exact. Chaque ligne ouvrable
est un unique bouton de formulaire POST avec nonce `fans_notifications` : le
serveur relit la notification du membre, vérifie sa destination actuelle, marque
lue puis redirige en 303. Ni destination ni destinataire ne viennent du formulaire.
Un objet devenu inaccessible donne une ligne sans interaction ; un formulaire
ancien donne 409 sans marquage. La destination vérifie encore ses droits après la
redirection. GET, affichage et défilement ne changent jamais l’état. Le traitement
historique lu/non lu reste compatible, sans commandes visibles dans les lignes.
Aucun second état « ouverte ». Les lignes simples font 56 px, sans bordure colorée,
avec fond gris discret seulement pour les non lues et focus clavier visible.

Sur ce seul écran, la barre Notifications/Déconnexion, l’en-tête visuel et le pied
de page explicatif ne sont pas rendus. Un h1 « Notifications » reste accessible
aux lecteurs d’écran. Les filtres commencent en haut à gauche ; la liste utilise
la largeur disponible et le défilement naturel du document, sans panneau imbriqué.
Le seul lien de pagination inférieur est « Page suivante », présent uniquement
si le serveur fournit un curseur. « Toutes » revient à la première page. La
configuration de lecture privée est portée par le centre, indépendamment de la
cloche conservée sur les autres pages.

Les nouvelles décisions sont capturées même si le flag UI est fermé. Il ne
s’agit pas d’un service de push. Le centre relit sa page toutes les 12 secondes ;
la cloche seule toutes les 20 secondes. Retour visible, focus ou reconnexion réseau
provoquent une lecture immédiate. Aucun e-mail, SMTP, webhook ou permission Identity ajouté.

### Lecture dynamique privée

`GET /faluss-fans/v1/notification-view` exige session SSO locale et nonce REST.
Paramètres fermés : rôle de vue, partie `count`/`center`, filtre et curseur existants ;
aucun destinataire. Réponse privée `no-store` (y compris refus de l’adaptateur),
projection HTML échappée par le même rendu serveur que la page native. Pas de
référence d’objet ni destination dans la projection ; les formulaires contiennent
seulement l’identifiant de notification, leur nonce et l’action `open`.

Une seule lecture en vol, timeout 10 s, pause/annulation hors ligne ou onglet masqué,
reprise immédiate ; délai doublé sur erreur jusqu’à 120 s. L’expiration/refus efface
la liste et le compteur privés. Le garde de session attend une reconnexion sans
renouveler la session, en gardant une page masquée voilée jusqu’à revalidation.

Le filtre et le curseur restent inchangés. En première page, les nouveaux événements
arrivent en tête (ID monotone décroissant). Sur une page ancienne, le compteur
actualisé dans « Non lues » et le filtre « Toutes » donnent accès aux nouveautés sans déplacer la
pagination. Les lignes inchangées gardent leur nœud ; une ligne visible sert d’ancre
de défilement. Le focus reste sur la même notification, ou la suivante/le filtre si
elle quitte le filtre non lu ou devient inaccessible. Aucun défilement forcé vers
le haut. Sans JavaScript, filtres/pagination/formulaires POST restent opérationnels.

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

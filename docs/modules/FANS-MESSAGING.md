# Messagerie Fans — politique et traitements avant activation

Arbitrages utilisateur du 30 septembre 2026. Développement en lots de code ;
aucune activation ou visite de site. Faluss Identity reste l’autorité du compte.

## Accès et états

Un membre SSO lié peut agir en Fan et envoyer une demande **textuelle** à un
Créateur actif autre que lui. Une seule conversation courante par couple.
État pending : seul le texte initial est transmis ; aucun second message ni
réponse du Créateur avant acceptation. Le Créateur accepte ou refuse. Chacun
peut bloquer l’autre dans ce couple ; un abonnement ne contourne jamais blocage,
refus, suspension, limites, modération ou expiration. Débloquer ne constitue
pas une acceptation et ne réouvre pas un refus. Le refus reste terminal jusqu’à
l’expiration de la conversation. Aucun média, HTML, pièce jointe, e-mail ou SMTP.

Exception décidée : une preuve serveur actuelle d’abonnement Max ou au Créateur
permettrait l’ouverture directe. **Non disponible dans le code actuel de Fans** :
Subscriptions est propriétaire sur Hub, sans projection attestée consommable par
Fans ; l’abonnement au Créateur n’a pas de service. Aucune déclaration client,
cookie, rôle WordPress, champ SSO ou option manuelle ne remplace cette preuve.
Le parcours ordinaire reste disponible avec information explicite. À raccorder :
autorité, portée sujet/Créateur, expiration, révocation et fraîcheur de la preuve.
Cette limite ne bloque pas demandes, conversations acceptées et protections locales.

Limites techniques initiales, vérifiées sous verrou SQL : demande 1 000 caractères,
message 2 000 ; 2 nouvelles demandes/heure, 10/24 h, 5 demandes pending par Fan,
100 pending par Créateur ; 30 messages/heure et 200/24 h par expéditeur. Une demande
compte aussi comme message. Les limites amont PHP/proxy restent à configurer par
l’exploitant. Clé d’idempotence par expéditeur, contenu et destination stricts ;
aucun quota consommé par un rejeu identique pendant la conservation du message.

## Rétention décidée

- Messages ordinaires, demande incluse : suppression au plus tard **12 mois
  calendaires UTC après le dernier message envoyé**. Lire, accepter, refuser,
  signaler ou bloquer ne repousse jamais cette échéance. Aucun envoi sur une
  conversation expirée ; une nouvelle demande exige une nouvelle intention.
- Au signalement : isoler seulement le message désigné et son contexte minimal
  (auteur local, date, conversation, motif). Ne pas copier toute la conversation.
  La preuve est réservée aux modérateurs expressément habilités ; aucune API
  membre ne renvoie sa copie. La purge ordinaire reste indépendante.
- Preuves : purge **12 mois calendaires après la décision définitive, recours
  compris**. Une décision provisoire n’enclenche pas ce délai ; la finalisation
  doit confirmer le traitement des recours. Aucune finalité déduite du temps seul.
- Dossiers ouverts : échéance de revue humaine visible, relance dans le panel et
  trace de chaque réexamen. Pas de clôture automatique pour masquer un retard.
- Litige : conservation exceptionnelle motivée, datée, limitée et renouvelée
  explicitement, avec journal distinct. Elle ne prolonge jamais les messages
  ordinaires ni ne copie leur historique complet.

Les horloges de rétention sont indépendantes. La purge doit être idempotente,
transactionnelle et bornée ; les lectures ne doivent pas servir de texte expiré.
WP-Cron dépend du trafic : avant activation, l’exploitant doit prévoir un cron
fiable, surveiller les retards et traiter également réplicas, exports et sauvegardes.
Un DELETE ne prouve pas l’effacement physique des sauvegardes.

## Scénarios obligatoires

Positifs : Fan lié → demande → lecture par Créateur → acceptation → échange
bilatéral ; refus ; blocage/déblocage ; rejeu identique ; concurrence ; signalement
minimal, revue, recours, finalisation, litige motivé ; purges à la borne et après.
Négatifs : invité, compte non lié/privilégié, tiers, auto-contact, profil suspendu,
fausse preuve Max, média/HTML, nonce, révision périmée, quota, erreur SQL ; aucune
lecture des preuves par les participants, aucune prolongation par lecture/rejeu.

## Lots et preuve

1. Moteur local demande/conversation/blocage et expiration ordinaire, schéma additif
   et tests réels InnoDB ; pas de branchement runtime avant les protections complètes.
2. Signalement, modération, recours et rétention des preuves ; journal de litige.
3. UI Fan/Créateur et panel existant, cron, tests HTTP et captures ordinateur/mobile.

Pas de double passwordless, transport Hub inventé, abonnement vendu, paiement,
score, PF ou PC. Le flag de messagerie restera fermé par défaut. La recette
WordPress/Elementor/SSO cible et la validation des traitements restent au propriétaire.

## Contrats locaux du premier lot

`MessageModule` exige rôle `fans`, SSO configuré et schémas vérifiés, profils
Créateur et `FALUSS_PLATFORM_FANS_MESSAGING === true`. Ce lot n’enregistre encore
aucun hook ni route au démarrage du plugin. Aucun schéma installé au chargement.
`MessageSchema::installOrVerify()` est une opération d’activation explicite,
jamais un correctif silencieux d’un schéma incompatible.

Tables propres InnoDB : `faluss_fans_dm_threads`, `faluss_fans_dm_messages`,
`faluss_fans_dm_blocks`, option de version `faluss_fans_messages_schema_version`.
Les préférences de blocage survivent à la purge des contenus et disposent d’une
liste privée et d’un déblocage indépendant ; elles ne conservent aucun message.
Un verrou applicatif et une transaction sérialisent admissions, quotas et purges.
Les décisions utilisent une révision attendue ; les textes utilisent une clé de
rejeu UUID hachée. Aucune API ne divulgue ID utilisateur local ou matériel de rejeu.

API testée sous `/faluss-fans/v1` : `GET /messages` (curseur opaque),
`POST /messages/requests` (`creator_id`, `body`, `key`), `GET /messages/{id}`
(`after` séquence), `POST /messages/{id}/send` (`body`, `key`),
`POST /messages/{id}/decision` (`revision`, `action`), `GET /messages/blocks`,
`POST /messages/blocks/{id}/unblock` (`confirm: true`). Tous les accès exigent
session liée ordinaire et nonce REST ; les réponses privées sont `no-store`.
La lecture par pages n’étend pas la rétention. Les messages d’un profil suspendu
restent accessibles aux deux participants pour signaler ; tout nouvel envoi est refusé.

Retour arrière du premier lot : retirer le code sans toucher aux autres modules ;
aucune route, activation ni donnée runtime n’a encore été créée. Après intégration,
fermer l’admission ne devra pas interrompre les obligations de purge des données
déjà conservées. Aucun effacement automatique à la désactivation du plugin.

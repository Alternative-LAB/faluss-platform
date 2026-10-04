# Messagerie Fans — politique et traitements avant activation

[Repasse UI V2 : données disponibles, limites, recette et captures](../evidence/fans-messaging-layout/README.md).

[Procédure opérateur : habilitations, préparation fermée, cron, ouverture et fermeture](../operations/FANS-MESSAGING-RUNBOOK.md).

Arbitrages utilisateur du 30 septembre 2026. Développement en lots de code ;
aucune activation ou visite de site. Faluss Identity reste l’autorité du compte.

**État du lot raccordé** : moteurs des PR #110 et #111 fusionnés ; UI native,
panel existant, cron et contrôles navigateur raccordés dans le lot suivant.
[Preuves et captures](../evidence/fans-messaging/README.md). Les descriptions
« premier lot sans runtime » ci-dessous retracent la construction ; les portes
d’ouverture actuelles sont précisées à la fin de ce document.

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

## Signalement, décisions et recours

Le signalement porte sur **un message de l’autre participant** encore conservé,
dans sa conversation. Motif codifié : harcèlement, spam, contenu interdit, autre.
La copie contient texte, auteur local, date, références opaques et motif ; aucun
historique complet, e-mail, profil Identity, IP, média ou abonnement n’est copié.
Un rejeu renvoie le même dossier. Protection technique : trois nouveaux dossiers
par jour et vingt non définitifs par membre. Le membre peut toujours bloquer.

Les deux participants voient seulement référence, état et décision du dossier.
La preuve, les motivations internes et les journaux sont réservés aux comptes
ayant **les deux capacités** `manage_options` et `moderate_faluss_fans_messages`.
Aucune attribution automatique de cette capacité ; un administrateur ordinaire
ne l’obtient pas par le SSO, par son seul rôle ni par une requête. Un modérateur
ne peut pas examiner un dossier dont il est lui-même participant.

Décisions provisoires : absence de mesure, retrait du texte ordinaire, restriction
de la conversation, restauration. Une restriction préserve l’état antérieur :
restaurer une demande ne l’accepte pas. Une autre restriction encore applicable
au même fil reste effective. Restaurer ne recrée jamais de contenu ordinaire purgé.
Une décision d’absence de mesure ne lève pas implicitement une restriction antérieure :
utiliser la restauration explicite. Retirer un texte conserve ses métadonnées de
quota/rejeu jusqu’à l’expiration ordinaire, avec un texte vide rendu comme retiré.

Chaque partie peut former un recours textuel de mille caractères au plus, une
fois **par décision provisoire**. Il devient une pièce nécessaire du dossier privé.
La décision change alors en `appealed`, bloquant la finalisation jusqu’à nouvel
examen. Après une nouvelle décision, une nouvelle contestation reste possible.
La finalisation exige une motivation et l’attestation explicite du modérateur
que les recours ont été traités et que la décision est définitive. Aucun délai de
recours n’est inventé par le logiciel. Avant activation, l’exploitant doit définir
et communiquer sa procédure de notification, ses voies et délais applicables ;
il est responsable de la véracité de cette attestation, pas d’une clôture automatique.

## Revue, litiges et purge des preuves

Échéance opérationnelle de réexamen : trente jours après ouverture, réception
d’un recours ou réexamen humain. Le panel affiche les retards ; chaque réexamen
exige une motivation. Le passage du temps ne vaut jamais revue ou clôture.
Après finalisation sans litige actif, seules les obligations de purge demeurent.

La conservation de litige n’est possible qu’avant l’effacement : motif obligatoire,
responsable, début, échéance de un à quatre-vingt-dix jours et journal distinct
`faluss_fans_dm_legal_holds`. Renouvellement explicite et motivé, révocation explicite.
Les dossiers sous litige continuent à apparaître parmi les revues à effectuer.
Cette borne oblige au réexamen ; elle ne constitue pas une durée légale supposée.
L’exploitant doit surveiller les échéances et renouveler ce qui demeure nécessaire.
Aucun litige ne repousse l’expiration des messages ordinaires.

`faluss_fans_dm_reports` contient la preuve, `faluss_fans_dm_report_events` les
actes et recours, et le journal de litiges reste séparé. L’option
`faluss_fans_message_reports_schema_version` vérifie ces trois tables additives.
La purge détruit dans une même transaction preuve, recours et journaux sensibles
douze mois calendaires après la décision définitive, sauf litige encore actif.
Les lectures modérateur effacent les preuves expirées avant de renvoyer `410`.
Un échec SQL ne sert jamais de texte expiré. La purge batch traite cent dossiers
au plus par appel et fonctionne sans flag d’admission ni session de modérateur.

REST : `POST /messages/{thread}/report`, `GET /message-reports/mine`,
`POST /message-reports/{case}/appeal` pour les membres ; `GET /message-reports`,
`GET /message-reports/{case}`, `POST /message-reports/{case}/decision` pour les
modérateurs dédiés. JSON strict, nonce REST et réponses privées `no-store` partout.
Hooks, panel et automatisation des purges sont raccordés dans le lot UI suivant.

## Raccordement UI et conditions d’ouverture

Routes existantes `fan/messages` et `creator/messages`, sidebar Créateur à huit
accès, liste et échange en deux colonnes sur ordinateur. Sur mobile, un fil choisi
remplace la liste avec un retour explicite. Demande depuis le profil public,
formulaires natifs sans JavaScript, statuts HTTP réels, erreurs serveur, blocages
et recours accessibles séparément. Aucun UUID, nom Fan supposé, faux portrait,
badge d’abonné ou état « en ligne » inventé. Un nom Créateur n’est lu que depuis
la présentation éditoriale approuvée. Il n’existe pas de diffusion de nom Fan.

Le retour SSO conserve uniquement un paramètre UUID `creator` ou `thread` sur
les deux routes Messages. Jamais le texte rédigé, un nonce, un e-mail ou un droit.
La page cible revérifie toutes les permissions après connexion. Le vrai trajet
Identity Me n’a pas été exécuté par cette recette.

`MessageModule` est déclaré pour Fans uniquement, dépend de `fans-creator-profiles`
et s’installe à l’activation explicite du plugin. Il exige SSO configuré, profils,
les six tables privées vérifiées et l’attestation **hors Git**
`FALUSS_FANS_MESSAGING_POLICY_ATTESTED === true`. Toute nouvelle demande ou tout
envoi exige en plus `FALUSS_PLATFORM_FANS_MESSAGING === true`. Les constantes
sont absentes/fermées par défaut ; elles ne sont mises à vrai que dans les fixtures
isolées. Aucun compte modérateur n’est créé ni promu par le module.

Une fois les données installées et la politique attestée, fermer uniquement
`FALUSS_PLATFORM_FANS_MESSAGING` ferme les envois mais conserve lecture privée,
blocages, signalements et recours des membres liés. La modération habilitée et
la rétention restent disponibles indépendamment de l’admission. Ne pas fermer
l’Identity Client ou supprimer le plugin tant que les obligations de traitement
et de recours subsistent sans procédure de remplacement.

Cron horaire `faluss_fans_messages_retention` : dix lots maximum de cent fils et
cent dossiers chacun par exécution, transactions et échecs fermés. Le diagnostic
`faluss_fans_messages_retention_status` contient seulement date de contrôle,
compteurs de suppressions, lot restant et code d’erreur sans contenu. Le panel
signale exécution absente, erreur, retard supérieur à deux heures ou lot restant.
L’expiration empêche immédiatement la lecture ; l’effacement batch dépend de
l’exécution effective du cron. Avant ouverture : configurer un déclenchement
fiable, surveiller les lots en retard et tester sa capacité avec le volume prévu.
Une désactivation/absence du plugin empêche ses callbacks : elle n’efface pas les
preuves et ne remplace pas leur traitement. Les sauvegardes et journaux techniques
de l’hébergeur nécessitent une procédure séparée ; éviter les journaux SQL/HTTP
contenant des corps privés.

Attester la politique signifie avoir défini les personnes habilitées, les canaux
de notification, la procédure et les délais de recours, les réexamens, la gestion
des litiges, des sauvegardes et des erreurs de purge. Le code ne prétend pas
détecter automatiquement tout texte interdit. L’interdiction des contenus adultes
et des pièces jointes reste applicable ; quotas, blocage, signalement et revue
humaine ne sont pas une garantie de détection exhaustive. Cette validation et
la recette WordPress/Elementor/SSO restent à l’exploitant avant activation.

Limite persistante précise : **l’ouverture directe Max/abonnement Créateur n’est
pas fonctionnelle**, faute de preuve propriétaire accessible depuis Fans.
Le formulaire et l’aide le disent ; aucun rôle, paramètre, badge ou paiement
ne simule cette capacité. La demande ordinaire et la conversation acceptée
fonctionnent indépendamment de cette absence.

## Actualisation et mobile (après 0.12.3)

Les écrans mobiles et lectures périodiques sont décrits avec leurs limites dans
[la recette mobile et actualisation privée](../evidence/fans-messaging-mobile-live/README.md).
L’adaptateur privé `message-view` réutilise les lectures et formulaires existants ;
aucune activation, migration ou modification de rétention/permissions n’est requise.

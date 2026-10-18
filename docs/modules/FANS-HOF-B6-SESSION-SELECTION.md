# Choix explicite des sessions avant attribution — raccordement fermé en cours

## Frontière

Ce code réutilise les contrats Hub0.3 et barrières1.0 déjà approuvés. Aucun format,
permission, motif ou endpoint propriétaire Hub nouveau. Les constructeurs échouent
avant les lectures sur un site ordinaire ; la recette reste Hub/Fans jetable,
identités, preuves et clés fictives. Aucun bootstrap, migration ou activation.

La nouvelle direction visuelle HoF et l'intérieur de session restent reportés.
Le présent lot prépare le parcours existant avec ses composants, sans refonte de
sidebar et sans déclarer une interface ou une économie réelle disponible.

## Construction serveur

- Le membre vient de son compte lié et de son véritable token/cookie WordPress
  local non expiré. Un simple paramètre d'identité ou une session administrateur
  non liée ne délègue rien.
- Le profil Créateur est actif avec une présentation approuvée. Son Faluss ID est
  résolu via les contrats locaux ; auto-attribution refusée.
- Catégorie et politique de pays viennent de deux actions de registration
  **explicitement configurées côté serveur**, signées et relues sur le primaire,
  de la même origine active. Une catégorie de profil différente de la version
  enregistrée, fermeture ou preuve absente ferme ce parcours. Une valeur venant
  du formulaire ne devient jamais une autorité de catégorie ou de pays.
- La liste paginée parcourt les sessions ouvertes où ce Créateur est réellement
  admis. Chaque session conserve ses règles figées, modération, visibilité de
  l'organisateur et portée. Les admissions territoriales courantes ne sont pas
  extrapolées à partir d'un pays ou d'un profil.
- Chaque admission est relue depuis sa version native et son acquittement
  primaire courant. Un ACK historique seul n'autorise pas le choix. Les erreurs
  de stockage/preuve ne sont pas remplacées par une liste exacte vide.
- Le Fan choisit de zéro à dix sessions, sans sélection par défaut. Le serveur
  refuse doublons, champs fabriqués, sessions inconnues/fermées ou inadmissibles,
  et compose toutes les dates, empreintes et révisions depuis ces faits. Il trie
  seulement les identifiants choisis selon le format canonique existant.

La lecture et la construction partagent les mutex/transaction gouvernance et
origine. Elles ne réservent ni ne débitent de PF. Elles n'attachent jamais une
ancienne attribution à une nouvelle session.

## Transport et reprise

`ClosedRankedClient` conserve `ClosedClient`0.2 inchangé. Le nouveau client0.3
utilise uniquement l'endpoint loopback exact admis, avec permissions PF dédiées,
expiration de contexte liée à la session locale et aucune redirection/cookie
sortant. Il persiste l'intention et la clé d'opération avant HTTP.

Confirmation et lookup relisent l'intention immuable du membre courant : ils ne
reconstruisent pas un contexte depuis la catégorie ou les admissions actuelles.
Une présentation suspendue depuis ne prive pas le Fan de la reprise de son
résultat. Le lookup ne crée jamais une clé absente ; aucune nouvelle clé ne
contourne une réponse incertaine. Une réponse confirmée est authentifiée, puis
son reçu signé est conservé localement. Une panne de réception ne réécrit pas le
débit Hub déjà commis. Le navigateur ne reçoit aucun reçu, enveloppe ou secret.

Une fermeture locale interdit immédiatement de nouveaux choix. Les faits
confirmés avant la fermeture effective attestée par Hub restent conservés selon
le contrat approuvé ; la reprise ne réouvre aucune barrière.

## Checkpoint du parcours

Le checkpoint privé est créé explicitement dans la recette, sans migration de
site. Il référence l'intention immuable et le profil destinataire ; il ne contient
ni solde, ni nouveau ledger, ni copie des quantités économiques.

Un seul résultat non rapproché est autorisé par membre lié, même entre onglets
ou Créateurs distincts. Un mutex local de membre couvre l'action, mais aucune
transaction SQL ne reste ouverte pendant HTTP. L'état `unknown` et la clé stable
sont persistés **avant** l'envoi. Une visite, un retour navigateur ou une nouvelle
soumission identique ne renvoie pas silencieusement l'opération.

Après une réponse perdue, seul le lookup primaire de cette opération permet
d'avancer. `not_found` autorise explicitement un nouvel essai de **la même clé**,
pas une nouvelle intention. Un refus signé ne prouve pas l'absence d'une
consommation antérieure et laisse le parcours fermé. Une réponse `confirmed`,
`released` ou `expired` attestée rend l'état terminal. Les anciens formulaires ne
peuvent remplacer le checkpoint d'une nouvelle intention.

La création de l'intention ne consomme rien ; la confirmation reste une action
distincte. La reprise relit le contexte original même après suspension du profil.
Les lectures restent liées au vrai cookie/token du membre et ne réactivent aucune
barrière. Les historiques du protocole sont conservés ; aucune politique de purge
PF réelle n'est décidée par ce lot (#150).

## Scénarios et reste avant livraison

Scénarios positifs : liste sans admission, deux admissions natives, pagination,
choix vide et explicite, un seul débit alimentant deux sessions, réponse perdue
après COMMIT et lookup du même résultat même après suspension.

Scénarios négatifs : invité, cookie expiré, identité tierce, auto-attribution,
ACK sans relecture primaire, doublons et plus de dix choix, session inconnue,
contexte fabriqué et fermeture avant un nouveau choix. Les contrats historiques
et leurs tests doivent rester intacts.

**En cours, pas encore fonctionnel déclaré :** nouvelle recette SQL/HTTP et
contrôles du nouveau head restent à réussir. Le checkpoint durable et le
formulaire Fan sont raccordés uniquement après la barrière physique de recette.
Le profil éligible propose l'entrée vers `hof?support=<profil>` ; ce paramètre est
un repère de navigation, jamais une identité ou une permission. Le retour SSO ne
conserve que cette cible exacte, sans quantité ni choix économique.

Le formulaire sans JavaScript propose zéro à dix cases non cochées, des pages de
sessions avec conservation des choix, puis une préparation et une confirmation
distinctes. Le membre peut reprendre le même checkpoint et libérer une
réservation. Toute opération exige le cookie lié courant et un nonce ; les
champs d'identité, contexte et actions étrangères sont refusés. Chaque lecture
du service reste privée, sans cache partagé ; un GET ne crée aucune intention.
Le transport typé de recette n'est jamais consulté avant le garde physique.

Le head `65bcc18` a passé la recette complète : **944 contrôles, zéro échec**,
formulaires natifs et parcours territoriaux intégrés compris. Les
[captures et limites de preuve](../evidence/fans-session-selection-2026-10-18/README.md)
séparent ce fonctionnement HTTP des rendus de composants Windows.

Le nouveau head composé avec #239 doit encore passer sa recette conjointe :
un vrai formulaire natif choisit deux sessions, une réponse de confirmation est
perdue puis le lookup du même reçu capture un seul remerciement. Le worker doit
remettre uniquement le texte figé, après rapprochement complet, sans second débit
ou message lors du rejeu. La préparation et la réponse incertaine sans reçu
authentifié ne créent aucun message. Ces nouveaux cas ne sont pas annoncés
réussis avant leurs résultats. Aucun appel n'est admis sur un site ou un
producteur réel.

L'autorité réelle de politique des pays, l'achat et la conservation #150 restent
distincts. La configuration de recette fictive ne les valide pas à leur place.
RustFS #161 et les médias restent indépendants. Une publication devra décrire
ces frontières et ne rendra pas les scores utilisables sur les sites.

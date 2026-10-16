# B6 — formulaires de cycle de session, raccordement fermé en cours

Ce lot suit les [brouillons et examens des règles](FANS-HOF-B6-SESSION-UI.md).
Il réutilise le [pont B2/B3 approuvé](FANS-HOF-B3-SESSION-LIFECYCLE.md), sans
modifier un message Hub, une permission, un ledger ou un contrat économique.
**Travail en cours : les nouvelles actions n'ont pas encore leur preuve HTTP.**

`ClosedSessionActions` orchestre les appels aux services propriétaires existants.
Le membre lié ne choisit ni l'origine, ni le pair, ni le point d'accès ou la clé.
Le hook local `faluss_fans_hof_closed_sessions` est résolu uniquement après la
garde physique et la vérification des schémas, sans installation implicite. Le
service retourné doit utiliser la connexion de l'application courante. Ce hook
de configuration serveur ne permet pas une admission sur un site ordinaire.

Les GET montrent les actions disponibles sans transition ou requête Hub. Chaque
POST utilise le nonce et la révision de session existants. Ouverture/réadmission
revalident profil, règles, coorganisateurs, quotas et territoire. La politique
territoriale provient de la déclaration examinée courante, jamais d'un paramètre
du navigateur ou d'une politique par défaut.

La transition B2 est commise avant l'appel réseau. Le pont conserve sa liaison,
son action et sa clé ; la reprise effectue son lookup primaire existant. L'UI
ne montre un état terminal que lorsque l'application de la preuve l'a écrit.
Un résultat réseau encore incertain répond202 avec opening/closing, et une
action explicite de reprise. Une exception impose une relecture protégée ;
aucun remplacement de clé ou succès supposé n'est ajouté.

Un ACK de cycle n'est pas assimilé à une fraîcheur actuelle. Après application,
`ClosedSessionFreshness` prépare et exécute la lecture primaire existante sur
la même action, avec inbox, lecteur et client liés à la même connexion. Le hook
local fermé `faluss_fans_hof_closed_session_freshness` fournit cette composition.
Une transition attestée dont la relecture reste incertaine est distinguée dans
l'interface ; le bouton protégé « Vérifier l’affichage » reprend cette lecture
sans rouvrir la session ni créer une clé économique. Aucun GET ne l'exécute.
Cette coordination ponctuelle n'annonce pas une fraîcheur continue sur site.

Annulation confirmée, fin à échéance et suspension restent distinctes. La fin
anticipée est refusée localement avant la transition ; Hub1.1 vérifie encore
l'échéance figée après les verrous. Les règles et contributions précédentes ne
sont ni effacées ni redistribuées. Une demande d'ouverture durablement refusée
conserve la limite de rapprochement documentée par B3 ; aucun acquittement
fictif ne résout cette situation.

Une révocation administrative connue commise est distinguée de son transport de
fermeture. Si celui-ci reste en attente, le panel confirme la décision mais pas
la fermeture ; une reprise protégée par cible et révisions reste disponible.
Une annulation concurrente déjà engagée conserve son motif et son état final,
sans être transformée silencieusement en suspension.

Scénarios à prouver : refus de nonce/propriété/révision, examen et consentement
des coorganisateurs nécessaires, fin anticipée, ouverture et annulation
attestées, réponse perdue aprèsCOMMIT puis reprise, révocation et fermeture,
GET sans mutation, et fermeture physique hors recette. Aucun résultat de
branche précédente ne vaut preuve de ce raccordement.

Application : instances Hub/Fans jetables à données fictives uniquement ;
aucun flag, site, admission de pair ou migration de production. #150 et #161
restent distinctes. Retour arrière : retirer les adaptateurs/formulaires, en
conservant les liaisons et journaux existants pour la reprise ; aucune purge.

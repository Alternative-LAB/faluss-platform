# Remerciements PF — raccordement en recette fermée

## Source et absence de nouveau contrat Hub

Le candidat provient uniquement de la **première insertion** d'un reçu0.3 réellement
signé, validé contre l'intention immuable par l'inbox Fans. La capture n'est appelée
ni pour un ancien reçu retrouvé, ni pour une reconstruction de corpus, une correction,
une résolution de litige ou un clic navigateur. Achat du pack seul, PC, bonus,
anciens PF non admissibles et simples aperçus n'y entrent pas.

L'identifiant métier du message dérive de l'attribution, toutes projections
confondues. Une seule consommation peut alimenter plusieurs dimensions sans
plusieurs messages. Le texte/révision/époque de configuration sont figés lors de
la première confirmation authentifiée reçue et commise côté Fans. Aucun accord
Fan additionnel. Cette autorisation ne vaut pas acceptation générale d'échanges
en mode Sur demande.

Le reçu et le checkpoint de message sont commis dans la **même transaction locale**.
Le débit officiel Hub est antérieur et indépendant : un défaut de messagerie peut
laisser la réception Fans en attente, jamais annuler le soutien Hub confirmé ou
autoriser une seconde consommation. La reprise réutilise les clés d'intention
existantes et leur lookup primaire, sans clé de remplacement.

Deux tables explicitement installées en recette fermée portent seulement les
références et empreintes source ; aucune quantité, balance, écriture PF, argent
ou copie de conversation. Une installation absente ne collecte pas rétroactivement
les anciens reçus. Le schéma présent mais divergent fait échouer la réception
locale avant COMMIT, sans effet économique sur Hub. Ce schéma n'est pas une
politique applicable aux données réelles ; #150 demeure distincte.

## Livraison et ordre des verrous

La file, les conversations, notifications, quotas, blocages, refus, signalements,
modération et rétention restent ceux de la messagerie existante. Aucun média pour
le remerciement, aucune URL d'original, aucun wallet Créateur.

La livraison relit le reçu signé conservé et le **corpus exhaustif courant attesté**
de la même origine. Attribution, identités, consommation, contexte, ledger et ordre
doivent correspondre, avec un net positif. Corpus incomplet/incertain ou signature
révoquée : pas d'envoi ; reprise à délai croissant. Net nul : candidat annulé,
une restauration ultérieure ne le réarme pas. Une correction ne crée aucun candidat.
Une modification du texte ne remplace pas le texte déjà sélectionné ; désactiver
invalide les attentes par l'époque existante, sans réactivation silencieuse.

Ordre de capture : mutex d'attribution PF local puis transaction de réception,
mutex messagerie, identités/configuration/message. Aucune ligne de profil n'est
verrouillée avant le mutex messagerie. Aucun réseau dans cette transaction.

Ordre de livraison : gouvernance sessions, origine/acquittement primaire, corpus,
références/reçu immuables, mutex messagerie, identités/configuration/message.
La participation de la messagerie à la transaction ne fait ni START ni COMMIT ;
le résultat de file, le message et sa notification réussissent ou reculent ensemble.
Pas d'appel économique ni réseau dans cette livraison.

Le worker minute existant partage un budget maximal de20 décisions entre bienvenue
et remerciement ; dix au plus d'abord pour les remerciements afin de préserver le
traitement des bienvenues. Les nouveaux essais gardent le même événement et la
même clé de message. Un défaut de source n'expose aucun message ou donnée privée
dans le statut technique. Même sans source récupérable, l'expiration ordinaire
des textes en attente est contrôlée ; aucune durée PF réelle n'est introduite.

## Garde, vérifications et limites

Le garde physique Hub/Fans jetables est vérifié avant la factory locale typée
`faluss_fans_closed_pf_thanks_delivery` ; un marqueur ou flag copié ne l'ouvre pas
sur un site réel. Aucun nouveau format, permission ou endpoint Hub n'est créé.
La configuration Remerciement reste indisponible dans un environnement ordinaire.

La recette WordPress/MariaDB du head `956914f` réussit **921 contrôles, zéro échec**.
Elle couvre les nouveaux candidats, concurrence, réceptions et réponses perdues
après COMMIT, rollback message/résultat, attributions multiples, corrections,
litiges/résolutions sans nouveau message, blocage, désactivation, texte figé,
indépendance de la bienvenue et formulaires HTTP natifs (nonce, compte étranger,
aperçu, média refusé et édition concurrente). Il s'agit de preuves de ce lot,
distinctes de #236.

Les [preuves et six captures](../evidence/fans-pf-thanks-2026-10-18/README.md)
distinguent ces parcours HTTP sur de vrais WordPress jetables des **rendus de
composants** exécutés sous Windows avec modèles fictifs à 1 440, 390 et 320 px.
Ces captures ne prouvent ni une session WordPress active ni le SSO ; aucune
soumission navigateur sur WordPress n'est revendiquée. Les vues et leurs CSS
existants sont conservés, avec focus, police et absence de débordement vérifiés.

Le head final doit garder ses contrôles verts, puis être vérifié avec le main
conjoint. Aucune activation sur les sites, producteur réel, politique de
conservation PF ou preuve du stockage RustFS n'est ajoutée par ce travail.

# B6 — première livraison fermée depuis les faits rapprochés

## Reprise du 15 octobre 2026 — raccordement en cours

Les vues de classement permanent et de relevé mensuel sont préparées dans
`FansUiHof` et `FansUiHofView`, avec une feuille de styles limitée à `.fu-hof`.
Elles ne changent ni la navigation globale ni les accès existants. La demande
d'admission Créateur de l'espace Fan est conservée. Les dates de source restent
UTC et leur présentation utilise le fuseau WordPress ; les mois civils restent
explicitement Europe/Paris. Aucun graphe, portrait ou résultat de session n'est
inventé lorsque le contrat ne le fournit pas.

Le filtre PHP privé `faluss_fans_hof_closed_delivery` accepte seulement un
`ClosedRankingDelivery`, **après** validation de l'enclave physique. Il ne peut
pas activer un fournisseur sur un site ordinaire, même avec des constantes
copiées. Il n'accepte aucune identité ou quantité venant du navigateur. Les
lectures GET ne préparent pas de barrière, ne consomment pas de PF et ne modifient
pas les consentements. Un fournisseur absent, une preuve périmée ou une source
incomplète donne un état indisponible, jamais un zéro ou un rang estimé.

Le sélecteur de catégorie et les pages de vingt lignes sont rendus côté serveur.
Le relevé privé utilise seulement le membre SSO courant. La lecture des sessions
compose aussi les règles figées approuvées, le profil/publication de
l'organisateur et l'acquittement primaire correspondant exactement à la liaison
locale de cycle de vie. Elle ne démarre aucune requête réseau ni transaction
indépendante. L'ancien acquittement d'ouverture ne passe pas pendant une
fermeture locale ; une session arrivée à échéance attend sa clôture attestée,
sans être déclarée terminée par le navigateur. Une annulation attestée conserve
le classement corrigible sans vainqueur. La liste est paginée par clé de session,
vingt candidates par page ; les erreurs de preuve ferment la page, les retraits
éditoriaux et suspensions masquent la session. Les identifiants servent aux liens,
jamais aux libellés. Aucun retrait économique ne réécrit les points historiques.

Scénarios positifs/négatifs de ce raccordement : session approuvée ouverte via
HTTP Hub réel de recette, vide puis alimentée par la même consommation que les
classements permanents ; correction partielle commune ; lecture sans preuve
primaire refusée, suspension masquée, fermeture en cours refusée, annulation
attestée conservant les deux points nets. Le rendu des vrais DTO/templates est
exercé pour invité/Fan/Créateur, avec refus des relevés privés invité et admin
non lié, et du relevé Créateur pour un Fan sans profil. Ces scénarios ajoutés
attendent leur exécution avant d'être présentés comme des preuves acquises.

L'export facultatif `FALUSS_B6_UI_EXPORT=/var/tmp/fans-hof-ui-…` conserve uniquement
des replays HTML/assets des données fictives, destinés à la recette de mise en
page navigateur. Il ne prouve ni une authentification centrale Me ni l'hébergement
réel des routes. La recette utilise les mécanismes WordPress/MariaDB existants,
sans modification de préparation, cache, délais, matrice ou runners.

Les formulaires de gouvernance et les intentions serveur complètes restent à
raccorder. Ce travail reste en brouillon, sans annonce d'activation sur les sites.

La recette applicative complète existante `--b6-joined-integration` vérifie
désormais aussi le fournisseur, sans modifier ses arguments de CI, la préparation
d'environnement, les caches, les délais ou les runners. La première tentative
locale a échoué avant B6 sur l'assertion de perte de réponse du transport de corpus ;
sa cause n'est pas établie. Aucun succès réseau du fournisseur n'est revendiqué
sur la seule base de ses tests unitaires.

## État au point d'arrêt du 14 octobre 2026

Travail incomplet sauvegardé en brouillon. La suite locale passe : 722 tests,
7 544 assertions, PHPStan sans erreur ; deux dépréciations historiques restent.
La recette WordPress/MariaDB Hub/Fans de ce sous-lot est préparée mais n'a pas
encore été exécutée. Les scénarios ci-dessous sont à vérifier, pas des preuves
de fonctionnement obtenues. Aucune UI ni capture n'est encore livrée.
La recette concurrente d'identité de la PR parente #208 reste en échec sur
son point de synchronisation ; sa cause doit être établie avant intégration.

Périmètre serveur, depuis #208–#211. Aucun nouveau contrat Hub, API, flag,
migration de site ou opération économique. Garde physique obligatoire avant
SQL : aucun site ordinaire ne peut activer ce fournisseur par une constante.

La lecture de l'origine est distincte des actions d'ouverture administrateur.
`ClosedOriginOpening::withCurrentRead` exige un acquittement local déjà appliqué,
une relecture primaire explicitement confirmée, le même inbox/connexion, les
champs immuables, l'intervalle d'admission et l'état primaire/local actif.
Ouverture et installation restent protégées par leurs contrôles existants.

`ClosedRankingDelivery` compose sessions → origine/barrières → corpus/cache
dans l'unique transaction propriétaire. Le cache doit correspondre exactement
au corpus complet authentifié et corrigé de la même origine/politique. Une
source incomplète, clé révoquée, fermeture locale ou relecture en attente
refuse la livraison ; aucun cache ancien servi comme exact.

- Général/catégories persistants : noms approuvés et consentements relus par
  les contrats SSO/Profils propriétaires, IDs locaux distincts des Faluss ID.
  Sortie minimale nom/points/place visible, sans UUID, reçu ou départage privé.
- Relevé mensuel privé : membre courant déterminé par WordPress et son lien
  SSO primaire, sans paramètre de destinataire ; mois civil Europe/Paris,
  corrections au mois d'origine. Retrait du consentement public et suspension
  du profil ne suppriment pas les faits/relevés privés. Une absence de faits
  dans un corpus complet vaut zéro, pas un montant ou solde utilisable.
- Mois antérieur à l'ouverture ou futur par rapport au corpus attesté refusé.
  PC, packs non attribués et anciens PF non admissibles restent exclus.

La préparation/collecte/relecture HTTP et reconstruction sont explicites,
hors de la transaction et des mutex de livraison. `source_at` est l'instant
de fence du corpus, pas une promesse de fraîcheur future continue ou une
transaction distribuée entre les primaires.

Recette `--b6-current-delivery` : deux WordPress/MariaDB jetables, données,
achats, clés et identités fictifs. Ouverture réelle par le transport signé,
corpus complet vide/pack seul, une consommation officielle, filiation locale,
consentement/suspension, relevés privés, correction partielle/incomplète et
fermeture. Les comptes liés sont des fixtures, pas le véritable SSO Me.
L'horloge injectée du Hub est appliquée seulement à la connexion Fans de recette.

Limites : pas encore de livraison des sessions et de toutes leurs barrières
d'admission/Créateur/pays, d'intentions serveur complètes ni d'UI/captures.
Ce sous-lot ne rend pas les classements testables sur les sites. Retour arrière :
retirer ses futurs appelants et revert, sans modifier faits ou checkpoints.
#150 et RustFS #161 restent distincts ; aucune release/activation.

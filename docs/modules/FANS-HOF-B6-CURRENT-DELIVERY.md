# B6 — première livraison fermée depuis les faits rapprochés

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

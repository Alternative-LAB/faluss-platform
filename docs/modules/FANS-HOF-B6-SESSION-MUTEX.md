# B6 — ordre de verrouillage de la gouvernance

Contrat serveur du domaine HoF, sans API, schéma, cron, flag ou contrat Hub
supplémentaire. Préparatif de composition B6 depuis #208/#209, aucun classement
ou session ouverts sur site par ce lot.

La livraison doit tenir d'abord le mutex `sessions` déjà utilisé par la
gouvernance et ses quotas, puis l'origine/barrière et le corpus. Imbriquer
`SessionStore::transaction` dans la transaction de l'inbox provoquerait un
BEGIN/COMMIT distinct. `withGovernanceMutex` réutilise le même mutex de domaine
**sans démarrer de transaction** ; le propriétaire de barrière/corpus peut
ensuite tenir l'unique transaction dans son callback.

Cette primitive n'est ni une preuve Hub ni une permission de lecture. Le
fournisseur doit encore vérifier origine, sessions/participations, territoires,
corpus, filiation et visibilité. Les actions de gouvernance existantes gardent
leur transaction et leurs permissions. Pas de SQL d'un autre module dans HoF.

Le callback doit achever la transaction qu'il possède avant de rendre le
mutex. Une entrée déjà transactionnelle est refusée sans COMMIT ; un callback
laissant une transaction ouverte est refusé et rollbacké avant libération.
La libération est vérifiée, y compris après exception. Le mutex ne doit pas
être tenu pendant un appel HTTP ou une attente de navigateur.

Scénarios positifs : entrée sans BEGIN, transaction appelante avec rollback,
transaction de session existante réellement en attente du même mutex, puis
commit après libération. Négatifs : callback rejeté, transaction imbriquée,
transaction abandonnée et absence de schéma. Recette WordPress/MariaDB jetable
dans `--hof-b6-visibility`, comptes/données fictifs uniquement. La jonction
avec les acquittements et le corpus/cache sera validée dans la livraison B6
suivante ; aucune fraîcheur ou recette de site n'est déduite de ce verrou.

Le [rapport de branche](../evidence/fans-hof-b6-session-mutex/README.md)
réussit 126 contrôles, dont six nouveaux B6mx ; ressources supprimées.

Retour arrière : arrêter ses futurs appelants et revert, aucune donnée ou
table à supprimer. Ledger, claims, SSO et autres écrans inchangés. #150/#161
restent distincts ; aucune release pour ce seul préparatif.

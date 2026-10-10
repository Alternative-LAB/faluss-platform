# B6 — composition transactionnelle du corpus

## Contrat local fermé

La livraison doit confronter acquittements de barrières, cache dérivé et
visibilité courante dans une transaction locale. Les façades autonomes
`withAcknowledgement` et `withCurrent` ouvraient chacune leur transaction ; les
imbriquer est refusé. Une seconde transaction pourrait aussi commettre le
travail de son appelant implicitement dans MariaDB.

`ClosedCorpusInbox::withCurrentInTransaction` est une entrée de composition
**pour le code serveur de confiance**, pas un endpoint ou un acquittement Hub.
Elle exige l'enclave physique, la transaction active et la propriété du mutex
de barrière de la même origine/politique sur la même connexion SQL. Elle prend
ensuite le mutex du corpus, vérifie sa génération complète avec la confiance
Hub actuelle et appelle l'opération locale. Elle n'effectue aucun BEGIN,
COMMIT, ROLLBACK ni HTTP. Le code extérieur possède le rollback ; il ne doit
pas ignorer une exception et continuer une livraison partielle.

Ordre imposé : gouvernance B2 le cas échéant, mutex origine des barrières,
transaction du propriétaire, mutex origine du corpus, lignes du corpus/cache,
puis visibilité. Une entrée recursive ou autonome pendant cette composition
est refusée. Les verrous de lignes restent détenus jusqu'au COMMIT/ROLLBACK
extérieur, y compris après libération du mutex du corpus. La confiance et les
preuves sont vérifiées à chaque lecture ; posséder un mutex ne les remplace pas.

`ClosedRankingProjectionStore::withReadInTransaction` conserve l'égalité exacte
entre le cache et la reconstruction actuelle. Il livre son document **privé**
au callback serveur dans cette même portée. La lecture autonome et la
reconstruction existantes restent compatibles. Aucun reçu, quantité choisie,
ledger supplémentaire, contrat réseau, permission, schéma ou hook n'est ajouté.

## Scénarios de recette

Positifs : cache courant exact, transaction toujours active, rollback du
callback, COMMIT unique, résultat incertain récupérable, collecteur concurrent
réellement en attente du mutex puis reprise et nouvelle génération complète.
Négatifs : absence de transaction/mutex, mauvaise origine, imbrication,
exception du callback, clé Hub révoquée, cache altéré, rapprochement en cours
et génération non reconstruite. Les tests installent un témoin InnoDB privé
pour vérifier les écritures ; ils ne fabriquent pas d'acquittement Hub.

Cette primitive ne constitue pas la jonction complète B6 : l'appelant doit
encore authentifier les barrières courantes, examiner l'origine admise et les
participations, mapper les filiations SSO et appliquer la visibilité. Une
fence prouve son instant primaire, jamais une fraîcheur future garantie.
La recette utilise WordPress/MariaDB jetables fictifs ; aucun site ou vrai SSO.

Retour arrière : retirer l'entrée de composition après ses consommateurs,
sans effacer les preuves ou installer une migration. #150 et #161 distinctes.

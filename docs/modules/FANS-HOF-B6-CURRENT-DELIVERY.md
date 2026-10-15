# B6 — lectures et vues fermées depuis les faits rapprochés

## Périmètre du raccordement

`ClosedRankingDelivery` compose les trois objets approuvés : classements
persistants général/catégories avec familles Créateurs et Fans séparées,
relevés mensuels privés et sessions HoF. Il lit les faits Hub complets corrigés
via B4/B5 ; il ne crée ni attribution, crédit, débit, ledger Fans ou contrat Hub.

Les vues `FansUiHof` / `FansUiHofView` réutilisent les routes, conteneurs,
typographie et composants Fans. Leurs styles sont limités à `.fu-hof`. La
sidebar, les autres écrans et la matrice d'accès restent inchangés : HoF d'accueil
public, espaces et pages de détail personnels derrière les contrôles existants.
La demande d'admission Créateur dans l'espace Fan reste accessible.

**Cette livraison est exclusivement une recette fermée.** Le filtre PHP privé
`faluss_fans_hof_closed_delivery` accepte seulement un `ClosedRankingDelivery`,
après validation de l'enclave physique. Copier des constantes ou activer un
flag ne permet pas de l'ouvrir sur un site ordinaire. Aucun score n'est annoncé
testable sur les sites par la fusion ou la publication de ce code.

## Garanties de lecture

Ordre de composition : mutex des sessions, transaction propriétaire de l'origine
et des barrières, corpus/cache authentifié, puis visibilité. L'accueil compose
classement et sessions dans cette même transaction ; il ne mélange pas deux
générations de source. Aucun HTTP, BEGIN imbriqué ou COMMIT indépendant dans la
livraison. Un rollback de l'appelant conserve les garanties propriétaires.

`ClosedOriginOpening::withCurrentRead` est distinct des actions administrateur
d'ouverture. Il exige une origine appliquée, le même inbox/connexion, ses champs
immuables et une relecture primaire confirmée. Le cache doit correspondre au
corpus complet courant de cette origine et de cette politique. Source incomplète,
preuve périmée, clé révoquée ou fermeture en cours : indisponible, jamais ancien
score présenté comme exact, zéro supposé ou rang estimé.

- Classements : filiation SSO locale, statut/publication approuvés et consentement
  relus par les contrats propriétaires. Sortie nom/points/place, sans UUID,
  reçu, date d'atteinte privée ni information financière. Les places visibles
  restent successives sans révéler les identités masquées.
- Relevé : seul le membre WordPress courant lié à Identity, aucun paramètre de
  destinataire. Mois civil Europe/Paris avec été/hiver et corrections dans le
  mois d'origine. Mois antérieur à l'origine ou futur refusé. Dans un corpus
  complet, absence de contribution = zéro ; achat seul, PC et anciens PF non
  admissibles exclus. Retrait de visibilité publique et suspension ne détruisent
  pas les faits ni le relevé privé autorisé.
- Sessions : règles figées approuvées, organisateur actif avec présentation
  approuvée, liaison locale de cycle de vie et acquittement primaire exact.
  Ancienne preuve d'ouverture refusée pendant fermeture ; échéance passée sans
  acquittement de clôture non présentée comme terminée. Annulation attestée :
  historique corrigible conservé, aucun vainqueur. Retraits et suspensions
  masquent la session, sans réécriture des contributions historiques.

Les pages de classement comportent vingt lignes ; les sessions utilisent une
pagination par clé de vingt candidates, y compris lorsque des candidates sont
masquées. Catégories, mois et pages fonctionnent par GET sans JavaScript. Les
lectures ne préparent aucune barrière, ne consomment rien et ne modifient aucun
consentement. Les instants techniques restent UTC, la présentation indique le
fuseau WordPress ; le mois métier reste explicitement Europe/Paris. Les entiers
ne passent jamais par une conversion flottante.

## Recette et limites des preuves

La recette applicative complète existante `--b6-joined-integration` inclut ce
fournisseur et les vraies routes WordPress. `--b6-current-delivery` permet la
même recette locale explicite. Aucun workflow, mécanisme de préparation,
cache, délai, matrice, runner ou contrôle requis n'est modifié.

Scénarios : origine ouverte par transport signé ; corpus vide et pack seul ; une
consommation alimentant Créateur/Fan/session une seule fois ; correction partielle
commune ; source incomplète indisponible ; retrait de consentement, suspension et
réadmission ; annulation ; fermeture ; rollback. De vrais cookies WordPress
distincts exercent les routes invité/Fan/Créateur/admin non lié, les refus et les
cookies expirés ou altérés. Comptes liés, achats, clés et données sont fictifs :
ces preuves ne sont pas celles du véritable SSO central Me.

La capture navigateur facultative s'exécute pendant que cette même paire jetable
est vivante, sur les routes réelles. L'adaptateur de recette HTTPS/HTTP loopback
reste dans `tests/`, après garde physique ; il ne change pas les permissions.
Les exports HTML/assets de `FALUSS_B6_UI_EXPORT` sont des replays distincts et
ne constituent pas une preuve d'authentification ou d'hébergement cible. Le
serveur, les capacités temporaires et la paire sont nettoyés en `finally`.

Les résultats exacts, SHA et captures de la recette finale sont consignés dans
les [preuves de branche](../evidence/fans-hof-b6-current-delivery/README.md) :
769/769 contrôles conjoints et48/48 cas navigateur réussis. Un résultat de branche n'est jamais présenté comme une
vérification du futur `main`. Les premiers échecs ont permis de corriger les
scénarios d'horloge/admission et le débordement à 320 px ; ils ne sont pas des
succès. L'échec antérieur B3beh, disparu sur un rejeu inchangé, reste sans cause
établie et a été signalé à Jérémie, sans modification d'infrastructure.

## Écarts V2 et raccordements suivants

Les trois objets ont leurs lectures et vues, sans photographie, graphe, rang,
statistique ou session inventés. Le contrat de session ne fournit pas de média
public approuvé : traitement graphique sobre et pictogramme. Les lignes de
classement affichent seulement les noms approuvés disponibles, sans portrait
supposé. Pas de variation de score inventée ni de badge/récompense irréversible.

Les formulaires de gouvernance, d'alias/consentement et les intentions serveur
complètes restent à raccorder aux services existants. Collecte/relecture primaire
et reconstruction restent explicites hors des transactions de livraison ;
`source_at` est la fence du corpus, pas une promesse de fraîcheur continue.
Producteur d'achat, admission réelle, vrai SSO, automatisation de production,
référentiel territorial et pays autorisés restent des portes distinctes.
Conservation #150 et RustFS #161 ne sont pas débloqués par cette recette.

Retour arrière : retirer les appelants et revert du lot, sans modifier faits,
reçus, schémas ou checkpoints. Aucun site, flag de production, migration de
production, achat ou opération économique réelle dans ce lot.

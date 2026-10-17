# B6 — visibilité territoriale par dimension, recette fermée

## Périmètre et règles

Ce lot raccorde les lectures actuelles aux admissions territoriales de B2 et aux
checkpoints de révocation de B6. Aucun contrat Hub, écriture économique, schéma,
endpoint, flag, migration automatique ou composant visuel n'est ajouté.
La nouvelle direction visuelle de l'accueil HoF et des catégories est reportée
à un lot ultérieur à la demande explicite du porteur ; l'intérieur attend aussi
sa référence. Les vues actuelles sont conservées.

Une session locale/nationale diffuse une ligne Créateur uniquement si le compte,
le profil, la présentation et le consentement de classement restent admissibles,
et si le territoire principal courant est approuvé selon la politique figée de
la session. Le pays doit correspondre ; une session locale exige aussi le même
territoire. Un nouveau dépôt, rejet, retrait ou révocation masque immédiatement
la ligne de cette dimension. Les places visibles sont recomposées sans exposer
le rang masqué, l'identité technique ou les dates de départage.

Ce contrôle ne s'applique pas au Fan : les pays autorisés du contrat d'attribution
restent contrôlés à la source, sans restriction liée au territoire du Créateur.
Les persistants général/catégories, les relevés privés mensuels et les sessions
internationales ne sont pas masqués par la seule perte d'admission territoriale.
Leurs autres contrôles de visibilité restent applicables.

L'organisateur propriétaire doit rester admissible pour diffuser les métadonnées
de sa session territoriale, y compris une session historique annulée/terminée.
Un coorganisateur accepté ne diffuse son nom que s'il reste admissible pour
cette portée ; sa fermeture opérationnelle suit le checkpoint existant, sans
lui donner de droit propriétaire. Aucune fermeture Hub n'est inventée pendant
une lecture. Un défaut de stockage, de preuve ou de schéma fait échouer la
lecture affectée ; il n'est pas converti en tableau vide présenté comme exact.

## Atomicité, corrections et reprise

Les contrôles utilisent le primaire, dans la transaction et le mutex de
gouvernance existants : sessions, origine, corpus courant complet, identité et
admission territoriale. Aucun réseau, COMMIT imbriqué ou mutation dans un GET.
Seule l'inadmissibilité métier est transformée en exclusion ; les erreurs
techniques remontent. La lecture internationale ne dépend pas du schéma
territorial.

Le corpus et les faits ne changent pas lors d'un masquage. Les corrections Hub
continuent de s'appliquer aux persistants et à toutes les sessions, même masquées
ou closes. Après nouvel examen favorable, l'affichage historique est reconstruit
depuis le net corrigé courant. Cela ne réactive pas l'ancien binding d'admission :
les nouvelles contributions exigent sa fermeture acquittée et la réadmission
future du pont existant. Aucun point annulé ou avantage d'ancienneté n'est restauré.

## Scénarios et preuves

Positifs : une consommation officielle fictive alimente trois sessions réellement
ouvertes et admises (locale, nationale, internationale) ; Créateurs et Fans
partagent le même fait, sans seconde consommation. Réadmission revue : seul le
score corrigé redevient visible.

Négatifs : révocation du participant, correction partielle pendant le masquage,
schéma territorial indisponible, perte d'admission de l'organisateur d'une session
historique. Vérifier l'absence d'effet sur les persistants, le relevé privé,
l'international, les deux ledgers et la quantité de reçus.

Les résultats de cette nouvelle recette doivent être consignés après exécution ;
aucune preuve de branche précédente ne les remplace. Le registre courant unique
est [#147](https://github.com/Alternative-LAB/faluss-platform/issues/147).
Ce lot ne rend aucun score utilisable sur les sites. #150 et #161 restent distinctes.

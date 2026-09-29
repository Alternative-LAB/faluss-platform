# Création et gestion des textes Fans

## Scénarios à vérifier

Positifs : créateur lié actif, liste privée paginée, création pending avec clé
unique, rejeu du même POST sans doublon, lecture privée avant édition, édition
avec révision courante, retrait explicitement confirmé. Profil suspendu : lecture
et retrait conservés, nouvelle création/édition refusées par le serveur.

Négatifs : invité/Fan sans profil/admin non lié, module fermé, nonce absent/faux,
UUID étranger, révision périmée, quota, requête invalide, stockage indisponible.
Aucun de ces refus ne doit être présenté comme une réussite. Pas d’HTML rendu,
d’identifiant propriétaire choisi par le client ni de contournement REST.

## Contrat UI

Formulaires POST natifs sur la route Créer, nonce propre à l’UI puis appel REST
interne avec nonce WordPress de la session. Les permissions, états, modération,
quotas, verrouillage et journal restent ceux de [FANS-PUBLICATIONS.md](FANS-PUBLICATIONS.md).
Services, prestations, produits, médias et transactions restent fermés.

La clé UUID de création est générée par le serveur et reste dans le formulaire
après erreur. Le navigateur peut renvoyer le même POST après réponse perdue ;
le moteur retrouve son résultat. Une réponse de succès affiche l’état retourné
(y compris un état plus récent lors d’un rejeu), jamais une publication automatique.
Une nouvelle intention exige le lien « Nouveau texte ». Pas de brouillon local,
de stockage navigateur du texte ou de sauvegarde avant envoi. Fermer/recharger
une page GET peut perdre une saisie ; après réponse incertaine, vérifier la liste
ou renvoyer le même POST avant de créer une nouvelle intention.

Une édition/retrait utilise la révision lue. Après conflit 409, relire la version
actuelle ; aucun écrasement automatique. Le retrait est terminal et purge le texte
selon le moteur, d’où une confirmation explicite. Les textes sont affichés échappés.
Les formulaires et la pagination fonctionnent sans JavaScript.

## Limites

Recette de code et navigateur isolée uniquement ; aucun site WordPress consulté.
Validation cible, droits réels et modération humaine à organiser avant activation.
Le lot n’installe aucun schéma, ne modifie aucun flag et n’ouvre aucune capacité Hub.

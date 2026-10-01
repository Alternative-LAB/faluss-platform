# Identité éditoriale des créateurs Fans

Lot de développement autorisé après #105, sans échéance ni suivi programmé.
Le nom public, la bio et le portrait sont des contenus Fans ; Faluss Identity
reste l’autorité du compte. Aucun nom n’est déduit du Faluss ID ou de l’e-mail.

## Contrat implémenté

- Propriétaire lié avec profil actif : soumettre un nom (1–80 caractères), une
  bio facultative (1 000 caractères maximum) et un portrait facultatif provenant
  de ses images approuvées. Aucun HTML, URL de média externe ou champ de compte.
- Une proposition courante et au plus une version approuvée distincte. Modifier
  conserve publiquement la dernière version approuvée jusqu'à décision ; aucune
  donnée de la proposition n'est diffusée. Une révision périmée échoue.
- Administrateur : lire la file privée, examiner nom/bio/portrait dans le panel
  existant, approuver ou refuser avec motif et révision exacts. L’approbation
  éditoriale ne constitue ni vérification d’identité ni permission commerciale.
- Explorer et profil public : champs approuvés uniquement ; portrait dérivé
  contrôlé sous le flag existant de diffusion, jamais les octets de quarantaine.
- Refuser une proposition efface ses champs et garde la dernière version approuvée.
  Refuser la version approuvée courante, révoquer explicitement ou retirer côté
  propriétaire efface immédiatement les deux versions. La révocation est possible
  pendant une proposition ou après son refus, avec la révision courante exacte.
  Le propriétaire peut retirer après refus même si les champs proposés sont vides.
  Seule la trace technique de décision subsiste. Les octets
  d’Images restent gérés par son propre cycle de retrait ; aucune copie créée.
- Suspension : aucune lecture publique ou nouvelle soumission ; le propriétaire
  garde la possibilité de retirer. Aucun ancien lien ne contourne l’état courant.
- Négatifs : invité, compte non lié, autre propriétaire, nonce absent, auto-
  approbation, portrait étranger/pending/retiré/révision périmée, faute SQL,
  décision rejouée ou conflit concurrent ; aucune diffusion avant approbation.

## Livraison et limites

Opt-in serveur `FALUSS_PLATFORM_FANS_EDITORIAL`, fermé par défaut. Trois tables
privées additionnelles, installées uniquement par l’activation contrôlée du
module profils sur Fans ; aucun changement de la table des comptes/profils v1.
Les routes n’installent aucun schéma. Fermer l’opt-in coupe toutes les lectures
et écritures éditoriales sans supprimer de données ni affecter les profils v1.

Preuves : [SQL réel et parcours navigateur isolé](../evidence/fans-editorial/README.md).
Aucun accès à un site WordPress ou serveur existant ; la recette cible reste
à la charge du propriétaire. Le dépôt d’images et la galerie complète restent
le prochain lot. Sans image approuvée, un nom et une bio peuvent être soumis.

## API et données

Toutes les routes sont sous `faluss-fans/v1`. Les routes personnelles/admin
exigent un nonce WordPress et la permission correspondante. Les lectures et
réponses de ce lot sont `private, no-store` ; aucune persistance navigateur.

| Route | Accès et fonction |
| --- | --- |
| `GET/POST creators/me/editorial` | Propriétaire lié ; lire/soumettre avec révision, `public_name`, `bio`, `portrait_id`, `portrait_revision` |
| `POST editorial/{id}/withdraw` | Propriétaire seulement ; révision courante, purge des champs |
| `GET editorial/moderation?state=…&cursor=…` | Admin ; états fermés, 20 fiches par page, curseur UUID |
| `GET editorial/{id}/private` | Admin ; proposition, version approuvée nullable `published`, 100 dernières traces sans historique du contenu |
| `POST editorial/{id}/moderate` | Admin ; révision, décision approve/reject/revoke et motif fermé |
| `GET creators`, `GET creators/{id}` | Public ; ajout compatible du champ nullable `editorial`, jamais les champs en attente |
| `GET creators/{id}/portrait/{revision}` | Public ; révision éditoriale exacte, profil actif, image approuvée exacte et opt-in de diffusion ; JPEG frais ≤ 2 Mio |
| `GET images/portraits` | Propriétaire ; ses cinq images approuvées maximum, sans être masquées par les anciens retraits |
| `GET images/{id}/preview/{revision}` | Propriétaire ; révision exacte, profil actif, pending/approved ; aperçu JPEG privé sans ouvrir la quarantaine |

Les motifs sont `allowed_editorial`, `needs_revision`, `prohibited_content` ou
`creator_withdrawal`. Vingt soumissions maximum par créateur et par heure.
Le journal conserve acteur local, action, motif, révision et date ; aucun nom,
bio ou copie d’image. Aucune suppression automatique de ce journal n’est
introduite. Aucun historique des versions remplacées n'est créé.

## Activation, compatibilité, retour arrière

`CreatorProfilesModule::activate()` vérifie le schéma profils v1 puis installe
les tables éditoriales uniquement si l’opt-in strict est ouvert sur Fans.
Pour un stockage éditorial v1 déjà installé, `init` prépare le schéma éditorial v2
avant les transactions métier : table `faluss_fans_editorial_approved` de même
structure que la proposition, copie des seules lignes effectivement approuvées,
révision et portrait préservés. Verrous de schéma puis de domaine, création
additive idempotente, aucune diffusion en cas de schéma incomplet. Les anciennes
versions écrasées par une proposition avant cette migration ne sont pas
reconstructibles et ne sont jamais inventées. Aucun nouveau flag ni cron.

L’opt-in éditorial est indépendant de la diffusion d’images : nom/bio sans
portrait fonctionnent sans Images. Le sélecteur utilise le contrat public Images,
sans lire ses tables directement. Fermer l’opt-in ou revenir au code précédent
laisse les trois tables intactes et supprime la projection éditoriale (version
de schéma non reconnue par le code v1) ; les profils
structurés restent compatibles. Aucun cron ni dépendance runtime supplémentaire.

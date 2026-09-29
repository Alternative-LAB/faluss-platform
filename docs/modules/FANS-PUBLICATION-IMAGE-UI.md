# Lecture des images de publications — UI opt-in

## Scénarios et périmètre

- Positif : invité, Fan lié, Créateur lié ou administrateur lit un texte public,
  demande son image et reçoit un JPEG dérivé autorisé à la révision exacte.
- Négatif : flag fermé, absence d’association, retrait ou révision obsolète,
  génération occupée, réponse non JPEG, trop volumineuse ou non décodable : aucun
  visuel de remplacement, aucun accès à la quarantaine.
- Course : changement de page, masquage de l’onglet, nouvelle pagination ou
  rafraîchissement annulent les requêtes et ignorent les réponses anciennes.

L’UI utilise uniquement le [contrat existant de diffusion](FANS-IMAGE-DELIVERY.md).
Elle ne modifie ni la modération, ni la projection publique des textes, ni les
prérequis d’hébergement, ni les flags. Version plugin inchangée.

## Parcours

Explorer et les accueils présentent le bouton « Vérifier l’image associée »
uniquement lorsque `ImageDisplayDerivative::enabled()` est vrai. La liste publique
ne contient aucune information de présence d’image : le bouton ne prétend donc
pas qu’une image existe. Aucune requête d’image automatique au chargement.

Chaque clic interroge la révision du texte chargé, sur la même origine, sans
redirection, query string, cache applicatif ou variante de format. Un seul clic
peut charger une image à la fois dans la page ; les autres boutons sont désactivés
pendant cette demande. Cela ne remplace pas la protection serveur entre visiteurs.
Un 503 permet une nouvelle tentative explicite, sans boucle de rejeu.

Le corps JPEG est borné à 2 Mio pendant sa lecture. Le navigateur décode l’image
avant affichage et vérifie le côté maximal de 1 280 pixels. L’URL Blob est révoquée
lors du remplacement de liste, du masquage, du départ ou d’une erreur. Au retour,
les textes sont relus et une nouvelle demande explicite est nécessaire pour
l’image. Aucun stockage persistant, CDN, URL privée ou source originale.

Ces contrôles ne rappellent pas les octets déjà reçus ou copiés. Une révocation
serveur pendant que la page reste visible ne retire pas immédiatement ses pixels.
Il n’y a ni polling ni promesse de DRM.

## Limites d’activation

Ce visuel est **l’image d’une publication**, jamais un portrait ou une identité
inventée. Le contrat ne fournit pas de description alternative éditoriale : le
libellé accessible annonce cette limite sans inventer le contenu de l’image.
Une description approuvée reste nécessaire pour une expérience accessible complète.
Les exigences de consentement, modération, signalement, conservation et hébergement
du contrat de diffusion demeurent à résoudre avant ouverture en production.

La création, l’association et le retrait d’images depuis l’UI feront l’objet d’un
lot distinct ; leurs API existantes ne sont pas activées par cet affichage.

## Preuves

`tests/Fans/Ui/recipe/images.cjs` vérifie les scénarios ci-dessus dans le navigateur
avec adaptateurs PHP/REST isolés et un JPEG géométrique explicitement marqué fixture.
[Captures et résultats](../evidence/fans-48h/lot-7/README.md).
Ce n’est pas une recette WordPress, Elementor, stockage privé ou SSO réel.

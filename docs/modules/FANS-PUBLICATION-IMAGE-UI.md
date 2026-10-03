# Lecture des images de publications — UI opt-in

## Scénarios et périmètre

- Positif : invité, Fan lié, Créateur lié ou administrateur lit un texte public,
  demande son image et reçoit un JPEG dérivé autorisé à la révision exacte.
- Négatif : flag fermé, absence d’association, retrait ou révision obsolète,
  génération occupée, réponse non JPEG, trop volumineuse ou non décodable : aucun
  visuel de remplacement, aucun accès à la quarantaine.
- Course : changement de page, masquage de l’onglet, nouvelle pagination ou
  rafraîchissement annulent les requêtes et ignorent les réponses anciennes.
- Clavier : Entrée charge puis masque l’image sans perdre le focus. Pendant
  une demande, les boutons annoncent leur indisponibilité sans quitter l’ordre
  de tabulation ; le garde JavaScript refuse tout second lancement.

L’UI utilise uniquement le [contrat existant de diffusion](FANS-IMAGE-DELIVERY.md).
Elle ne modifie ni la modération, ni les prérequis d’hébergement, ni les flags.
La lecture du profil public utilise une projection additive décrite ci-dessous. Version plugin inchangée.

## Parcours

Les accueils gardent « Vérifier l'image associée » et leur demande explicite,
uniquement lorsque `ImageDisplayDerivative::enabled()` est vrai.
Le profil public recomposé demande `public_image=1` avec `creator_id` : le serveur
ajoute `has_public_image`, sans référence privée, après vérification du texte,
du profil, de l'association, de l'image approuvée à sa révision et du stockage
attesté. La projection par défaut reste identique.

Sur ce profil uniquement, un texte seul n'a ni bouton ni requête d'image. Les
images admissibles sont chargées séquentiellement au premier affichage, sans
rejeu automatique ; le bouton permet de masquer puis de revoir l'image. La
livraison revalide les droits, et un 404 retire le contrôle devenu sans objet.
Voir [la recette réelle et le contrat précis](../evidence/fans-discovery/README.md).

Chaque chargement interroge la révision du texte chargé, sur la même origine, sans
redirection, query string, cache applicatif ou variante de format. Un seul clic
peut charger une image à la fois dans la page ; les autres boutons sont désactivés
pendant cette demande. Cela ne remplace pas la protection serveur entre visiteurs.
Un 503 permet une nouvelle tentative explicite, sans boucle de rejeu.

Le corps JPEG est borné à 2 Mio pendant sa lecture. Le navigateur décode l’image
avant affichage et vérifie le côté maximal de 1 280 pixels. L’URL Blob est révoquée
lors du remplacement de liste, du masquage, du départ ou d’une erreur. Au retour,
les textes sont relus ; le profil recharge seulement les images encore
admissibles, les autres écrans attendent une demande explicite. Aucun stockage persistant, CDN, URL privée ou source originale.

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

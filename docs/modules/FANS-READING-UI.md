# Lecture Fans — accueil, profil propre et publications

## Scénarios du lot

Positifs : accueil Fan et Créateur avec liens de navigation réels ; profil propre
avec catégorie et statut issus du service propriétaire ; lien public seulement
si actif. Explorer et accueil lisent les pages de textes approuvés via le REST
existant, avec curseur et lien vers le profil public. Aucun nom, portrait ou score
n’est déduit d’un UUID ou de l’identité SSO.

Négatifs : invité/admin non lié refusés sur le profil propre ; profil en attente
ou suspendu sans lien public ; module texte absent, erreur réseau, réponse mal
formée et page vide affichent un état explicite. Le texte est rendu comme texte,
jamais en HTML. Changement de visibilité/retour navigateur recharge la première
page et masque les anciennes réponses ; une réponse dépassée ne restaure rien.

La liste publique est globale, pas un fil personnalisé ni une liste filtrée par
créateur. Le contrat actuel n’offre pas de filtre créateur ; le profil public ne
doit pas prétendre afficher toutes ses publications. Aucune nouvelle API, aucun
changement de droits, modération, schéma, média ou flag dans ce lot.

## Limites

Le service REST relit la visibilité et la modération à chaque page. Un contenu
retiré après sa lecture ne peut pas être rappelé instantanément sans nouvelle
requête. L’UI propose de recommencer le parcours et rafraîchit au retour visible ;
aucun polling ni stockage local n’est ajouté. La recette utilise des fixtures
identifiées comme telles ; aucune donnée fictive n’entre dans le produit.

La mise à jour des nom/portrait, les statistiques sociales, les médias et les
classements restent indisponibles. La publication/édition de texte est le lot
suivant. Validation WordPress/Elementor/SSO cible à effectuer par le propriétaire
avant activation ; aucun accès aux sites dans ce chantier.

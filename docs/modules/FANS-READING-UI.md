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

Explorer et les accueils gardent une liste globale, pas un fil personnalisé.
Le profil public utilise maintenant le filtre serveur ajouté par #96 : chaque
page est liée à son `creator_id`, et le navigateur refuse toute réponse contenant
un texte d’un autre créateur. Aucun identifiant n’est affiché comme nom.

Scénarios du raccordement : Explorer → fiche → publications de ce profil, puis
page suivante ; invité, Fan lié, Créateur lié et administrateur. Un profil sans
texte public affiche une liste vide. Une erreur ou une réponse d’un autre auteur
ne réutilise pas le contenu précédent. Les profils absents/suspendus/retirés
restent des pages HTTP 404 sans section ni appel de publications.
Les images éventuelles utilisent le même [parcours à la demande](FANS-PUBLICATION-IMAGE-UI.md),
sans portrait inventé ni changement de modération, schéma ou flag.

## Limites

Le service REST relit la visibilité et la modération à chaque page. Un contenu
retiré après sa lecture ne peut pas être rappelé instantanément sans nouvelle
requête. L’UI propose de recommencer le parcours et rafraîchit au retour visible ;
aucun polling ni stockage local n’est ajouté. La recette utilise des fixtures
identifiées comme telles ; aucune donnée fictive n’entre dans le produit.

La mise à jour des nom/portrait, les statistiques sociales et les classements
restent indisponibles. La [gestion des textes](FANS-AUTHOR-UI.md) et la lecture des
images ont leurs preuves séparées. Validation WordPress/Elementor/SSO cible à effectuer par le propriétaire
avant activation ; aucun accès aux sites dans ce chantier.

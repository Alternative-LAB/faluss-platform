# Associer une image à un texte — scénarios et contrat UI

Scénario positif : depuis Créer, ouvrir un texte enregistré, examiner une image
approuvée personnelle, confirmer son association, retrouver le texte pending,
faire examiner le couple texte/image dans le panel, puis lire son dérivé public.
Détacher repasse aussi le texte en modération. Retirer/rejeter l’image révoque
immédiatement son usage lors de la lecture suivante, sans effacer le texte.

Scénarios négatifs : invité, Fan sans profil, autre propriétaire, faux nonce,
absence de confirmation, image pending/refusée/retirée/étrangère, révision texte
ou image périmée, profil suspendu, module Images fermé, erreur SQL. Aucun octet
ni texte pending ne doit devenir public. Une mutation ne change jamais deux
formulaires ; aucune publication implicite ou réussite optimiste.

L’UI appelle exclusivement le contrat existant
[image–publication](FANS-PUBLICATION-IMAGES.md). Aucune nouvelle règle, table,
route REST, quota, permission, rétention, dépendance ou activation. Après réponse
incertaine, relire la fiche : une ancienne révision reçoit 409. Le choix « Sans
image » est explicite ; aucun champ manquant n’est interprété comme un détachement.

Le nom de fichier et les identifiants restent techniques. Les images proposées
sont des aperçus propriétaires approuvés ; la référence est vérifiée de nouveau
par le serveur au moment de l’association. L’édition de légende et la description
alternative détaillée ne sont pas ajoutées par ce lot ; la limite d’accessibilité
publique du contrat de diffusion existant reste à traiter avant activation.

## Livraison et retour arrière

Formulaire natif séparé dans `creator/creer?publication=<id>`, nonce propriétaire,
radio sans choix implicite, confirmation de nouvelle modération. Liste des images
approuvées via le contrat Images utilisé pour les portraits, sans lecture de table
par l’UI. Après succès ou conflit, la liste privée est relue et un lien rouvre
la version courante. L’édition texte et l’association ne sont jamais fusionnées
en deux mutations silencieuses dans un POST.

Aucune modification des moteurs Publications/Images ni des flags. Le retour
arrière consiste à revert cet adaptateur UI ; les références déjà enregistrées
restent gérées par le contrat serveur existant. Les preuves utilisent des services
réels et une base/fichiers jetables, avec adaptateurs WordPress ; la recette cible
reste au propriétaire avant activation.

[Captures et résultats](../evidence/fans-author-image/README.md).

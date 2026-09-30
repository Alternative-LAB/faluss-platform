# Galerie privée Fans — dépôt et gestion propriétaire

Suite du lot éditorial #106. Raccorder les services Images existants au parcours
Créateur → Créer : dépôt natif JPEG/PNG, liste privée avec date/état, aperçu
propriétaire contrôlé, retrait explicite et accès au choix de portrait.

Positifs : propriétaire actif, dépôt pending, aperçu privé avant approbation,
modération puis sélection du portrait. Répéter le même fichier normalisé encore
conservé doit retrouver la même image, sans quota consommé ni nouvelle décision.
Un retrait coupe l’aperçu et les portraits qui le référencent ; aucune copie
d’image n’est placée dans la médiathèque. Les formulaires fonctionnent sans JS,
hors l’aperçu enrichi. Le propriétaire suspendu peut consulter les états et retirer.

Négatifs : invité, Fan sans profil, compte non lié, administrateur non propriétaire,
nonce absent, image étrangère, révision périmée, faux format/SVG, fichier tronqué
ou trop gros, quota, stockage absent, faute SQL. Aucun nom de fichier source ou
UUID affiché à la place d’une description. Après erreur incertaine, relire la
galerie avant de renvoyer ; aucune réussite optimiste. Séparer les POST texte
et image pour qu’un formulaire ne déclenche jamais deux mutations.

Pas de nouveau flag ni schéma, pas d’activation, de site WordPress ou de release
dans la recette. La configuration de stockage et les limites amont restent
celles de [FANS-IMAGES.md](FANS-IMAGES.md). Les autres offres restent fermées.

## Parcours livré

`creator/creer#fu-images` : formulaire multipart natif, galerie des images
conservées (pending/approved), onglet des retraits/refus, pagination 20 éléments,
date UTC, aperçu JPEG privé à la demande et retrait confirmé par case à cocher.
Le lien Mon profil rejoint la sélection du portrait approuvé du lot #106.
Un changement d’image impose deux revues distinctes : l’image puis sa présentation
éditoriale. Aucun original, nom de fichier ou UUID visible n’est utilisé comme
identité ou légende. Les identifiants restent uniquement techniques.

Nonce UI `fans_images`, puis requête REST interne avec nonce de session ; pas de
permission supplémentaire accordée par l’UI. Un POST contenant simultanément une
action texte et une action image est refusé avant toute mutation. Les fichiers
dépassant `post_max_size` sont signalés sans prétendre qu’ils ont été reçus.
Les erreurs 409/413/415/429/503 sont conservées par la page. Un retrait dont le
nettoyage échoue indique la révocation enregistrée et l’intervention nécessaire.
La galerie est relue après chaque POST, sans état optimiste ni stockage navigateur.
Les aperçus et formulaires privés sont effacés au `pagehide`.

## Livraison, configuration et preuves

Pas de nouvelle table, dépendance runtime, cron ni changement de flag. Réutilise
Images, profils, SSO, UI et la modération existante. La diffusion publique du
portrait conserve le flag et le contrôle spécifiques de #106. Me et Hub restent
exclus des gardes Fans. Le stockage POSIX privé et son attestation d’hébergement
restent obligatoires ; le lot ne les configure pas automatiquement.

Retour arrière : revert du code UI et de la déduplication, ou fermeture contrôlée
de l’opt-in Images par l’exploitant. Aucun effacement automatique des données.
Rétention des images et journal : arbitrages du contrat Images, non inventés ici.

Preuves : [recette isolée et captures](../evidence/fans-private-images/README.md).
Les services, transactions, fichiers, requêtes multipart et formulaires sont réels ;
les primitives WordPress et sessions sont adaptées pour le test. Ce n’est pas une
preuve de recette WordPress, Elementor, SSO réel ou de sécurité de l’hébergement cible.

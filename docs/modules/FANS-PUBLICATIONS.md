# Publications Fans — fondation de politique v1

## Implémenté dans ce lot

Deux classes pures, sans enregistrement dans le Master Plugin :
`PublicationAccessPolicy` décide si une publication déjà approuvée est éligible
à l'accès à son original ; `TeaserProjection` construit une projection publique
bornée à cinq sélections explicites du même créateur actif, avec révision et
expiration de cinq minutes. Les scénarios positifs et négatifs couvrent gratuit,
verrouillé sans/avec droit, suspension, retrait, modération, catégorie interdite,
consentement absent, sélection étrangère, doublon, six éléments et champs privés.

Ce sont des fondations testées, **pas un moteur de publications opérationnel**.
Aucun module, flag, hook, schéma, upload, route, shortcode ou rendu n'est ajouté.
Les URL produites désignent des chemins réservés, actuellement non enregistrés.
Ni Me ni un navigateur ne consomme cette projection pour le moment.

## Frontière de confiance et contrat

Les paramètres de politique doivent provenir du futur dépôt propriétaire Fans
et du résolveur de droits Fans, jamais du JSON reçu d'un client. La classe pure
ne prouve pas un droit ni une modération. Un booléen `true` envoyé par le navigateur
ne peut pas être adapté directement à ces arguments. Les adaptateurs serveur
devront relire propriétaire, version, état et droit lors de chaque accès.

`originalAllowed` accepte seulement un créateur actif, une publication `published`,
une décision `approved`, la catégorie `hosted_allowed_content` et un accès `free`
ou `locked` avec droit courant Fans. SSO, follow, abonnement affiché ou approbation
du profil ne constituent pas ce droit. Toute valeur inconnue échoue fermée.

`fans.creator-teasers` / `1.0.0` contient uniquement identifiant public créateur,
révision, dates UTC et liste des `teaser_id`, libellés, URL publiques d'aperçu et
liens canoniques Fans. Une liste vide retire tous les teasers ; une liste invalide
refuse toute projection. Le serveur exige deux décisions distinctes : sélection
du créateur et approbation de l'aperçu public. L'original peut rester verrouillé ;
aucune URL, clé de fichier ou permission de cet original n'est projetée.

L'identifiant `public_preview_id` appartient à un dérivé public séparément approuvé,
jamais au stockage original. Il est distinct du `publication_id`. Les chemins sont
construits sur l'origine Fans fixe : aucune URL fournie par le client n'est relayée.
Les champs supplémentaires privés de l'instantané propriétaire ne sont pas copiés.
Le consommateur devra encore échapper le libellé à son point de rendu.

## À construire avant toute publication

- Dépôt Fans et décisions transactionnelles, versions, audit de modération et retrait.
- Stockage des originaux hors racine publique ; quarantaine avant analyse, quotas,
  validation MIME/signature/taille, autorisation d'upload avant réception du contenu.
- Routes privées avec ownership/nonce, lecture fraîche des droits et accès aux octets
  uniquement après contrôle ; aucun original protégé dans la médiathèque publique.
- Dérivés publics approuvés et révocation synchronisée avec retrait/suspension.
- Exclusion du contenu adulte également dans messages, aperçus et pièces jointes.
- Admission Federation/Events/Apps Registry explicite, projection signée Me,
  révocation et expiration du cache. La fraîcheur seule ne prouve pas l'autorité.
- Recette WP/MariaDB et HTTP de concurrence retrait/lecture, accès direct fichier,
  refus de substitution de créateur, faux droit et contournement de l'upload.

Sans adaptateur ni stockage actif, retour arrière par retrait de ces classes ;
aucune donnée à migrer. Catalog Me et les adaptateurs Identity Hub sont inchangés.

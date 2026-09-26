# Association privée image–publication Fans — v1

## Implémenté et frontière d’accès

Une publication textuelle peut référencer **une image**, approuvée dans la
quarantaine Fans et appartenant au même profil créateur. Le même créateur peut
réutiliser cette image sur plusieurs de ses textes. Aucun octet n’est copié,
aucune URL générée, aucun attachment WordPress créé. Le lot #77 n’introduit aucune diffusion. Le
[lot JPEG séparé](FANS-IMAGE-DELIVERY.md) ajoute une route Fans sous flag distinct,
sans teaser Me ni lecture du fichier de quarantaine. L’approbation du texte seule
ne suffit pas à autoriser un dérivé et n’ouvre jamais le fichier de quarantaine. Les catégories du texte restent limitées à
`hosted_allowed_content` ; le droit adulte externe n’est pas une publication.

`TextPublicationService` réutilise le SSO, `CreatorProfileService::own()` et la
politique d’accès au texte existants. Aucun `creator_id`, `wp_user_id`, e-mail ou
Faluss ID fourni par le client ne décide de la propriété. Un profil `active`
signifie approbation administrative de publication, pas identité vérifiée.

Le contrat serveur Images `PublicationImageReference::eligible()` renvoie
uniquement un booléen : image approuvée, révision exacte, même créateur actif,
module Images et schéma disponibles. Il ne donne ni métadonnées ni octets.
Publications ne lit pas les tables internes Images. Cette intégration est
optionnelle et sans dépendance d’ordre de boot : le texte reste utilisable avec
le flag Images fermé, mais aucune référence d’image ne devient alors utilisable.
Les deux modules conservent leur dépendance SSO/profils et leur admission Fans.
La politique pure d’accès aux originaux ne devient pas un fournisseur de fichiers.

## REST et transitions

`POST /faluss-fans/v1/text-publications/{id}/image`, session SSO locale et nonce
`X-WP-Nonce` valides, profil actif réellement propriétaire du texte :

```json
{"revision": 4, "image_id": "aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa", "image_revision": 2}
```

Pour détacher : `image_id=null` **et** `image_revision=null`. JSON strict : aucun
autre champ, fichier, query string ou chemin accepté. Révisions entières positives,
UUID v4 minuscule. La révision de l’image provient de sa liste privée existante.

| Situation | Résultat |
| --- | --- |
| Ajouter, remplacer, détacher (même demande identique avec révision courante) | 200, révision du texte +1, `pending`, nouvelle trace ; texte public masqué jusqu’à décision |
| Non connecté/non lié, nonce absent/invalide ou mauvais propriétaire | 401/403, aucune mutation |
| Paramètres invalides ; texte vide après rejet sans nouvelle édition | 400 |
| Révision du texte périmée ou état retiré | 409 |
| Image inconnue, étrangère, non approuvée, révoquée, révision périmée ou module Images fermé | 409 `publication_image_unavailable`, sans dévoiler la cause privée |
| Quota atteint | 429, codes d’admission texte existants |
| Verrou, schéma ou écriture indisponible | 503, aucune mutation partielle |

Les textes `pending` et `approved` non vides peuvent changer d’association ; une
approbation précédente est perdue. Un texte rejeté doit d’abord recevoir une
nouvelle édition textuelle. Le retrait du texte est terminal. L’édition du texte
conserve la référence mais repasse toujours en modération. Détacher est aussi une
édition ; retirer la publication ou l’image reste possible sans quota d’admission,
y compris après suspension du créateur.

Le GET privé, les listes propriétaire et modération contiennent `image_id`, ou
`null` si aucune référence n’est actuellement utilisable. Ils ne retournent aucun
chemin, hash de fichier, taille, URL ou octet. Les réponses d’écriture conservent
leur projection texte ; relire le GET privé pour l’association courante.
**Les GET publics et leur liste gardent exactement les cinq champs texte** :
`publication_id`, `creator_id`, `revision`, `body`, `updated_at`. Même l’existence
d’une image n’y est pas indiquée. Les octets de quarantaine restent accessibles seulement à
l’administrateur Fans autorisé avec nonce via le parcours de quarantaine existant.

Chaque lecture privée réévalue l’état Images et le profil. Après commit du retrait
ou rejet de l’image, retrait/rejet du texte, suspension du profil ou fermeture du
flag Images, la référence est inutilisable (`null`). Un retrait d’image n’efface
pas le texte approuvé : seul son lien devient inutilisable. Une réactivation de
profil peut rendre une référence toujours approuvée à nouveau éligible ; elle ne
restaure jamais une image ou un texte retiré. Une réponse déjà lue avant révocation
ne peut être rappelée. Réponses `private, no-store` ; aucun cache CDN ne doit les
conserver. Le moteur JPEG optionnel relit ses propres autorisations à chaque demande :
ni cet UUID ni une ancienne réponse privée ne constituent un droit aux octets.

## Transaction, quota et trace

Ordre d’acquisition : verrou d’admission par créateur, transaction InnoDB,
publication `FOR UPDATE`, puis image `FOR UPDATE` via son contrat d’éligibilité.
Le contrat Images ne démarre ni ne clôture de transaction. Les mutations Images
existantes verrouillent leur ligne lors de l’UPDATE ; une révocation concurrente
est donc ordonnée avant ou après l’admission. Toute lecture ultérieure revérifie
l’éligibilité. Aucun verrou Images n’acquiert de verrou publication en retour.

Le journal d’association, la révision du texte et sa trace sont commis ensemble.
Échec d’une écriture : rollback des trois. Même révision lors d’une course entre
deux associations, texte/association, modération/association ou retrait/association :
une mutation réussit, l’autre reçoit 409. Pas d’idempotence supplémentaire : après
réponse perdue, relire le privé ; le rejeu d’une révision consommée reçoit 409.

Chaque association compte comme `edit` dans les limites existantes : 30 admissions
par heure et 100/24 h ; 20 textes pending. Une modification de texte déjà pending
à 20/20 ne consomme pas de nouvelle place, mais compte dans le débit. Une association
sur texte approuvé exige une place pending. Le motif `image_association` distingue
ces éditions ; le SHA-256 reste celui du texte, pas celui du fichier.

## Schéma, activation et retour arrière

Schéma Publications **v3** : nouvelle table InnoDB privée
`${prefix}faluss_fans_text_images`, clé primaire `(publication_id,revision)`,
référence `image_id` et `image_revision`. Une ligne est ajoutée à chaque décision
d’association ; chaîne vide/révision zéro représente le détachement. Sa révision
rejoint la trace existante qui porte acteur WordPress, motif et horodatage UTC.
Aucun historique d’octets ni de texte n’est ajouté. Rétention de cette trace,
suppression de compte et accès aux sauvegardes restent à définir avant ouverture.

Migration v2 → v3 additive par activation explicite locale : les trois tables
existantes restent inchangées. Aucune migration au chargement HTTP ; v2 non migrée
ferme le module v3. Aucun changement de schéma Images, profil ou SSO. Installation
réservée au rôle Fans et aux flags texte/SSO/profils déjà requis ; l’association
effective exige aussi Images. Les flags restent fermés hors recette jetable.

Rollback sûr : fermer les flags texte et Images, recharger les workers, vérifier
les routes absentes ; conserver tables, références et quarantaine privée. Ne pas
abaisser l’option de version ou réactiver un ancien moteur pour contourner les
contrôles. Aucune purge destructive, tâche cron ou configuration de production.

## Preuves et limites

Tests simulés : éligibilité, ownership, révisions, suspension/révocation, projection
publique, quotas et rollback du journal ou de la référence. Recette distincte
[WordPress/MariaDB réelle](../../tests/Fans/Publications/recipe/IMAGE-ASSOCIATION.md) :
54 contrôles HTTP réussis, dont courses entre quatre workers et panne SQL injectée.
Les tests locaux ne prouvent ni la détection de tout contenu interdit, ni la sûreté
des alias HTTP de l’hébergeur, ni la diffusion future. Le contrat de stockage privé
et ses limites restent ceux de [FANS-IMAGES.md](FANS-IMAGES.md).

Avant activation de la diffusion : valider les consentements/modérations,
l’hébergement, les caches et la rétention selon le [contrat JPEG](FANS-IMAGE-DELIVERY.md).
Sa recette locale ne constitue pas une activation de production.
Avant teaser Me : sélection explicite et contrat de projection/révocation, transport
et tests Me distincts. Aucun paiement, commande ou score n’est ajouté.

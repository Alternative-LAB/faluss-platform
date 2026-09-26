# Affichage d’images Fans — dérivé v1, opt-in fermé

## Implémenté dans ce lot

Le Master Plugin peut servir un **dérivé JPEG** d’une image associée à un texte
publiquement lisible sur le rôle **Fans uniquement**. Le flag distinct
`FALUSS_PLATFORM_FANS_IMAGE_DELIVERY` est absent/false par défaut. Aucune activation
de production n’a accompagné #78, désormais fusionnée. Le flag de quarantaine
`FALUSS_PLATFORM_FANS_IMAGES` ne suffit jamais à ouvrir cette route.

Le dérivé est créé **en mémoire à chaque demande**, dans un nouveau raster GD,
sans fichier dérivé persistant : JPEG qualité 82, côté maximal 1 280 pixels, sans
agrandissement, transparence aplatie sur blanc, au plus 2 Mio. Les dimensions et
le format du PNG de quarantaine sont vérifiés avant décodage (4 millions de pixels,
4 096 par dimension, 8 Mio maximum). Aucun EXIF, nom source ou octet source n’est
recopié dans le JPEG. Décodage/encodage en échec ou avertissement : refus, **jamais
de fallback vers le fichier de quarantaine**. Ce traitement n’est pas une preuve
de détection de tout contenu interdit ; la modération humaine reste nécessaire.

Les originaux source téléversés ne sont déjà plus conservés par le moteur de
quarantaine. Son PNG privé demeure réservé à la modération administrative par
la route existante. Le nouveau parcours public n’envoie que le JPEG dérivé.
Aucun attachment, miniature WordPress, chemin public, fichier temporaire dérivé,
URL de stockage, CDN, transport Hub ou teaser Me n’est ajouté.

## Contrat REST public

`GET /faluss-fans/v1/text-publications/{publication_id}/image/{revision}`

Le client utilise l’UUID et la révision du texte courant, déjà publics dans la
réponse texte. Ce ne sont pas des secrets ni des droits : **chaque requête** relit
et contrôle les données canoniques. Le JSON public des textes garde ses cinq
champs ; aucun UUID d’image ni métadonnée privée n’y est ajouté. La route peut
répondre 404 pour un texte sans association ; aucune URL n’est enregistrée en base.

Lecture anonyme autorisée uniquement si toutes ces conditions sont réunies :

1. rôle Fans, flags SSO/profils/textes/images/diffusion, configuration SSO et schémas
   existants valides ;
2. UUID v4 valide, révision entière exacte du texte, texte non vide autorisé,
   état approuvé et politique `PublicationAccessPolicy` autorisant sa lecture publique ;
3. profil propriétaire actif, vérifié via le contrat du module Profils ;
4. dernière association de cette publication non détachée, image approuvée à la
   révision référencée et appartenant **au même créateur** ;
5. racine privée contrôlée et attestation d’hébergement valides, fichier régulier
   privé, hash correct et génération réussie.

Le client ne peut choisir ni image, ni propriétaire, ni chemin, ni format, ni taille.
Un administrateur n’a aucun contournement de ces conditions sur cette route.
La lecture est sans nonce, puisqu’elle porte uniquement sur un contenu public.
Les mutations existantes et l’accès à la quarantaine conservent session/permissions
et nonce REST obligatoires. Le statut de profil `active` ne vaut toujours pas
vérification d’identité du créateur. La catégorie adulte externe reste interdite.

| Réponse | Sens |
| --- | --- |
| 200 `image/jpeg` | Dérivé frais, nom générique `display.jpg`, aucun chemin ni hash privé |
| 404 | Texte/image non éligible, révision ancienne, association absente, retrait, rejet ou suspension ; pas de détail privé |
| 400 | Query string, corps, fichier, `Range` ou `If-Range` : aucune variante client ni lecture partielle |
| 503 | Génération occupée (`display_busy`), stockage, SQL ou conversion indisponibles ; aucun octet image |
| Route absente | Flag fermé, schéma/prérequis indisponible ou rôle Me/Hub ; généralement 404 WordPress |

`HEAD` effectue les mêmes contrôles, sans corps. Pas d’ETag ou Last-Modified et
pas de réponse 304 : les en-têtes conditionnels ne permettent pas de sauter les
contrôles. Aucune requête HTTP externe n’est effectuée par ce parcours.

## Révocation et concurrence

Un GET prend un verrou MariaDB de génération par site avec attente **zéro** :
un seul rendu à la fois, les autres demandes reçoivent 503 sans file de rendu.
Ce n’est pas une limite de débit par visiteur ni une défense DDoS complète.

Dans une transaction InnoDB, ordre de verrouillage : publication, profil actif,
dernière association, puis image. Chaque lecture de droit utilise `FOR UPDATE`.
Le contrat `CreatorProfileService::publicById($id, true)` ajoute une lecture
verrouillée optionnelle, sans changer la projection ou les appels existants.
Le module Publications ne lit pas les tables internes Profils ou Images.
`ImageDisplayDerivative::forPublication()` est un contrat serveur appelé dans
cette transaction, après autorisation du texte/profil ; aucune route directe
ne permet au visiteur de l’appeler par UUID d’image.

Le JPEG est entièrement construit avant commit ; aucune sortie d’octets avant
succès de la transaction. Erreur SQL : rollback et refus. Les verrous sont libérés
avant l’envoi HTTP. Les mutations existantes attendent les verrous de leurs lignes,
sans acquisition inverse de verrous publication. Les attentes InnoDB dépendent
du timeout SQL de l’hébergement ; leur échec doit rester un refus.

- Édition, remplacement ou détachement : nouvelle révision pending, anciennes URL
  refusées. Une nouvelle approbation exige la **nouvelle URL/révision** ; un ancien
  lien ne se remet pas à servir un autre contenu.
- Retrait/rejet de l’image : l’association ne donne plus accès aux octets dès la
  demande suivante, même si le texte reste approuvé.
- Retrait/rejet du texte, suspension du profil ou fermeture d’un flag requis :
  plus de diffusion à la demande suivante.
- Réactivation du profil : les approbations inchangées peuvent redevenir lisibles.
  Elle ne restaure jamais un texte ou une image retiré. Réapprouver un texte rejeté
  nécessite une nouvelle édition/révision et sa décision normale.

Une demande déjà autorisée peut terminer son envoi pendant une révocation.
**Les octets déjà téléchargés, copiés, capturés ou affichés ne sont pas rappelables.**
Le navigateur peut conserver des pixels déjà rendus. Ce lot n’ajoute ni DRM,
notification de révocation au navigateur, ni effacement de copies tierces.

## Caches, origine et responsabilité d’hébergement

Réponses du parcours : `Cache-Control: private, no-store, max-age=0, must-revalidate`,
`CDN-Cache-Control: no-store`, `Surrogate-Control: no-store`, `Pragma: no-cache`,
`Expires: 0`, `X-Content-Type-Options: nosniff`. Le filtre d’envoi les réapplique
aussi aux erreurs de cette route lorsque l’adaptateur est chargé. Aucun cache
partagé applicatif, miniature publique ou réponse 304. Une route absente relève
des erreurs WordPress et de la configuration du serveur : exclure aussi ces erreurs
du cache, notamment pendant les bascules de flags.

`Cross-Origin-Resource-Policy: same-origin` et retrait des en-têtes CORS permissifs
WordPress sur ce parcours limitent l’intégration par un navigateur sur un autre
site. Ils ne peuvent empêcher un client HTTP de télécharger un contenu public,
ni un tiers de le recopier. « Fans uniquement » signifie ici rôle/route Fans,
aucun moteur Me ou teaser actif ; ce n’est pas une promesse de contrôle des copies.

`FALUSS_FANS_IMAGE_STORAGE_ATTESTED=true` reste obligatoire à **chaque** lecture.
Avant diffusion, l’exploitant doit renouveler la vérification d’hébergement :
aucun alias HTTP, montage public, indexation ou sauvegarde publique de la
quarantaine ; aucun cache inverse/CDN/plugin ignorant `no-store` pour ce préfixe,
y compris erreurs et variantes HEAD ; pas de logs de corps ou dumps accessibles.
PHP ne prouve ni cette configuration ni le comportement des intermédiaires.
Le booléen est une attestation opérateur, pas une mesure de sécurité automatique.

## Activation et retour arrière

Pas de nouveau schéma, hook d’activation, cron, dépendance ou migration. Texte v3,
Images v1 et profils/SSO existants restent nécessaires. La route est enregistrée
par le module Publications seulement avec le flag de diffusion vrai et Images
disponible. Aucune activation implicite au chargement ou par l’approbation d’un texte.

Avant activation de production, restent à valider explicitement :

- droits et consentements de diffusion, modération du contexte texte/image et
  inventaire des anciennes associations approuvées, qui deviendraient éligibles
  dès l’ouverture du flag ; aucun ancien lot privé ne vaut autorisation de lancement ;
- attestation d’hébergement actualisée, HTTPS, cache inverse, erreurs, CORS/origine,
  configuration des erreurs PHP, mises à jour GD et isolation du processus ;
- limites mémoire/CPU/temps SQL, limitation de débit en amont et tests de charge ;
- durées de quarantaine, images approuvées, traces, comptes supprimés, sauvegardes,
  temporaires multipart et éventuels dumps mémoire. Aucun fichier dérivé persistant
  n’est créé ; son existence en mémoire ne garantit pas un effacement physique de
  swap/core dump. Aucun TTL ou nettoyage de sauvegarde n’est inventé ici ;
- parcours utilisateur, accessibilité, signalements, recours et modération ;
- recette de l’hébergement réel et plan de retour arrière avant toute activation.

Rollback : `FALUSS_PLATFORM_FANS_IMAGE_DELIVERY=false`, recharger les workers,
vérifier route absente et aucun ancien cache. La quarantaine et les textes peuvent
rester actifs indépendamment ; fermer leurs flags ferme aussi la diffusion.
Conserver les données privées pour traitement contrôlé. Aucun fichier dérivé à
purger ; cela ne supprime pas les copies déjà reçues. Aucun déploiement, paiement,
release, vidéo, messagerie, CDN public ou teaser Me dans ce lot.

## Preuves

[Recette WordPress/MariaDB réelle](../../tests/Fans/Publications/recipe/IMAGE-DISPLAY.md) :
77 contrôles HTTP, dont courses, stockage corrompu/manquant, attestation absente,
liens forgés, verrou SQL, ancien lien après réapprobation et rollback des flags.
Les tests PHPUnit ciblés sont distincts : format/limites du dérivé, refus des états,
propriétaires et révisions, absence d’opt-in, cache et isolation Me/Hub.

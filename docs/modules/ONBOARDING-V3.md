# Onboarding Faluss.me V3 natif

V3 est le parcours de création de carte du rôle `me`. Le flag `FALUSS_PLATFORM_ONBOARDING_V3` est absent ou `false` par défaut. Il exige les modules Identity, Catalog, Link, Apps Registry et la dépendance de Link `Token Engine Connector`, ainsi que le schéma Link V4 déjà migré. Le module Me Studio fournit l'interface ; Identity conserve l'identité, le slug, la publication et le curseur ; Link conserve les préférences, les réseaux, les blocs ordonnés et le rendu public. La carte affichée dans l'unique téléphone utilise `Faluss_Link::card_markup_from_presentation()`.

## Parcours et reprise

Simple : rythme → identité → réseaux → liens → dernier regard → publication. Atomique suit les mêmes quatre premières étapes puis ajoute couleurs, boutons, avatar, image de fond, nom et style des réseaux avant le dernier regard. Le panneau inférieur a une position compacte et une position déployée ; son contenu défile une fois déployé. La prévisualisation AJAX réutilise le renderer public. À l'étape Identité, une sauvegarde différée enregistre les champs dans une métadonnée privée du compte WordPress, liée au Faluss ID actif ; après un upload d'avatar réussi, cette sauvegarde est immédiate avant d'annoncer « Image prête ». Les autres aperçus ne sauvegardent pas l'étape courante.

```mermaid
stateDiagram-v2
    [*] --> Rythme
    Rythme --> Identite
    Identite --> Reseaux
    Reseaux --> Liens
    Liens --> Verification: Simple
    Liens --> Couleurs: Atomique
    Couleurs --> Boutons
    Boutons --> Avatar
    Avatar --> Fond
    Fond --> Nom
    Nom --> StyleReseaux
    StyleReseaux --> Verification
    Verification --> Publication
    Publication --> Succes
```

L'étape Nom propose Outfit lorsqu'il est fourni par le site, ainsi que les piles natives sans et sérif avec poids 400 et 700. Aucun fichier de police ou service distant n'est ajouté ; la recette doit vérifier la disponibilité effective d'Outfit dans le thème actif.

Le curseur est enregistré dans `onboarding_next_step` d'Identity. Les anciens curseurs `choice`, `identifier`, `studio`, `wizard_*` et `wizard_atomic_*` sont projetés vers un écran V3 sans écriture à la lecture. Le mode choisi est encodé dans `v3_identity_simple` ou `v3_identity_atomic` avant l'enregistrement des préférences Link. À l'étape Identité, Retour enregistre le nom, le slug saisi et l'avatar dans la métadonnée privée **avant** de déplacer le curseur vers `v3_mode_*`. Un rechargement ou une reprise relit cette métadonnée pour les champs et l'aperçu ; elle est supprimée après l'enregistrement réussi de l'identité dans Link. Le slug n'est pas réservé par Retour ou par la sauvegarde différée : sa réservation permanente reste juste avant l'enregistrement de l'identité. Si ce dernier échoue, le slug reste réservé au même compte et peut être repris. Les autres passages suivants, retours ou étapes passées sauvegardent les données et le curseur dans la transaction Link/Identity existante.

Le serveur exige un nonce, une session membre, le curseur courant et la version agrégée du profil, des préférences et des blocs. Une version obsolète renvoie HTTP 409. Les champs non enregistrés restent dans le formulaire et un message invite à recharger. Les liens historiques omis par l'écran V3 ne sont pas supprimés. Une publication réussie marque le profil public et termine le curseur Identity dans une transaction. Un nouvel appel sur le profil déjà publié retourne son URL canonique.

## Activation et retour arrière

Les prérequis restent Link V4 (procédure documentée dans [ME-STUDIO.md](ME-STUDIO.md)), puis les flags suivants définis dans la configuration non versionnée :

```php
define('FALUSS_PLATFORM_ROLE', 'me');
define('FALUSS_PLATFORM_IDENTITY', true);
define('FALUSS_PLATFORM_CATALOG', true);
define('FALUSS_PLATFORM_TOKEN_ENGINE_CONNECTOR', true);
define('FALUSS_PLATFORM_LINK', true);
define('FALUSS_PLATFORM_APPS_REGISTRY', true);
define('FALUSS_PLATFORM_ONBOARDING_V3', true);
```

Le flag V3 charge désormais le Studio V3 **et** l'onboarding V3, indépendamment de `FALUSS_PLATFORM_ME_STUDIO_V2`. Le Studio propose treize rubriques natives via `/mon-faluss/`, désormais réparties entre Liens, Design et Profil : structure, identité, réseaux, liens, couleurs, boutons, avatar, fond, nom, style des réseaux, collections, contenus et ordre, réglages. Chaque rubrique relit l'agrégat Link et sauvegarde uniquement ses champs, avec nonce et version agrégée. La transaction conserve les autres préférences, les blocs non présentés, les médias et le slug ; elle ne déplace pas le curseur d'onboarding. Le slug est revendiqué exclusivement via Identity avant le design et reste immuable. Une indisponibilité du fournisseur V3 affiche une erreur récupérable, sans présenter le Studio historique comme V3.

La carte conserve une densité standard dans tous les contextes. Le téléphone réduit une composition de 390 px par `zoom` CSS, sans changer ses proportions ; son contenu reste défilable. Les décalages du mode immersif sont exclus de la carte canonique. Les styles de variantes sont chargés par Link même lorsque le Studio est désactivé. Les aperçus donnent aux champs temporaires la même priorité sur le thème que la sauvegarde ; une modification invalide immédiatement la réponse précédente. Le dernier regard relit l'état enregistré. La confirmation est une page autonome avec URL et deux liens, sans panneau ni progression.

Le shell de l’onboarding est fixe. `visualViewport` ne redimensionne pas l'en-tête : il relève le panneau au-dessus du clavier. Les champs ont une taille de 16 px, sans restriction du zoom volontaire. Le panneau s'agrandit avant le défilement interne. La couleur des boutons détermine côté serveur un texte noir ou blanc contrasté.

Pour revenir au parcours précédent, désactiver uniquement le flag V3 : Studio V2 reprend si son flag est actif, sinon le fournisseur historique. Les données restent conservées. Cette livraison ne modifie aucun flag. L'utilisateur installe le ZIP et effectue sa recette réelle ; aucun staging n'est utilisé pour cette livraison.


## Vérification avant activation

Tester sur un WordPress/MariaDB isolé : nouveau profil sans slug, reprise de chaque ancien curseur, Simple et Atomique, retour, passer, rechargement après chaque étape, deux sessions concurrentes, liens historiques, upload JPEG/PNG/GIF/WebP, fichier rejeté et serveur sans dossier d'upload accessible. Vérifier le même rendu dans la preview, le profil public, le shortcode et le widget Elementor. Vérifier la carte et le panneau à 320, 390 et 768 px dans Chromium et WebKit, les deux positions du panneau, le clavier, un lecteur d'écran et une préférence de mouvement réduit. Les logos de réseaux proviennent du catalogue Link ; un média absent est signalé dans son administration et aucune icône distante n'est téléchargée. Une recette fonctionnelle locale sur WordPress/MariaDB et Elementor est documentée dans [les preuves V3](../evidence/onboarding-v3/WORDPRESS-RECIPE.md) ; elle ne couvre pas le staging ni la production.

Les échecs de téléversement renvoient désormais un code structuré distinguant notamment taille, format, refus WordPress et création de pièce jointe. Le flux de publication renvoie un code d'échec et laisse le brouillon intact. Aucun code ou trace HTTP des incidents de production n'a été fourni : leur cause précise reste à établir à partir de la réponse brute et des logs serveur horodatés.


## Reprise et séparation Studio — 0.5.2

Le téléphone et le panneau demeurent propres à l’onboarding. Le Studio utilise désormais `StudioV3` : voir [Me Studio](ME-STUDIO.md#studio-v3-autonome--052).
`OnboardingV3::mode()` reconnaît un curseur historique `wizard_atomic_*` avant la préférence par défaut. Le mode résolu côté serveur est persisté dans la même transaction que les champs et le prochain curseur (`LinkStudioContract::saveOnboardingV3Step`, argument optionnel `mode`). Il n’est jamais lu depuis un mode arbitraire envoyé par le navigateur. Retour et Passer gardent ainsi le parcours Atomique après rechargement. Sans session, le rendu propose la vraie connexion.
Les conditions derrière `studio_unavailable` sont distinguées par le hook serveur `faluss_link_studio_diagnostic` : `session`, `identity_schema`, `identity_contract`, `composition_schema`. Le hook n’expose aucune donnée du membre, et la réponse navigateur demeure générique. Aucun prérequis n’est réparé ni migré automatiquement lors de cette lecture.

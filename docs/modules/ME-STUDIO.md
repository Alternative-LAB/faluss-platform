# Studio Faluss V2 et V3 natifs

Le nouveau parcours de création V3, activé séparément, est décrit dans [ONBOARDING-V3.md](ONBOARDING-V3.md).

Depuis 0.5.0, le flag `FALUSS_PLATFORM_ONBOARDING_V3` fournit aussi un éditeur V3 natif indépendant du flag V2, décrit dans [ONBOARDING-V3.md](ONBOARDING-V3.md).

Studio V2 est un module natif et optionnel du Master Plugin sur le rôle `me`. Il ne remplace ni Identity ni Link : il fournit l’interface d’édition et le parcours d’onboarding Atomique, tandis que Link reste l’autorité de composition et l’unique renderer de carte.

## Frontières d’autorité

| Autorité | Possède | Studio V2 consomme |
|---|---|---|
| Identity | `faluss_id`, nom, bio, avatar source, slug, publication, SSO, passwordless et curseur d’onboarding | `IdentityContract` uniquement |
| Link | document de composition, préférences, blocs ordonnés, projection des liens, transactions, visibilité et rendu public | `LinkStudioContract` uniquement |
| Catalog | thèmes et futures sources de polices autorisées | dépendance de module, aucune police distante ajoutée |
| Apps Registry | bindings descriptifs intermodules | quatre emplacements symboliques, jamais de PHP/HTML/JS transporté |
| Studio V2 | écrans, composants, preview et parcours Atomique | aucune table intermodule lue ou écrite directement |

Le shortcode, les widgets Elementor, les URLs publiques, les handlers historiques et le parcours Simple restent enregistrés une seule fois par Link. Le registre `StudioProviderRegistry` sélectionne au plus un fournisseur. Sans fournisseur, en cas d’erreur, ou lorsque le schéma V4 n’est pas prêt, Link rend son Studio interne.

## Renderer partagé

`Faluss_Link::card_markup_from_presentation()` reste la façade unique. Le profil public, la preview du Studio et la preview de l’onboarding lui fournissent le même profil Identity, la même composition Link et les mêmes blocs. Les conteneurs V3 réduisent visuellement la même composition à densité standard ; ils ne reconstruisent pas la carte et ne changent pas ses proportions.

Les variantes Atomiques comprennent :

- formes et textures de boutons (`Formel`, `Visuel`, `Minutieux`, `Granulée`, `Lisse`, `Camo`) ;
- forme et effet d’avatar, avec rayons partagés, bordure dérivée du fond et ombre stable ;
- wallpaper compact ou couverture, avec dégradé optionnel après environ 60 % ;
- neuf styles sociaux et couleur personnalisée de bulle ou de contour sans recolorer le logo ;
- liens neutres ou grille illustrée, largeur large ou courte, image propre à chaque lien ;
- détection locale des plateformes prises en charge depuis une URL HTTPS normalisée, sans favicon distant ;
- visibilité `all`, `members` ou `none` pour chaque lien.

Le header du Studio V2 reste collant sans modifier le fournisseur Simple. Le compositeur Link canonique conserve aussi ses blocs `media_teaser` ordonnés : cinq images peuvent donc rester composées et rendues par la carte partagée, sans nouvelle galerie, nouvelle table ou limite destructive ajoutée par Studio V2.

Les logos officiels ne sont jamais téléchargés depuis les URLs des membres. Le sélecteur et la carte publique consomment exclusivement les médias administrés par le catalogue de réseaux Link ; si un asset officiel n’est pas configuré, la carte omet l’icône plutôt que d’utiliser un favicon distant. La recette doit donc vérifier que les assets officiels attendus sont présents dans ce catalogue.

## Modèle de composition et migration

Le schéma Link V4 ajoute une colonne `composition LONGTEXT NOT NULL` à `faluss_link_cards`. Le JSON canonique a exactement quatre clés de tête : `version`, `structure`, `presentation` et `atomic`. Sa version documentaire est `2`. Les propriétés déjà possédées par des colonnes Link ou par Identity n’y sont pas déplacées.

La migration V3 vers V4 est additive :

1. prise d’un verrou consultatif MariaDB borné ;
2. ajout temporaire de `composition` nullable ;
3. backfill déterministe depuis les préférences historiques de `social_links` ;
4. validation stricte de la forme du document ;
5. passage de la colonne en `NOT NULL` ;
6. mise à jour de `faluss_link_schema_version` à `4`.

Aucune colonne ou table n’est supprimée, renommée ou convertie. Une migration interrompue peut reprendre depuis la colonne nullable. Les requêtes publiques et les requêtes d’administration ordinaires vérifient le schéma sans DDL, sans backfill et sans mise à jour d’option. Une installation neuve peut créer V4 pendant l’activation explicite du plugin avec Link opt-in. Pour une extension déjà active, la promotion V3 vers V4 nécessite l’action manuelle « Migrer Link vers V4 » sur Réglages > Réseaux Faluss Link ; son POST dédié exige `manage_options` et un nonce WordPress. Les anciennes données restent lisibles par Link V1 interne au Master Plugin tant que cette action n’a pas abouti.

## Activation et rollback

Studio V2 ne s’active pas lors d’une simple mise à jour. Après migration et recette sur une copie représentative, ajouter à la configuration non versionnée :

```php
define('FALUSS_PLATFORM_ROLE', 'me');
define('FALUSS_PLATFORM_IDENTITY', true);
define('FALUSS_PLATFORM_CATALOG', true);
define('FALUSS_PLATFORM_LINK', true);
define('FALUSS_PLATFORM_APPS_REGISTRY', true);
define('FALUSS_PLATFORM_ME_STUDIO_V2', true);
```

Le rollback applicatif consiste à retirer ou passer à `false` uniquement `FALUSS_PLATFORM_ME_STUDIO_V2`, puis charger une nouvelle requête. Link reprend immédiatement son fournisseur interne ; aucune restauration de base ni réactivation d’un plugin V1 n’est requise. La colonne additive reste en place et est ignorée par le fallback.

## Extensions Apps Registry

Le manifest Link déclare les bindings descriptifs :

- `me.studio.tab` ;
- `me.studio.block_source` ;
- `me.public.tab` ;
- `me.public.block`.

Un futur module doit ensuite enregistrer localement un `StudioBlockProvider` dans `StudioBlockProviderRegistry::shared()`. Le registre vit pendant toute la requête et le fournisseur Studio consulte ses descripteurs au moment du rendu : un module dépendant de `me-studio-v2`, démarré après lui, reste donc visible sans dépendre d’un hook déjà passé. Le descripteur fermé contient exactement : identifiant, emplacement, contrat de read model versionné, actions symboliques, visibilité et fallback. L’ordre final est déterministe par identifiant ; les doublons, champs supplémentaires et valeurs inconnues sont rejetés. Ce lot ne développe ni Fans, ni Store, ni Collections, ni Progression.

## Matrice d’onboarding

| Écrans | Étape persistée | Comportement |
|---|---|---|
| ONB-01.A / ONB-01.B | `wizard_structure` | Simple poursuit vers `wizard_name`; Atomique poursuit vers l’avertissement |
| ONB-02.B | `wizard_atomic_warning` | pas de preview, retour au choix, confirmation explicite |
| ONB-03.B / ONB-04.B | `wizard_atomic_colors` | onglets Arrière Plan/Boutons, deux valeurs indépendantes |
| ONB-05.B / ONB-06.B | `wizard_atomic_buttons` | onglets Forme/Texture |
| ONB-07.B | `wizard_atomic_avatar_upload` | upload dans l’avatar Identity existant |
| ONB-08.B / ONB-09.B | `wizard_atomic_avatar` | onglets Forme/Effets |
| ONB-10.B | `wizard_atomic_wallpaper_upload` | upload Link existant, étape facultative |
| ONB-11.B / ONB-12.B | `wizard_atomic_wallpaper` | onglets Taille/Effet |
| ONB-13.B / ONB-14.B | `wizard_atomic_networks` | onglets Style/Couleurs, étape facultative |
| Fin | `wizard_finish` | réutilise la finalisation Identity existante |

Chaque transition verrouille le profil Identity, la carte Link et les blocs dans une transaction unique avant de persister le nouveau curseur Identity. Retour, passer et continuer utilisent un contrat serveur fermé ; un rechargement reprend au curseur canonique.

## Typographies du nom

Le renderer continue d’appliquer les polices uniquement au nom du membre. La source Theme/Catalog actuelle ne fournit pas encore exactement Readex Pro, Orelega One, Quintessential, Racing Sans One, Radley, Rakkas, Rammetto One et Rempart One. Aucune police distante ni substitution silencieuse n’est ajoutée dans ce lot ; Outfit demeure la seule famille exacte déjà autorisée jusqu’à un apport Catalog séparé.

## Preuves et limites

Les tests automatisés couvrent le flag d’activation, les dépendances, le fallback fournisseur, le parcours Simple/Atomique, les principaux libellés, les mutations fermées, les uploads, le rendu partagé, les variantes CSS, la navigation clavier, les extensions Apps Registry et la migration additive. Ils ne constituent pas une recette WordPress/MariaDB/Elementor réelle. La bascule exige encore une copie représentative, des médias réels appartenant aux comptes de test, une vérification mobile/bureau et un rollback par le seul flag Studio V2.

## Recette exacte avant bascule

1. Cloner sur un staging isolé une base et une médiathèque représentatives, puis prendre et dater une sauvegarde restaurable. Ne pas activer ce lot en production.
2. Démarrer avec `FALUSS_PLATFORM_ME_STUDIO_V2` absent ou à `false`, les modules Identity, Catalog, Link et Apps Registry activés. Sur une extension déjà active, ouvrir Réglages > Réseaux Faluss Link avec un compte `manage_options`, cliquer explicitement sur « Migrer Link vers V4 » puis attendre le résultat. Une simple ouverture du back-office ne doit produire ni DDL ni backfill.
3. Vérifier dans MariaDB que `faluss_link_schema_version` vaut `4`, que `faluss_link_cards.composition` est `LONGTEXT NOT NULL`, que chaque document possède exactement `version`, `structure`, `presentation` et `atomic`, que le nombre de cartes n’a pas changé et qu’aucune colonne ou table historique n’a été supprimée.
4. Ouvrir un membre Simple existant avant d’activer Studio V2 et comparer Studio, shortcode, widget Elementor et profil public : identité, liens, thème, blocs et ordre doivent rester identiques.
5. Configurer dans Link les médias officiels des réseaux attendus. Vérifier qu’aucun favicon n’est demandé. Vérifier aussi qu’Outfit est la seule famille exacte disponible et qu’aucune police distante ne remplace silencieusement les familles encore absentes du Catalog.
6. Passer `FALUSS_PLATFORM_ME_STUDIO_V2` à `true` dans la configuration non versionnée, charger une nouvelle requête et confirmer que le fournisseur actif est `me-studio-v2` sans double shortcode, double widget ni double route.
7. Avec un nouveau membre, parcourir ONB-01.A puis ONB-01.B jusqu’à ONB-14.B. Tester Simple, Atomique, retour, passer, rechargement à chaque étape et reprise au curseur Identity canonique. Tester des JPEG, PNG, GIF et WebP possédés par le membre, ainsi que le rejet d’un fichier non-image ou supérieur à 8 Mo.
8. Dans Studio, comparer immédiatement preview et profil public pour : structure, couleurs, trois formes et trois textures de boutons, avatar masqué/visible et ses formes/effets, wallpaper compact/couverture avec/sans dégradé, neuf styles sociaux, liens neutres/illustrés, grille et largeur. Composer cinq `media_teaser` et vérifier leur conservation, leur ordre et leur rendu partagé.
9. Tester la détection Instagram, TikTok, Telegram, X/Twitter, Snapchat, Threads, OnlyFans, YouTube, LinkedIn et GitHub avec des URL HTTPS et sous-domaines ; un domaine non pris en charge ne doit produire ni favicon ni requête distante. Tester chaque lien en `all`, `members` et `none` comme visiteur anonyme puis membre connecté.
10. Ouvrir le même Studio dans deux sessions. Enregistrer dans la première, puis tenter d’enregistrer la version devenue obsolète dans la seconde : attendre HTTP 409, rechargement de l’état canonique complet et aucune écriture partielle.
11. Rejouer le passwordless, la continuité SSO/onboarding, les shortcodes Link, les widgets Elementor et les autres modules du Master Plugin. Distinguer explicitement les résultats du harness des résultats WordPress/MariaDB réels.
12. Vérifier Chrome et WebKit automatisés en mobile et bureau, puis effectuer une recette manuelle sur Safari réel : WebKit automatisé ne constitue pas une preuve Safari.
13. Pour le retour arrière, remettre uniquement `FALUSS_PLATFORM_ME_STUDIO_V2` à `false`, charger une nouvelle requête et confirmer le retour au fournisseur Link interne, avec la composition V4 et tous les médias conservés.


## Correctif 0.5.1 — écran public et fonctions d’édition

La carte canonique couvre au minimum le viewport dynamique (`100dvh`, repli `100vh`) et s’allonge avec son contenu. Son enveloppe n’ajoute plus de cadre. L’aperçu utilise la même hauteur logique à l’échelle du téléphone ; le rendu Link partagé reste la source du shortcode et du widget Elementor. Le thème Elementor peut encore imposer ses propres marges extérieures : une intégration réelle n’est pas simulée par la preuve DOM.

Le Studio V3 comporte désormais **treize rubriques**. Liens utilise des opérations unitaires, et Collections, Contenus et ordre, Réglages rétablissent les éditions auparavant inaccessibles. L’onboarding conserve ses étapes existantes. La [matrice des fonctions et les résultats ciblés](../evidence/me-v3-viewport/README.md) distinguent les capacités déjà visibles dans l’ancien Studio des helpers historiques non raccordés.

Les actions AJAX `faluss_studio_v3_manage` et `faluss_studio_v3_upload_content` exigent le flag V3, une session et leur nonce propre. Le sujet reste résolu côté serveur. `LinkStudioContract::mutate()` conserve les contrats fermés et la transaction de l’agrégat ; `create_content`, `update_content`, `delete_content` sont limités aux blocs texte/teaser. Les liens et collections réutilisent leurs mutations existantes. Aucune sauvegarde partielle ne remplace le flux complet, aucun curseur d’onboarding n’est modifié.

Un enregistrement recharge la rubrique depuis l’état canonique. Les autres modifications non enregistrées nécessitent un choix explicite avant d’être abandonnées. Un conflit 409 laisse les champs saisis visibles. Les liens historiques non initialisés restent en lecture seule selon le contrat préexistant ; ce correctif ne force aucune migration. Les droits des teasers utilisent le catalogue et la décision serveur existants, sans nouvelle livraison de média protégé.


## Studio V3 autonome — 0.5.2

Sous le flag V3 existant, `MeStudioProvider` appelle `StudioV3::render()`.
Le Studio possède son shell, sa feuille de style et son contrôleur ; il partage les contrôles V3 et les mutations fermées, sans afficher le téléphone ni le panneau d’onboarding.

| Navigation | Rubriques existantes |
| --- | --- |
| Liens | Liens, Collections, Contenus et ordre (textes, teasers, ordre de tous les blocs) |
| Design | Structure, Couleurs, Boutons, Avatar, Fond, Nom, Style des réseaux |
| Profil | Identité, Réseaux, Réglages (bio/publication, en-tête, layout, thèmes, retrait des médias) |
| Shop | Indisponible : aucun contrat de boutique membre exposé dans ce périmètre ; aucun produit fictif |

Les URL `v3_section` existantes restent reconnues. L’aperçu est un dialogue natif ouvert à la demande, rendu par Link. Les éditeurs de blocs prévisualisent leur état **enregistré** ; leurs brouillons indépendants ne sont pas implicitement sauvegardés. La sortie compare les champs et l’ordre courant à leur état chargé ; un refus serveur ne réinitialise pas les champs. Une confirmation réussie recharge l’état canonique.

Un compte déconnecté retrouve le lien de connexion ; un compte avec une création inachevée est dirigé vers V3. Une carte volontairement dépubliée après un curseur `complete` reste éditable dans le Studio. Aucun écran V1/V2 ne remplace une erreur V3.

Recette et limites : [0.5.2](../evidence/me-v3-052/README.md). Les paragraphes de recette V2 ci-dessus décrivent le lot historique et ne valent pas preuve de production pour V3.

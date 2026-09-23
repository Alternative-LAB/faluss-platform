# Studio Faluss V2 natif

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

`Faluss_Link::card_markup_from_presentation()` reste la façade unique. Le profil public, la preview du Studio et la preview de l’onboarding lui fournissent le même profil Identity, la même composition Link et les mêmes blocs. Les conteneurs peuvent adapter la densité, jamais reconstruire une seconde carte HTML.

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

Aucune colonne ou table n’est supprimée, renommée ou convertie. Une migration interrompue peut reprendre depuis la colonne nullable. Les requêtes publiques vérifient le schéma sans DDL ; seuls l’activation du plugin avec Link opt-in ou un chargement d’administration par un compte `manage_options` peuvent promouvoir le schéma. Les anciennes données restent lisibles par Link V1 interne au Master Plugin.

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

Un futur module doit ensuite enregistrer localement un `StudioBlockProvider` dont le descripteur fermé contient exactement : identifiant, emplacement, contrat de read model versionné, actions symboliques, visibilité et fallback. Les doublons, champs supplémentaires et valeurs inconnues sont rejetés. Ce lot ne développe ni Fans, ni Store, ni Collections, ni Progression.

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
2. Démarrer avec `FALUSS_PLATFORM_ME_STUDIO_V2` absent ou à `false`, les modules Identity, Catalog, Link et Apps Registry activés, puis déclencher la migration V4 uniquement par l’activation du plugin ou une requête d’administration `manage_options`.
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

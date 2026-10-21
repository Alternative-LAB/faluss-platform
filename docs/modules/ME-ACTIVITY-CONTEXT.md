# Me — catégorie d'activité et premier bloc contextuel

## Périmètre et propriétaires

Ce contrat prépare deux livraisons dépendantes sur le rôle `me` : (1) une catégorie d'activité modifiable dans l'onboarding V3 et le Studio ; (2) un premier bloc contextuel facultatif dans la carte. Identity reste propriétaire du compte, du Faluss ID, du slug, de la publication et du curseur d'onboarding. Link reste propriétaire des préférences de la carte, des blocs, de la version agrégée, de la prévisualisation et du rendu public. Me Studio ne possède que les contrôles et la sélection dans le compositeur. Aucun champ métier ne passe dans Hub ou Fans.

La première livraison n'ajoute ni thème, commerce, réservation, automatisation tierce, ni collecte de métadonnées distante. Le shell mobile, le panneau inférieur, le clavier, les étapes Simple/Atomique existantes et les composants de carte restent les points d'intégration. La nouvelle étape de catégorie se place après la réservation de l'identité, avant les réseaux, dans les deux rythmes ; « Plus tard » garde la valeur `none`.

## Contrats de données

La préférence Link `activity_category` accepte exactement `none`, `video`, `music`, `streaming` ou `visual_arts`. Elle est enregistrée dans la colonne JSON `social_links` déjà détenue par la carte Link, sous l'enveloppe `{ "networks": [...], "activity_category": "..." }`. Le lecteur Link accepte déjà une liste historique ou une enveloppe pour les réseaux ; les écritures V4 doivent conserver la catégorie lors de toute sauvegarde, y compris les anciennes commandes de préférences. La composition visuelle V4 reste dans sa colonne `composition` et garde son contrat strict. Une clé absente vaut `none`. La catégorie participe à la version agrégée existante. La modification utilise la transaction Link/Identity et le nonce V3 ; une version périmée retourne HTTP 409 avec l'état canonique, sans écraser la catégorie d'une autre session. Une catégorie ne crée jamais automatiquement de bloc.

Le bloc est une ligne Link `faluss_link_blocks` de type `contextual_feature`, avec un `block_id` UUID existant et un payload JSON borné :

| Champ | Contrat |
|---|---|
| `category` | Une des quatre catégories concrètes, immuable pour cette ligne. |
| `provider` | `youtube` pour `video`, `music_link` pour `music`, `twitch` pour `streaming`, `dribbble` ou `pinterest` pour `visual_arts`. |
| `url` | URL HTTPS canonique du contenu ou du portfolio ; validée par fournisseur, 2 048 caractères maximum. |
| `title` | Libellé saisi par le membre, 80 caractères maximum ; défaut neutre si absent. |
| `subtitle` | Artiste, album ou description courte saisi par le membre, 120 caractères maximum ; facultatif. |
| `cover_attachment_id` | Image WordPress facultative appartenant au membre ; zéro si absente. |
| `enabled` | Booléen explicite ; `false` conserve le brouillon sans le publier. |

Au plus une ligne contextuelle par catégorie et par carte est acceptée. La ligne de la catégorie active et `enabled=true` est rendue avant les autres blocs sur l'aperçu et la carte publique. Les lignes des autres catégories restent privées dans l'état Studio ; elles ne sont ni supprimées ni projetées dans la carte publique lors d'un changement de catégorie. Revenir à une catégorie les retrouve. Les blocs existants, liens et collections gardent leur ordre et leur payload ; la limite globale actuelle de 32 blocs reste appliquée. Une suppression explicite du bloc est distincte d'un changement de catégorie ou d'un masquage.

Les écritures du bloc réutilisent le nonce, la session du propriétaire, la version agrégée, les transactions InnoDB et la réponse canonique du Studio. Le navigateur n'envoie ni Faluss ID ni propriétaire. L'aperçu d'un brouillon valide est sans écriture ; l'enregistrement réaffiche exactement le HTML retourné par le renderer Link. Le rendu public lit uniquement l'état enregistré. Une URL invalide, un fournisseur non autorisé, une image non possédée, un bloc supplémentaire ou une version périmée échoue sans écriture partielle.

## Fournisseurs, URL et rendu

| Catégorie | URL acceptée et sortie | Rendu principal et secours |
|---|---|---|
| Vidéo | URL de vidéo YouTube `youtube.com/watch?v=ID`, `youtube.com/shorts/ID` ou `youtu.be/ID` ; ID vidéo de 11 caractères ; stockage en URL `watch` canonique. | Lecteur généré par le serveur depuis l'ID seul, chargé à l'action du visiteur ; lien « Voir sur YouTube » toujours visible. |
| Musique | Lien d'écoute HTTPS sur `open.spotify.com`, `music.apple.com`, `soundcloud.com`, `bandcamp.com` ou leurs sous-domaines explicitement autorisés. | Carte avec titre, artiste/album et image du membre si disponibles ; lien « Écouter » toujours visible. Sans métadonnées, libellé générique et nom du service. Aucun lecteur ni appel API tiers dans ce lot. |
| Streaming | URL de chaîne `twitch.tv/<chaine>` ; nom de chaîne ASCII borné ; stockage canonique. | Lecteur Twitch généré avec le `parent` égal à l'hôte HTTPS réel de la carte, uniquement si ses dimensions minimales sont garanties ; lien « Ouvrir la chaîne » toujours visible. |
| Arts visuels | Profil `dribbble.com/<compte>` ou `pinterest.com/<compte>` ; aucun contenu arbitraire injecté. | Tuile portfolio avec titre et image du membre si disponibles ; lien externe permanent. Sans image ni intégration disponible, tuile typographique propre. |

Le serveur refuse les URL relatives, les schémas autres que HTTPS, les identifiants dans l'autorité, les ports non standards, les fragments, les hôtes IP, les hôtes trompeurs et les domaines non listés. Il n'effectue aucun `wp_remote_get` vers l'URL du membre dans ce lot. Une intégration n'accepte ni HTML ni URL iframe fournis par le navigateur : le serveur reconstruit la source depuis l'identifiant normalisé. Les titres, descriptions, URL et attributs sont échappés à la sortie. Les liens externes ouvrent un nouvel onglet avec `rel="noopener noreferrer nofollow"`. Le lien de secours reste utilisable si le fournisseur bloque l'iframe, si la ressource n'est plus disponible ou si les métadonnées manquent.

Le lecteur YouTube suit le format d'[intégration officiel](https://developers.google.com/youtube/player_parameters). Le lecteur Twitch utilise l'URL `player.twitch.tv` et le paramètre `parent` exigés par la [documentation Twitch](https://dev.twitch.tv/docs/embed/video-and-clips/) ; ses dimensions minimales doivent être vérifiées dans le compositeur et la carte publique. Les images de couverture proviennent seulement de la médiathèque WordPress du membre.

## Critères d'acceptation

1. Nouveau membre : choisir ou passer la catégorie dans Simple et Atomique, revenir, recharger, publier et retrouver la valeur dans le Studio sans modifier les étapes historiques.
2. Membre publié : changer de catégorie dans le Studio web et mobile ; l'aperçu change immédiatement après une sauvegarde réussie et la carte publique correspond après rechargement. Une prévisualisation seule ne modifie pas la carte publique.
3. Ajouter, éditer, masquer et réactiver le bloc facultatif dans chaque catégorie. Un changement de catégorie garde les quatre contenus enregistrés ; seule la catégorie active et publiée apparaît en première position.
4. Tester YouTube, musique avec et sans métadonnées, Twitch, Dribbble et Pinterest ; vérifier le lien de secours quand l'iframe ou les métadonnées sont indisponibles.
5. Refuser URL hostile, fournisseur incompatible, image étrangère, double bloc, nonce absent, session absente et version obsolète ; vérifier l'absence d'écriture partielle et la conservation des comptes déjà liés.
6. Comparer le même état sur prévisualisation, shortcode/carte publique et widget Elementor ; contrôler souris, toucher, clavier, PC, grande et petite tablette, téléphone. Le shell mobile, le panneau et le focus clavier conservent leur comportement.
7. Exécuter les contrats, les tests navigateur et une recette WordPress/MariaDB isolée avec de vraies écritures API. La vérification sur le modèle Elementor cible et les appareils réels reste distincte ; aucune fusion ne vaut installation WordPress.

## Livraison et retour arrière

La PR de catégorie précède la PR du bloc ; la seconde cible la branche de la première puis `main` après fusion. Les deux doivent rester séparées de #248 (accès/feedback), #249 (session centrale), #247 (release) et de tout changement CI. Le retour arrière retire les contrôles et le rendu sans effacer les clés ou lignes Link. Une ancienne version V4 peut réécrire `social_links` comme simple liste et perdre la catégorie lors d'une autre mutation : suspendre l'édition pendant le retour arrière tant que sa préservation n'a pas été prouvée sur copie isolée. Il faut aussi vérifier qu'une ancienne version préserve un bloc qu'elle ne reconnaît pas lors d'une autre mutation. Les liens et profils historiques ne doivent pas être réécrits.

Lots suivants, hors de cette livraison : catalogue de thèmes, variantes d'en-tête, avatar GIF/vidéo et recadrage, couleur de biographie, liens téléphone/localisation, carte Wallet, outils de création, revenus et réservations, liens profonds et performances du Studio. Les fonctions commerciales et les automatisations tierces exigent leurs intégrations et validations propres.

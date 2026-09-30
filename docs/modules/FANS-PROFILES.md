# Profils créateurs Fans — fondation structurée

## Portée implémentée

Le module optionnel `fans-creator-profiles` appartient exclusivement au rôle `fans` et dépend de `fans-sso`. Chaque `subscriber` local lié à Me peut créer un seul profil. Le profil contient un UUID public distinct du `faluss_id` et du `wp_user_id`, une catégorie fermée (`arts`, `music`, `games`, `learning`, `lifestyle`), un état (`pending`, `active`, `suspended`) et des dates. Il ne contient ni titre libre, biographie, image, fichier, média, lien externe, contenu adulte ou droit commercial. Cette limite permet une première identité de créateur classable sans ouvrir un canal d'hébergement de contenu à modérer.

`active` signifie uniquement qu'un administrateur Fans a approuvé la **publication du profil structuré**. La vérification d'identité du créateur n'est pas implémentée : toutes les réponses de profil, publiques ou privées, exposent `identity_verified=false`, même après passage à `active`. Aucun badge ou libellé « vérifié » ne doit être déduit du statut, de la liaison SSO, de l'e-mail prouvé ou de cette approbation. Une future vente exigera une condition distincte de vérification du créateur, définie, stockée et testée dans un lot commercial ultérieur ; le statut `active` ne suffit pas.

La table `${prefix}faluss_fans_creator_profiles` possède des index uniques sur `creator_id` et `wp_user_id` et un index `(status, category)`. Elle est InnoDB et appartient uniquement à Fans. L'option `faluss_fans_creator_profiles_schema_version=1` est écrite après vérification exacte du schéma. Aucune table Hub ou Me n'est lue ou modifiée, à l'exception du contrat public de liaison locale du client SSO Fans.

## Activation et retour arrière

La configuration serveur hors Git doit déjà activer et valider le client SSO Fans, puis définir `FALUSS_PLATFORM_FANS_CREATOR_PROFILES=true`. L'activation du plugin installe ou vérifie la table ; une réactivation contrôlée est nécessaire pour un plugin déjà actif. Le module n'enregistre aucune route tant que le rôle, les deux flags, le client SSO configuré et les deux schémas vérifiés ne sont pas présents. Retirer le flag désactive les routes. Les profils et l'option restent conservés pour une reprise ou un rollback ; aucun nettoyage de données n'est automatique. Aucune activation ni migration réelle n'est réalisée par cette PR.

## API `faluss-fans/v1`

| Route | Accès | Effet |
| --- | --- | --- |
| `GET /creators` avec catégorie optionnelle | Public | Au plus 20 profils `active`, triés par UUID. Catégorie inconnue refusée. |
| `GET /creators/{creator_id}` | Public | Un profil `active` ou 404 ; `pending` et `suspended` restent invisibles. |
| `GET /creators/me` | Membre `subscriber` lié au SSO Fans, nonce REST | Son propre profil, y compris non publié. |
| `POST /creators/me` | Même membre et nonce REST | Crée un profil `pending` depuis une catégorie autorisée. Rejouer la même catégorie retourne le même profil ; changer la catégorie retourne 409. |
| `POST /creators/{creator_id}/status` | `manage_options` et nonce REST | Publie (`active`) ou suspend (`suspended`) un profil, avec sa révision de statut ; seul un administrateur décide. Journal atomique, ne vérifie pas l'identité. |

Le serveur valide les catégories et états même si l'API est appelée directement. Les lectures publiques n'exposent jamais `wp_user_id`, `faluss_id`, e-mail ou claims Me. Les écritures utilisent les permissions WordPress, la liaison SSO et `X-WP-Nonce`; aucun rôle privilégié n'est accordé à un créateur. Le propriétaire ne peut pas s'auto-publier. Un index unique arbitre les créations concurrentes. Le module ne crée aucun endpoint de mise à jour de texte ou de média.

## Tests et étapes restantes

Le [parcours de demande natif](FANS-CREATOR-ADMISSION-UI.md) raccorde Mon espace
à cette API : catégorie, profil pending, lecture de l’état et accès au shell
Créateur. Il ne permet pas au membre de publier son propre profil.

Les tests couvrent l'isolation du rôle, le schéma, la création unique et idempotente, le rejet des catégories inconnues et des membres non liés ou privilégiés, l'invisibilité avant approbation et après suspension, le filtre de catégorie, `identity_verified=false` avant et après approbation, et les permissions REST avec nonce. Une recette locale WordPress/MariaDB sur la chaîne Fans a confirmé l'installation InnoDB et les réponses REST 403 sans nonce, 201 à la création, 403 pour l'approbation par le membre et 200 pour l'approbation administrative avec `identity_verified=false`. Restent à exercer l'échange réseau Me, le navigateur et les courses de création réelles.

Avant d'ajouter un nom affiché, une biographie, un lien ou un média, définir et tester la modération avant stockage et publication, les retraits, les signalements, la rétention et le refus de tout contenu adulte. Les publications, followers, messagerie et commerce sont des lots séparés. Les deux catégories commerciales restent régies par [FANS.md](FANS.md) : aucune vente n'est possible dans ce module.

## Lecture verrouillée pour la diffusion Fans

`CreatorProfileService::publicById($creatorId, true)` permet au consommateur
serveur d’obtenir la même projection de profil actif avec `FOR UPDATE`, dans
sa propre transaction InnoDB. Le moteur de dérivé JPEG verrouille ainsi le profil
jusqu’à la fin de la génération ; une suspension concurrente est ordonnée par
MariaDB. Sans second argument, le contrat existant reste inchangé. Ce paramètre
n’est pas une entrée REST et ne confère aucune vérification d’identité ni droit
commercial. Voir [le contrat de diffusion](FANS-IMAGE-DELIVERY.md).


## Administration de l’admission des profils

L’onglet **Faluss → Modération Fans → Profils Créateur** permet d’examiner les demandes structurées et de décider `active` ou `suspended`. Il reste disponible sans activer les modules textes, images ou présentations. Il exige `manage_options`. Le formulaire natif POST exige un nonce `fans_moderation`, la confirmation explicite et la révision de statut examinée ; l’adaptateur appelle la route REST existante, avec son propre nonce `wp_rest` et ses permissions.

Activer un profil ne valide **ni le partenariat commercial, ni les contenus éditoriaux, ni les images**, et ne prouve pas l’identité. Les validations et refus commerciaux restent inchangés. L’interface ne présente aucun nom ou portrait non approuvé comme public.

### Contrat privé et concurrence

- `GET /faluss-fans/v1/creators/moderation?status=pending` : file privée, états `pending/active/suspended`, 20 profils maximum, pagination par UUID opaque (`cursor`), tri par référence.
- `GET /faluss-fans/v1/creators/{creator_id}/private` : fiche structurée, `status_revision` et 20 dernières décisions. Aucun nom de compte, e-mail, Faluss ID, secret ou contenu éditorial dans ce journal.
- Ces deux lectures exigent `manage_options` et `X-WP-Nonce`. Les réponses privées sont `no-store`.
- La route de statut existante reçoit désormais un entier JSON `revision` obligatoire, égal à `status_revision` lu dans la fiche privée. Révision initiale **0**. Absence/type invalide : `400`. Modification concurrente ou rejeu obsolète : `409`, sans nouvel événement. Un changement déjà effectué avec la même révision est un no-op, sans faux journal.
- Exemple de corps : `{ "status": "active", "revision": 0 }`. Relire la fiche avant toute nouvelle décision ; ne pas réessayer aveuglément un `409` ou `503`.
- Le service PHP `CreatorProfileService::setStatus($id,$status,$revision=null)` conserve les deux arguments historiques pour les appelants serveur existants. Toute transition passe par le même verrou de profil et le même journal atomique. Les interfaces humaines et REST doivent fournir la révision.
- Le verrou InnoDB de la ligne de profil sérialise les décisions ; mutation, révision monotone et audit sont commis ensemble. Échec d’insertion du journal : rollback, `503`. La révision ne dépend pas de l’horloge à la seconde et empêche un conflit même après retour à un ancien statut.

### Stockage, mise à niveau et retour arrière

Table additive `${prefix}faluss_fans_creator_status_decisions` : `(creator_id,revision)` unique, `actor_id` WordPress de l’administrateur, statut précédent/nouveau et date UTC. Aucun changement au schéma v1 des profils ou au stockage éditorial, images, SSO, commercial. Les décisions sont conservées pour l’audit ; l’affichage se limite aux 20 dernières par profil.

Installation sous les autorisations **déjà existantes** du rôle Fans et des flags SSO/profils, avec schémas SSO/profils valides : hook d’activation, ou mise à niveau additive à `admin_init` pour `manage_options` et SSO configuré. Aucun DDL dans un endpoint REST. En cas de schéma absent/non conforme, la file et les décisions échouent fermées (`503`). Les lectures publiques existantes continuent d’utiliser seulement le statut de profil ; aucun journal ou identifiant administrateur n’y est projeté.

Les statuts antérieurs restent intacts et commencent à la révision 0 sans inventer d’historique. Le retour à une ancienne version conserve la table, mais cette version n’apporte plus la protection de concurrence/journal ; suspendre les décisions pendant un retour arrière et relire les profils avant de reprendre. Aucune suppression automatique de données ni modification des flags.

### Recette

Voir [recette WordPress/MariaDB](../../tests/Fans/Profiles/recipe/ADMISSION.md) et [captures et résultats](../evidence/fans-creator-admission/README.md). Les preuves locales ne constituent pas une recette sur `fans.faluss.me`.

# Profils créateurs Fans — fondation structurée

## Portée implémentée

Le module optionnel `fans-creator-profiles` appartient exclusivement au rôle `fans` et dépend de `fans-sso`. Chaque `subscriber` local lié à Me peut créer un seul profil. Le profil contient un UUID public distinct du `faluss_id` et du `wp_user_id`, une catégorie fermée (`arts`, `music`, `games`, `learning`, `lifestyle`), un état (`pending`, `active`, `suspended`) et des dates. Il ne contient ni titre libre, biographie, image, fichier, média, lien externe, contenu adulte ou droit commercial. Cette limite permet une première identité de créateur classable sans ouvrir un canal d'hébergement de contenu à modérer.

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
| `POST /creators/{creator_id}/status` | `manage_options` et nonce REST | Publie (`active`) ou suspend (`suspended`) un profil ; seul un administrateur décide. |

Le serveur valide les catégories et états même si l'API est appelée directement. Les lectures publiques n'exposent jamais `wp_user_id`, `faluss_id`, e-mail ou claims Me. Les écritures utilisent les permissions WordPress, la liaison SSO et `X-WP-Nonce`; aucun rôle privilégié n'est accordé à un créateur. Le propriétaire ne peut pas s'auto-publier. Un index unique arbitre les créations concurrentes. Le module ne crée aucun endpoint de mise à jour de texte ou de média.

## Tests et étapes restantes

Les tests couvrent l'isolation du rôle, le schéma, la création unique et idempotente, le rejet des catégories inconnues et des membres non liés ou privilégiés, l'invisibilité avant approbation et après suspension, le filtre de catégorie et les permissions REST avec nonce. Une recette WordPress/MariaDB de test reste requise pour l'installation InnoDB, la connexion Me, les nonces REST, les appels publics et privés, les courses de création et le rollback.

Avant d'ajouter un nom affiché, une biographie, un lien ou un média, définir et tester la modération avant stockage et publication, les retraits, les signalements, la rétention et le refus de tout contenu adulte. Les publications, followers, messagerie et commerce sont des lots séparés. Les deux catégories commerciales restent régies par [FANS.md](FANS.md) : aucune vente n'est possible dans ce module.

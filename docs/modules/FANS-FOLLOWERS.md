# Followers Fans — suivi local minimal

## Contrat implémenté

Le module opt-in `fans-followers` ne s'exécute que sur le rôle `fans`, après `fans-sso` et `fans-creator-profiles`. Un `subscriber` lié au SSO Fans peut suivre un profil créateur `active` autre que le sien. L'action répétée est idempotente ; la clé primaire composée interdit les doublons. Le retrait reste possible lorsque le profil est ensuite suspendu. Aucun utilisateur non lié ou rôle privilégié ne peut écrire une relation.

La table InnoDB `${prefix}faluss_fans_follows` contient seulement le `wp_user_id` local du follower, l'UUID public du créateur et la date de création. Le `faluss_id` et l'e-mail ne sont ni copiés ni exposés. L'option `faluss_fans_followers_schema_version=1` n'est écrite qu'après vérification du schéma. Le flag `FALUSS_PLATFORM_FANS_FOLLOWERS=true` est une configuration serveur hors Git ; l'activation ou réactivation contrôlée du plugin installe ou vérifie la table après les dépendances. Retirer le flag coupe les routes sans supprimer les relations.

## API

| Route `faluss-fans/v1` | Permission | Réponse |
| --- | --- | --- |
| `POST /creators/{creator_id}/follow` | Membre SSO Fans lié et `X-WP-Nonce` REST | `{creator_id, following: true}` ; profil inactif, inconnu ou propre au membre refusé. |
| `DELETE /creators/{creator_id}/follow` | Même permission | `{creator_id, following: false}` ; idempotent, y compris après suspension. |
| `GET /creators/{creator_id}/followers/count` | Public si profil `active` | `{creator_id, count}` ; aucune liste de followers ni identifiant local. |

Le service revérifie la preuve SSO, le format de l'identifiant et l'état public du profil côté serveur ; les routes d'écriture réutilisent la permission REST avec nonce du module Profils. Le compteur public n'accorde aucun droit. Il n'y a ni notification, ni événement, ni projection Hub/Federation/Apps Registry implicite.

## Preuves et limites

Les tests couvrent l'isolation de rôle et de schéma, la création unique, le rejeu, le retrait répété, le refus de l'auto-suivi, d'un membre non lié et d'un profil suspendu, l'échec de stockage et l'absence d'identités dans le compteur. Une recette WordPress/MariaDB reste requise pour les clés et courses réelles, les nonces REST, la suspension simultanée d'un profil et le rollback du flag.

Avant d'ouvrir une expérience sociale complète, ajouter dans des PR distinctes le blocage, les signalements, les règles de visibilité et de suppression de compte, la rétention des relations et l'intégration aux contrats Events/Federation appropriés. Aucun contenu adulte ni message n'est stocké par ce module. Publications, messagerie et commerce restent contractuels dans [FANS.md](FANS.md).

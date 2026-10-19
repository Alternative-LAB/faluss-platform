# Followers Fans — suivi local minimal

## Contrat implémenté

Le module opt-in `fans-followers` ne s'exécute que sur le rôle `fans`, après `fans-sso` et `fans-creator-profiles`. Un `subscriber` lié au SSO Fans peut suivre un profil créateur `active` autre que le sien. L'action répétée est idempotente ; la clé primaire composée interdit les doublons. Le retrait reste possible lorsque le profil est ensuite suspendu. Aucun utilisateur non lié ou rôle privilégié ne peut écrire une relation.

La table InnoDB `${prefix}faluss_fans_follows` contient seulement le `wp_user_id` local du follower, l'UUID public du créateur et la date de création. Le `faluss_id` et l'e-mail ne sont ni copiés ni exposés. L'option `faluss_fans_followers_schema_version=1` n'est écrite qu'après vérification du schéma. Le flag `FALUSS_PLATFORM_FANS_FOLLOWERS=true` est une configuration serveur hors Git ; l'activation ou réactivation contrôlée du plugin installe ou vérifie la table après les dépendances. Retirer le flag coupe les routes sans supprimer les relations.

## API

| Route `faluss-fans/v1` | Permission | Réponse |
| --- | --- | --- |
| `GET /creators/{creator_id}/follow` | Membre SSO Fans lié et nonce REST | État privé du suivi courant, sans paramètre destinataire, corps ni cache partagé. |
| `POST /creators/{creator_id}/follow` | Membre SSO Fans lié et `X-WP-Nonce` REST | `{creator_id, following: true}` ; profil inactif, inconnu ou propre au membre refusé. |
| `DELETE /creators/{creator_id}/follow` | Même permission | `{creator_id, following: false}` ; idempotent, y compris après suspension. |
| `GET /creators/{creator_id}/followers/count` | Public si profil `active` | `{creator_id, count}` ; aucune liste de followers ni identifiant local. |

Le service revérifie la preuve SSO, le format de l'identifiant et l'état public du profil côté serveur ; les routes privées réutilisent la permission REST avec nonce du module Profils. Le compteur public n'accorde aucun droit. Lorsque les métadonnées des [messages automatiques](FANS-AUTOMATIC-MESSAGES.md) sont explicitement préparées et la politique attestée, une insertion effective enregistre dans la même transaction la bienvenue unique, sans envoi synchrone. Aucun événement Hub/Federation ni droit commercial n'en découle.

La fiche publique UI peut lire ce compteur après avoir validé son profil actif.
Elle conserve le compteur existant sans valeur de secours. Le membre lié non
propriétaire dispose aussi du formulaire natif « Suivre / Ne plus suivre » :
nonce dédié au Créateur, POST uniquement et redirection303 vers la même fiche.
Aucune option de consentement Fan, aucun abonnement payant ni liste de membres.
Voir le
[contrat de lecture UI](FANS-READING-UI.md#nombre-public-de-suivis).

## Preuves et limites

Les tests couvrent l'isolation de rôle et de schéma, la création unique, le rejeu, le retrait répété, le refus de l'auto-suivi, d'un membre non lié et d'un profil suspendu, l'échec de stockage et l'absence d'identités dans le compteur. Une recette locale WordPress/MariaDB a confirmé la table InnoDB et la fermeture des routes au retrait des flags, en conservant la table. Les clés et courses simultanées réelles, les nonces REST des routes Followers et la suspension simultanée d'un profil restent à exercer.

Cette relation locale ne constitue pas une expérience sociale prête à ouvrir. Le blocage entre membres, les signalements, la suppression de compte et de ses relations, ainsi que la durée et la purge de rétention ne sont **pas implémentés**. Avant une ouverture sociale complète, définir leurs règles serveur, leurs permissions, leurs tests de retrait et d'accès direct, puis ajouter dans des PR distinctes les règles de visibilité et l'intégration aux contrats Events/Federation appropriés. Aucun contenu adulte ni message n'est stocké par ce module. Publications, messagerie et commerce restent contractuels dans [FANS.md](FANS.md).

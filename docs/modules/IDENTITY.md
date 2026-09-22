# Faluss Identity

## Périmètre et autorité

Le module `identity` reprend Faluss Identity `0.4.15` sur le seul rôle `me`. Il demeure inactif sans activation explicite :

```php
define('FALUSS_PLATFORM_ROLE', 'me');
define('FALUSS_PLATFORM_IDENTITY', true);
```

`faluss.me` reste l’unique autorité d’identité. Le module refuse de démarrer si une classe du plugin historique est déjà chargée; l’ancien plugin et le module Platform ne doivent donc jamais être actifs dans la même requête. `faluss.com` reste un client SSO et conserve ses utilisateurs, rôles et sessions WordPress locaux.

Les seize sources PHP historiques et les huit assets CSS/JavaScript sont conservés octet pour octet depuis le commit audité `4c84e4bbfc859f9d6c17b1d44a76c79bdbcdadb4`. Les shortcodes, widgets Elementor, routes, actions, options, classes publiques, libellés et sélecteurs historiques restent disponibles. Le lot n’effectue aucune refonte visuelle.

## Données et comportements conservés

| Surface | Contrat conservé |
|---|---|
| Identité | `faluss_id` UUID v4 opaque, stable, unique et non réutilisable, lié à un unique `wp_user_id` local |
| Schéma | huit tables InnoDB historiques : profils, challenges, rate limits, clients, codes, audit, profils publics et demandes d’autorisation |
| Versions | `faluss_identity_schema_version` jusqu’à `6`, diagnostic séparé, migrations FI-02/FI-03/FI-04/ONB-01/FI-06 additives |
| Passwordless | challenge et secret navigateur 256 bits, cookie `Secure`/`HttpOnly`/`SameSite=Lax`, OTP haché, cinq essais, dix minutes et rate limit transactionnel |
| Comptes | compte existant retrouvé avant toute création; un nouveau compte reçoit uniquement `subscriber`; aucun compte privilégié n’est créé ou ouvert par le flux membre |
| Profils publics | slug stable, nom, bio, avatar, statut et liens HTTPS; e-mail absent du stockage et du rendu public |
| Onboarding | état minimal sur le profil Identity existant, poignée navigateur opaque et retours strictement locaux; aucune seconde table d’identité |
| OAuth | Authorization Code à usage unique, 60 secondes, URI exacte, PKCE S256 et secret serveur pour un client confidentiel |
| Consentement | `identity.basic` obligatoire, `identity.email` seulement lorsqu’il est demandé et accordé; le marqueur `first_party` est limité au callback exact de Faluss.com |
| Session membre | une heure fixe pour le seul rôle `subscriber` possédant un profil Identity actif; les challenges et codes conservent leurs durées propres |
| Audit | identifiant d’événement, type, client et dates; aucun e-mail, OTP, code, state, verifier ou secret brut |

L’installation vérifie le schéma avant d’enregistrer les surfaces publiques. Une installation partielle ou incompatible échoue fermée; elle n’est ni réparée en supprimant des tables ni convertie silencieusement. Les tables et options existantes restent la source de vérité : aucun compte, profil, client, consentement ou audit n’est recopié dans un nouveau stockage.

## Contrat avec Faluss Link

`IdentityContract` expose uniquement l’identité active, les mutations Studio déjà publiques, l’onboarding, la navigation et les projections de profils publiés nécessaires à Link. La liste privée des découvertes ne joint plus directement la table Identity : Link lit ses propres identifiants, puis Identity retourne les seuls champs publics autorisés. Le contrat accepte au plus 250 Faluss IDs valides et omet les profils absents ou non publiés.

La transaction Studio reste une transaction MariaDB unique sur `faluss.me` : Identity verrouille et persiste son profil, Link verrouille et persiste sa carte et ses blocs, puis le commit est commun. Aucun `faluss_id`, e-mail, secret ou état OAuth n’est accepté depuis le navigateur pour décider de l’identité.

## Activation, validation et retour arrière

Avant une bascule, travailler sur une copie représentative de `faluss.me` et `faluss.com`, sauvegarder les deux bases, relever les versions/options/tables et désactiver l’ancien plugin Identity avant d’activer `FALUSS_PLATFORM_IDENTITY`. Vérifier ensuite au minimum :

- conservation et rapprochement de tous les comptes, `faluss_id`, profils, profils publics, clients, consentements et audits ;
- passwordless complet, nouvel utilisateur `subscriber`, compte existant, cinq échecs, expiration, rate limit, échec d’envoi et absence de double e-mail ;
- parcours réel `faluss.me` → `faluss.com`, consentement tiers, client `first_party`, refus/révocation, URI altérée, state altéré, code expiré, code rejoué et absence de liaison ou compte dupliqué ;
- migrations MySQL/InnoDB de chaque version historique vers `6`, concurrence réelle, reprise après échec et diagnostic fermé ;
- profils, onboarding, Studio, découvertes, routes, réécritures, cache, Elementor, responsive, clavier et accessibilité ;
- contenu des journaux et absence d’e-mail, OTP, code, state, verifier, secret ou jeton sensible dans les URL et logs.

Ces contrôles réels ne sont pas exécutés par les tests statiques du dépôt et ne sont pas une autorisation de production.

Pour revenir en arrière, remettre d’abord `FALUSS_PLATFORM_IDENTITY` à `false`, charger une nouvelle requête sans les classes Platform, puis réactiver l’ancien plugin Faluss Identity contre les mêmes tables et options. Ne supprimer, renommer, fusionner ou réécrire aucune donnée. La remise en service de deux autorités dans une même requête est interdite.

# Faluss Identity

## Périmètre et autorité

Le module `identity` reprend Faluss Identity `0.4.15` sur le seul rôle `me`. Il demeure inactif sans activation explicite :

```php
define('FALUSS_PLATFORM_ROLE', 'me');
define('FALUSS_PLATFORM_IDENTITY', true);
```

`faluss.me` reste l’unique autorité d’identité. Le module refuse de démarrer si une classe du plugin historique est déjà chargée; l’ancien plugin et le module Platform ne doivent donc jamais être actifs dans la même requête. `faluss.com` reste un client SSO et conserve ses utilisateurs, rôles et sessions WordPress locaux.

Les sources historiques restent conservées, sauf les adaptations ciblées de session membre Me et de liaison de son échéance au code SSO. Le test de caractérisation verrouille leur contenu. Les shortcodes, widgets Elementor, routes, actions, options, classes publiques, libellés et sélecteurs historiques restent disponibles. Le lot n’effectue aucune refonte visuelle.

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
| Session membre | huit heures fixes pour le seul rôle `subscriber` possédant un profil Identity actif ; cookie persistant pour traverser une fermeture du navigateur, sans renouvellement automatique ; les challenges et codes conservent leurs durées propres |
| Audit | identifiant d’événement, type, client et dates; aucun e-mail, OTP, code, state, verifier ou secret brut |

La nouvelle session Me commence au succès du passwordless et garde une échéance serveur fixe de huit heures. Le cookie persistant permet de fermer complètement puis rouvrir le navigateur avant cette échéance. Une visite du Studio, du login ou d'un client SSO ne renouvelle pas cette session ; à l'échéance, Me exige une nouvelle preuve. Les sessions établies avant la mise à jour gardent leur échéance initiale. Une déconnexion explicite sur Me invalide cette session Me ; elle n'est pas une déconnexion globale des sessions WordPress déjà ouvertes sur Hub ou Fans.

Scénarios positifs à vérifier : membre `subscriber` actif encore reconnu sur Me après fermeture du navigateur avant huit heures ; Hub sans session locale qui traverse Me déjà authentifié et revient à sa destination sans e-mail ni OTP ; compte administrateur dont la durée WordPress ne change pas. Scénarios négatifs : membre sans identité active et rôle combiné inchangés ; jeton Me expiré refusé ; aucun cookie Me partagé avec `faluss.com` ; déconnexion Me n'effaçant pas silencieusement une session Hub ou Fans.

Au moment de l'autorisation, Identity lit l'expiration de son cookie `logged_in` et du jeton WordPress serveur pour le membre courant, puis retient la plus proche. Le code d'autorisation conserve exactement 43 caractères : 28 octets aléatoires et quatre octets d'échéance Unix. L'empreinte SHA-256 stockée lie ces octets au client, à l'URI exacte et à PKCE. L'échange refuse une échéance expirée ou supérieure à huit heures dans le futur, puis transmet `session_expires_at` comme entier dans la réponse serveur à serveur. Le navigateur ne reçoit aucun nouveau secret ou champ de profil. Les codes restent à usage unique et expirent au plus tard après 60 secondes, ou plus tôt si la session centrale se termine. Aucune table ni option n'est migrée.

Hub et Fans valident ce champ avant d'ouvrir leur session locale. Hub conserve sa durée locale maximale d'une heure et peut refaire un SSO silencieux tant que Me reste authentifié ; Fans garde son plafond local de huit heures. Chaque nouvelle session locale est raccourcie à l'échéance centrale initiale. Le navigateur conserve des cookies persistants propres à chaque domaine ; la fermeture complète avant échéance n'invalide pas les jetons. Après huit heures, Me ne peut plus émettre de code et les sessions clientes issues de cette preuve sont expirées. Une déconnexion locale détruit seulement le jeton de ce site ; elle ne devient pas une déconnexion globale implicite. Un membre qui se déconnecte de Hub peut encore y revenir par Me tant que sa session centrale reste active. La déconnexion Me n'invalide pas rétroactivement une session Hub ou Fans déjà établie, qui reste cependant bornée par son échéance initiale.

Compatibilité de déploiement : installer d'abord la version Identity sur Me, puis les clients Hub et Fans. Un client mis à jour accepte temporairement une réponse historique sans `session_expires_at` et conserve son ancienne durée locale ; il refuse une valeur présente mal formée, expirée ou supérieure à huit heures. Après mise à jour des trois sites, tout nouveau code émis par Me comporte l'échéance. Un ancien code en vol au moment du remplacement de Me peut échouer pendant au plus 60 secondes : recommencer l'autorisation suffit, sans nouvel OTP si la session centrale est encore valide. Le retour arrière restaure d'abord Me puis les clients ; le format du code et le schéma existant restent compatibles. La validation WordPress/MariaDB réelle et le parcours Hub → Me → Hub restent requis avant publication.

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

La bascule de production a été réalisée le 23 septembre 2026 après correction de l'issue #31. Les huit tables, quatre profils, quatre profils publics, trois clients OAuth et les compteurs d'audit ont été conservés. Link consomme le contrat Identity Platform et les routes publiques, login et OAuth répondent sans seconde autorité active.


## Passwordless : transaction de reprise (0.5.2)

La lecture verrouillée du challenge, la vérification OTP, la création/relecture du compte WP, l’activation Registry et la consommation du challenge appartiennent à une transaction unique. L’ouverture de session reste postérieure au commit. Un échec interne annule les écritures et purge les caches WP utilisateur/meta touchés ; la preuve liée au navigateur reste utilisable jusqu’à son expiration. Les comptes privilégiés ou suspendus restent refusés ; les garde-fous de code remplacé, expiré, consommé et cinq essais restent actifs. Aucune logique SSO n’est changée.

`faluss_identity_passwordless_diagnostic` émet uniquement un nom d’étape borné côté serveur : nonce, cookie, format, challenge, vérification, transaction, WP, Registry ou préférences. Les erreurs après preuve valide sont aussi enregistrées dans l’audit Identity existant, avec son horodatage et sa rétention ; aucune adresse, OTP, cookie, ID de compte ou exception brute n’est ajouté. Les diagnostics nonce/cookie/format ne créent pas de nouvelles lignes d’audit ; l’événement historique `passwordless_code_rejected` reste conservé pour les refus de challenge. Le navigateur reçoit toujours le refus générique. L’audit existant `passwordless_session_opened` distingue l’établissement réussi de session ; la destination reste calculée par les contrats existants.

La recette locale distingue publié, inachevé actif, inachevé pending et nouveau ; elle ne confirme pas la cause de l’incident rapporté en production. Voir [preuves 0.5.2](../evidence/me-v3-052/README.md).

## Confirmation de secret administrateur — correctif du 30 septembre 2026

La création/rotation prépare désormais la réponse native WordPress avant de
modifier le hash, puis affiche le secret uniquement après réussite de l’écriture.
La case officielle reste réservée à l’unique callback exact Faluss.com et est
désactivée pour les fiches Fans. Aucun secret récupérable, aucune migration ni
mutation automatique des clients existants n’est ajouté. Le hash de caractérisation
du seul fichier administrateur est actualisé pour cette correction explicite.
Voir [cause reproduite, tests et procédure de reprise](../incidents/2026-09-30-sso-client-secret-confirmation.md).

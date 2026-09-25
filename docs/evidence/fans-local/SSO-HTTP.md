# Preuves locales Fans — 25 septembre 2026

Base Studio : `7672807698744d84b6f33fa47af904527711d8cf` ; client testé :
`66d1ae25ecd64ec0463c71b77d424b818b69feb2`. Aucun fichier Studio modifié.
Deux instances WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6, bases jetables
distinctes ; vrais services Me et Fans, proxy TLS exclusivement loopback.

## Résultat des assertions HTTP

| Scénario exécuté | Résultat |
| --- | --- |
| Start POST avec nonce, consentement Me, échange serveur PKCE/secret, callback Fans | Session WP locale émise, retour vers Fans |
| Cookie de flux | Secure, HttpOnly, SameSite=Lax ; absence du cookie refusée |
| Callback rejoué avec copie du cookie initial | Refus local, aucune nouvelle session |
| Code Me rejoué directement sur `/oauth/token` | HTTP 400 `invalid_grant` |
| Code expiré en base avant callback | Refus local |
| Même e-mail qu'un compte local existant | Refus, compte préservé, aucune liaison implicite |
| Trigger MariaDB refusant l'INSERT de liaison | Callback refusé ; aucun utilisateur pour l'e-mail fictif après rollback |
| Nouvelle autorisation après retrait du trigger | Connexion réussie avec le même e-mail |
| Échec de liaison explicite à un subscriber préexistant | Compte préservé |
| Deux callbacks HTTPS concurrents du même sujet | Deux connexions réussies ; un compte et une liaison en base |
| Flag Link présent par erreur sur Fans | Aucune table Link |

Les tests unitaires supplémentaires couvrent le cache WordPress après rollback,
la panne InnoDB et l'échec d'acquisition du verrou. La recette HTTP n'a pas modifié
le service SSO : le correctif transaction/cache précédemment apporté fonctionne
sur le `main` Studio actualisé.

## Limites précises

Session Me initiale fournie par fixture ; OTP/e-mail non exercés. Client HTTP curl,
pas recette navigateur physique : les attributs sont observés et le cookie est
échangé, mais les politiques navigateur intersites restent à recetter. Certificat
local explicitement approuvé, aucun certificat ou client de production utilisé.
Pas de cache Redis/Memcached, plugins tiers de création de compte, panne réseau
pendant COMMIT, rotation de secret ou charge prolongée. Le test concurrent porte
sur deux connexions du même sujet, pas toutes les combinaisons de collisions.

Scripts et préconditions : [recette](../../../tests/Fans/Sso/recipe/README.md).
Ces résultats sont une recette WordPress/MariaDB/HTTP réelle **locale**, pas une
preuve de configuration ni de déploiement sur Me ou Fans en production.

# Accès Identity/Me sur v0.14.0 — état au 21 octobre 2026

Mise à jour : Jérémie a attribué la lenteur de connexion à PlanetHoster et indiqué que le service est revenu à la normale. Ce constat opérationnel n'est pas une mesure de cette recette. La notice d'attente de #248 améliore la lisibilité du formulaire, sans corriger ni expliquer la latence passée. Aucun nouvel OTP n'a été demandé pour la reproduire.

## Frontière des preuves

- Hub et Me affichent Faluss Platform `0.14.0` dans WordPress. L'absence de mise à jour proposée ne prouve pas à elle seule les octets installés.
- Lecture seule via l'éditeur de fichiers WordPress : sur Me, `OnboardingV3.php`, `StudioV3.php`, `class-faluss-identity-passwordless.php`, `faluss-identity-passwordless-login.js` et `class-faluss-identity-authorization.php` ont chacun le même SHA-256 que le blob Git `v0.14.0`. Sur Hub, `faluss-platform.php`, `IdentityClientService.php` et `IdentityClientAdmin.php` correspondent également. `LegacyLinkService.php` n'a pu être comparé que sur ses 200 000 premiers caractères, l'éditeur tronquant le reste. Aucun code de #244, #245 ou #247 n'est réputé installé par cette observation.
- Aucune trace horodatée de la tentative OTP réelle, HAR ou accès SQL en lecture seule n'est disponible. Jérémie a attribué la lenteur à PlanetHoster et signalé le retour à la normale ; aucun détail technique ou mesure n'a été transmis. Aucun nouvel OTP n'a été demandé sur Hub ou Me.
- Les essais ci-dessous sont faits sur WordPress/PHP 8.3 et MariaDB 11.8 isolés via Docker Desktop, exposés uniquement sur `127.0.0.1:18087`. L'instance ne dispose pas d'Elementor ni de Brevo.
- Après la réinitialisation de Docker Desktop, PowerShell avec le contexte `desktop-linux` a joint le moteur Docker Desktop 29.6.1 via `docker version` et `docker info`. WSL avec le contexte `default` a joint un moteur snap Docker 29.8.0 distinct. Le premier `docker version` WSL a échoué avec `failed to connect to the docker API at unix:///var/run/docker.sock; check if the path is correct and if the daemon is running: dial unix /var/run/docker.sock: connect: no such file or directory` ; `docker info` et la répétition de `docker version` ont réussi sans changement de permissions. La recette ci-dessous utilise le moteur Windows `desktop-linux`.

## SSO Hub → Me

Le relevé antérieur montrait un identifiant Hub absent des quatre clients alors affichés sur Me. La recette WordPress/MariaDB isolée a reproduit le refus `invalid_client` d'un client inconnu ; elle ne prouve pas l'état actuel de la production.

Après l'intervention de Jérémie, un nouveau départ réel depuis le bouton « Continuer avec Faluss » sur Hub a atteint `/oauth/authorize` puis `/login/` sur Me. Dans le code v0.14.0, ce passage exige d'abord un client Me actif, le callback exact et les scopes demandés. La discordance antérieure n'est donc plus observée à l'étape d'autorisation. La session de ce navigateur n'était pas authentifiée sur Me : le secret serveur, l'échange du code et le retour Hub n'ont pas été vérifiés. Aucun réglage de production ne doit être modifié sur la seule base de l'ancien relevé.

Contrat à confirmer avant toute intervention : client Me **actif et confidentiel** réservé à Hub, callback exact HTTPS `https://faluss.com/faluss-identity/callback`, scopes `identity.basic` et `identity.email`, identifiant identique sur Hub et secret serveur `FALUSS_IDENTITY_CLIENT_SECRET` présent hors Git et hors options exportables. Le passage à `/login/` confirme le client actif, son callback et ses scopes au moment de cette requête, mais ni son `client_secret_hash` ni la présence de la constante Hub. Le choix `first_party` doit être explicite et limité au callback unique autorisé.

Avant tout changement, relever en lecture seule et sans divulgation le caractère confidentiel du client Me et la **présence**, sans valeur, de la constante secrète sur Hub. Si un défaut est démontré, conserver l'ancien réglage et le secret Hub dans un canal opérationnel sûr ; modifier uniquement le champ défectueux via l'administration/API WordPress ou la configuration serveur prévue ; tester le retour réel Hub → Me → Hub. Retour arrière : restaurer uniquement ce champ à sa valeur précédente. Aucune écriture de configuration n'a été effectuée sur les sites réels.

## `/mon-faluss/` et sessions distinctes

Sur Me v0.14.0, la visite invitée aboutit à une étape « Retrouve ton Faluss ». Le compte administrateur WordPress observé reçoit `identity_unavailable` en texte brut ; une autre session membre a accédé au Studio. Dans `LegacyLinkService::studio_v2_state()`, ce code apparaît **après** les contrôles de session, schéma Identity, contrat Studio et schéma de composition : `currentFalussId()` ne fournit alors pas d'UUID actif valide. Un contexte V3 indisponible et un état de composition invalide ont d'autres chemins d'erreur. Le code ne permet pas de savoir si ce compte administrateur n'a jamais eu de ligne Identity, si elle est `pending`/suspendue, ou si la résolution échoue pour une autre raison : il faut une lecture SQL ciblée de la ligne de ce compte, sans copier le Faluss ID, l'e-mail ni les tokens dans le rapport.

Le correctif de cette branche redirige l'invité vers `/login/` avec un flux opaque `open_studio` et un retour local vers `/mon-faluss/`. Il affiche une erreur compréhensible et stylée pour une session sans identité active, avec un diagnostic serveur réduit à un code et au caractère administrateur de la session. Il distingue `v3_context_unavailable` de `identity_unavailable`. Il ne crée pas silencieusement d'identité pour un administrateur.

Recette isolée : GET invité `/mon-faluss/` → HTTP 302 `X-Redirect-By: Faluss Me`, destination locale `/login/?faluss_identity_flow=…` sans identité dans l'URL, puis HTTP 200 sans boucle. Le flux `open_studio` retourne à `/mon-faluss/` après authentification simulée du membre, est consommé une fois, et un retour externe est rejeté. La session administrateur sans identité reçoit HTTP 200, le titre « Ce compte n’a pas accès au Studio », la feuille V3 dans le HTML et aucun `identity_unavailable` affiché. La session membre avec identité active reçoit HTTP 200 et l'étape V3 « Choisis ton rythme ». Le serveur a journalisé `unavailable=identity_unavailable; wp_admin=yes` sans identifiant personnel. La publication et la carte publique de ce membre synthétique n'ont pas été exercées dans cette recette d'incident.

## Attente « Recevoir mon code »

Le code installé v0.14.0 vide la notice dès le clic et attend la réponse AJAX. Le correctif affiche immédiatement « Envoi en cours… » (ou « Vérification en cours… »), conserve le bouton désactivé et `aria-busy`, puis affiche l'étape OTP ou l'erreur seulement après réponse. Un test JavaScript avec réponse différée vérifie la notice, l'absence de double soumission et le réarmement du bouton. Sur WordPress isolé, un filtre local `pre_wp_mail` a attendu cinq secondes puis refusé l'envoi : Chrome a montré la notice en cours et le bouton désactivé, ensuite une erreur lisible et le bouton réactivé. Aucun message n'a été envoyé par cette simulation. Elle ne mesure pas les dix secondes de production.

Captures de la recette locale, avec le champ de test vidé avant la prise de vue : [pendant la requête](passwordless-pending-isolated.png) et [après l'échec simulé](passwordless-error-isolated.png). Le thème est Twenty Twenty-Five, sans Elementor ; ces images ne prouvent pas l'apparence du site réel.

La simulation locale confirme uniquement le comportement visuel pendant une réponse différée. Elle ne mesure pas l'incident PlanetHoster, désormais clos selon Jérémie, et ne justifie aucun changement serveur de performance.

## Vérifications et décision

- `php -l` sur chaque PHP modifié : OK.
- Contrats `faluss-identity-onb01-contract-test.php` et `faluss-identity-passwordless-contract-test.php` : OK.
- PHPUnit `tests/Identity tests/MeStudio tests/Link` : 115 tests, 739 assertions, OK avec dépendances Composer copiées localement dans le checkout de test.
- `node tests/Identity/passwordless-pending-feedback.cjs` : OK.
- Analyse PHPStan ciblée sur les quatre classes de module modifiées : OK. L'analyse globale reste en échec sur 11 diagnostics hors de ces fichiers (`Core/UpdateClient.php` et `TokenEngineConnector/ConnectorCrypto.php`) avec la copie locale de `vendor` ; aucun de ces fichiers n'est modifié par cette branche.
- PHPUnit complet avec extensions `mbstring` et `sodium` disponibles dans les sous-processus : 821 tests, 8 534 assertions, 2 ignorés et 3 échecs de caractérisation préexistants sur des fichiers inchangés (`faluss-portal.css`, `class-faluss-subscriptions-admin-notices.php`, `class-token-engine-admin.php`). Ces échecs ne sont pas masqués et devront être résolus par leurs propriétaires avant un contrôle global entièrement vert.
- `git diff --check` et scan ciblé des chaînes de secret : aucun résultat.
- Aucune fusion, étiquette, publication, mise à jour WordPress, modification de CI, de permissions ou de données de production. #247 reste ouverte. Une publication v0.15.0 reste bloquée par la validation réelle Hub → Me → Hub et par la vérification des fichiers effectivement déployés après toute future mise à jour.

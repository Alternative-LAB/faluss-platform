# Recette SSO HTTPS locale

Ces scripts sont **des fixtures locales**, jamais des fichiers à installer dans
WordPress ou en production. Ils utilisent les noms de bases fermés
`faluss_fans_recipe` et `faluss_fans_me_recipe`, un dossier jetable
`/var/tmp/faluss-fans-http`, et des utilisateurs `@example.test`.

Préconditions utilisées le 25 septembre 2026 : deux WordPress 7.1.2 frais,
MariaDB 11.8.6, PHP 8.5.4 avec mysqli/curl, WP-CLI et curl. Me est configuré
`role=me`, Identity seul ; Fans utilise le module de cette branche, SSO opt-in,
client `fans-local-recipe`. Un secret aléatoire de 32 octets base64url est généré
hors Git et stocké dans le fichier local `secret` et la constante serveur Fans.
Le plugin doit être activé et les réécritures installées sur les deux instances.

1. Utiliser les homes HTTPS `faluss.me` et `fans.local.test` **uniquement dans ces
   bases locales**. Ne pas modifier DNS ou hosts système.
2. Créer un certificat local `cert.pem`/`key.pem` avec SAN pour ces deux hôtes.
   Le proxy fourni écoute exclusivement `127.0.0.1:443` et route vers les serveurs
   PHP locaux 8101 (Me) et 8102 (Fans), chacun avec quatre workers. Il ne journalise
   pas les URL. Le routeur PHP local définit HTTPS et le port 443 avant WordPress.
3. Pour le POST serveur WordPress, utiliser un MU-plugin **jetable** : hook
   `http_api_curl` avec `CURLOPT_RESOLVE=['faluss.me:443:127.0.0.1']` ; filtre
   `http_request_args` avec `sslcertificates` pointant sur `cert.pem`. Conserver
   `sslverify=true`. Bloquer tout autre HTTP sortant par `pre_http_request`.
4. Créer la page locale `/sso-local/` avec `[faluss_fans_sso_button]`. Exécuter
   `fans-me-fixture.php` par WP-CLI `eval-file` dans Me, puis
   `fans-local-fixture.php` dans Fans. Les deux fixtures refusent une autre base.
5. Lancer le proxy puis `python3 fans-http-test.py`. Chaque appel curl force
   l'adresse loopback et vérifie le certificat ; aucune requête aux sites réels.
   Les fichiers de cookies et les secrets restent dans le dossier jetable.
6. Arrêter les serveurs, retirer les flags Fans et vérifier le retrait des routes
   dans une nouvelle requête ; les tables et liaisons doivent être conservées.
   Détruire ensuite les bases et fichiers jetables selon la procédure locale.

Les assertions couvrent succès, cookie absent, attributs du cookie de flux,
rejeu du callback et du code Me, collision, panne SQL injectée, absence d'orphelin,
compte préexistant, nouvelle tentative, callbacks parallèles et code expiré.
La concurrence utilise deux processus curl et plusieurs workers PHP sur MariaDB,
avec deux autorisations pour la même identité, pas une simulation de `$wpdb`.

La session initiale Me est une fixture WordPress réelle créée par
`wp_generate_auth_cookie`, avec identité active fictive. **Aucun envoi OTP** ni
prestataire e-mail n'est testé ici. curl teste les attributs/cookies et HTTPS,
pas l'application de SameSite par Safari/Chrome. Les hooks d'extensions tierces,
cache objet persistant et pannes de connexion au COMMIT restent à vérifier avant
activation sur une installation représentative.

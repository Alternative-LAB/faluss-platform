# Préparation de la livraison Faluss.me V3 — 25 septembre 2026

## État vérifié avant fusion

La PR #52 reste en brouillon. Sa branche `feat/onboarding-v3-native` contient le `main` observé au SHA `5237e80bff3739606f547cd803aae04f184a25a5` ; ce constat doit être revérifié immédiatement avant une fusion future. WordPress sur `faluss.me` affiche Faluss Platform **0.3.3 actif**, Elementor 4.3.1 et l'action du client privé « Vérifier les mises à jour ». L'accès au fichier de configuration de ce site existe dans le gestionnaire de fichiers ; aucune valeur de secret n'est reproduite ici. Aucun flag V3, plugin ni donnée de production n'a été modifié pendant cette préparation.

La branche prépare Faluss Platform **0.4.0** dans l'en-tête du plugin, la constante et le changelog. Le workflow [`publish-private-release.yml`](../../../.github/workflows/publish-private-release.yml) ne construit, ne tague et n'envoie l'archive au serveur `updates.faluss.com` qu'après une fusion sur `main` modifiant `faluss-platform.php`. Cette publication reste donc en attente.

## Archive candidate locale

Un export Git propre du commit de préparation `2776496cb2b78c40ba764f630ee2a6a18d43bd2c` a servi à reproduire les exclusions du workflow, puis `composer install --no-dev --classmap-authoritative` a installé Stripe PHP 21.3.0 et Plugin Update Checker 5.7. Le ZIP candidat local n'est **pas** l'archive officielle du workflow et n'a pas été envoyé au serveur de mises à jour.

- Chemin local WSL : `/var/tmp/faluss-v3-clean-release.fw9Zc6/faluss-platform-0.4.0-candidate.zip`.
- SHA-256 : `9a816e5d17f45b7cbf7749c64e7613fd470875b13866cff7043c97b8b672e1e0` ; environ 5,1 Mo et 1 035 entrées.
- `unzip -t` réussi, racine unique `faluss-platform/`, en-tête et constante `0.4.0` cohérents.
- Présence de `faluss-platform.php`, `vendor/autoload.php`, Plugin Update Checker et Stripe ; absence de `tests/`, `.git/`, `.github/`, `wp-config.php`, `.env`, `auth.json` et dépendances PHPUnit/PHPStan.
- `php -l` réussi ; `PluginVersionTest` et `ReleasePreparerTest` : 4 tests, 15 assertions ; `git diff --check` et scan de signatures de secrets ciblé : OK.

## Barrière de sauvegarde et procédure retenue

Les [incidents de mise à jour](../../incidents/2026-09-24-scripted-update-deactivation.md) documentent une désactivation et, lors d'un autre essai, la disparition du répertoire du plugin après un appel direct à `Plugin_Upgrader::upgrade()`. Cette voie scriptée reste exclue. Le remplacement prévu utilise l'interface interactive WordPress et l'archive officielle issue du workflow, après contrôle de sa version, de sa racine et de ses dépendances.

Les documents historiques citent des sauvegardes de fichiers et de bases sur l'hôte d'administration, notamment `/root/faluss-production-backups/2026-09-24-private-updater`. L'accès navigateur disponible ne présente que les fichiers du site dans `Files/Sites` ; aucun accès à ces sauvegardes hors site, à une archive SQL récente ou à un environnement de restauration n'a été établi. Ni leur date exacte actuelle, ni leur intégrité, ni la restauration du plugin et de la base ne peuvent être vérifiées. **Ce prérequis bloque la fusion**, car celle-ci déclencherait la publication automatique de `0.4.0` et l'installation serait sans retour arrière démontré.

Avant la fusion : obtenir l'accès à l'hôte ou fournir des archives récentes du dossier `faluss-platform`, du fichier de configuration et de la base `faluss.me` ; relever leurs horodatages et empreintes ; tester la lecture de chaque archive et une restauration sur une copie isolée, puis vérifier que WordPress démarre avec le plugin précédent et la base restaurée. Confirmer aussi que le site de mises à jour et l'interface WordPress permettent l'installation du paquet officiel. Refaire le contrôle d'ascendance sur `main` et exiger tous les contrôles CI verts sur le nouveau SHA de la PR.

Une fois ces preuves réunies, la séquence autorisée est : fusion GitHub de la PR ; vérification du tag, de l'archive officielle et de sa distribution ; installation interactive de `0.4.0` avec V3 désactivé ; contrôles du site, de la connexion, des profils publics, d'Elementor et de l'administration ; activation du flag **uniquement ensuite** ; recette Simple et Atomique avec de vrais e-mails OTP et médias officiels, mobile physique, uploads, publication et comparaisons des rendus. Conserver les HTTP, réponses brutes et logs horodatés de chaque échec. En cas d'échec V3, désactiver d'abord son flag ; si un fatal l'empêche, restaurer le dossier du plugin précédent. Ne restaurer la base que si une mutation de données le nécessite. Aucune étape de cette séquence de production n'a encore été exécutée.

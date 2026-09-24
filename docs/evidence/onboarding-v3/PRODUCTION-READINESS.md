# Préparation de la livraison Faluss.me V3 — 25 septembre 2026

## État vérifié avant fusion

La PR #52 reste en brouillon. Sa branche `feat/onboarding-v3-native` contient le `main` observé au SHA `5237e80bff3739606f547cd803aae04f184a25a5` ; ce constat doit être revérifié immédiatement avant fusion. WordPress sur `faluss.me` affiche Faluss Platform **0.3.3 actif**, Elementor 4.3.1 et l'action du client privé « Vérifier les mises à jour ». Sur la ligne Faluss Platform, « Activer les mises à jour auto » prouve que les mises à jour automatiques de ce plugin sont actuellement **désactivées** : publier `0.4.0` ne déclenchera pas son installation par cette fonction WordPress. Aucun secret de configuration n'est reproduit ici. Le flag V3 et le dossier actif du plugin n'ont pas été modifiés pendant cette préparation.

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

Avant fusion, une copie serveur du **dossier installé** `0.3.3` a été créée via le gestionnaire de fichiers à `Files/Faluss-Platform-Backup-2026-09-25/faluss-platform/`, hors de `Files/Sites/faluss.me/`. Le gestionnaire confirme la copie, une taille de 10,3 Mo, les dossiers `vendor/`, `src/`, `assets/` et l'en-tête ainsi que la constante `0.3.3` dans `faluss-platform.php`. En complément, l'artefact du workflow officiel `publish-private-release.yml` de `0.3.3` (run `36046045257`) a été téléchargé localement : `dist/official-0.3.3/faluss-platform.zip`, 987 entrées dont les 857 fichiers ont été décompressés et lus sans erreur, racine `faluss-platform/`, en-tête et constante `0.3.3`, SHA-256 `ed26943c85c1b60fe55c0ecd95451a64b01aff6beb47a3233119a088dbd2888a`. Cet artefact officiel est distinct d'une archive de la copie du dossier installé ; l'export ZIP/TAR.GZ de ce dossier depuis le gestionnaire a été bloqué par le navigateur. La copie serveur et l'archive officielle sont deux voies de retour aux fichiers `0.3.3`, sans preuve de restauration effective.

Les documents historiques citent des sauvegardes de fichiers et de bases sur l'hôte d'administration, notamment `/root/faluss-production-backups/2026-09-24-private-updater`. L'accès disponible ne présente aucun export SQL récent ; aucun accès à ces sauvegardes hors site ni à un environnement de restauration n'a été établi. Leur disponibilité actuelle, leur intégrité et la restauration de la base restent **non vérifiées**. Le propriétaire du site a explicitement accepté ce risque le 25 septembre 2026 et a levé la condition de restauration sur copie isolée pour la livraison de la PR #52. Cette limite doit rester visible dans le compte rendu final.

Avant la fusion : refaire le contrôle d'ascendance sur `main` et exiger les cinq contrôles CI requis verts sur le nouveau SHA de la PR. Vérifier aussi que les mises à jour automatiques du plugin restent désactivées. Après la fusion, confirmer que le tag et l'archive officiels `0.4.0` sont publiés par le workflow avant toute installation interactive dans WordPress.

La séquence autorisée est : fusion GitHub de la PR ; vérification du tag, de l'archive officielle et de sa distribution ; installation interactive de `0.4.0` avec V3 désactivé ; contrôles du site, de la connexion, des profils publics, d'Elementor et de l'administration ; activation du flag **uniquement ensuite** ; recette Simple et Atomique avec de vrais e-mails OTP et médias officiels, mobile physique, uploads, publication et comparaisons des rendus. Conserver les HTTP, réponses brutes et logs horodatés de chaque échec. En cas d'échec V3, désactiver d'abord son flag ; si un fatal l'empêche, restaurer le dossier du plugin précédent. Ne restaurer la base que si une mutation de données le nécessite. Aucune fusion, installation ou activation V3 de production n'a encore eu lieu au moment de cette note.

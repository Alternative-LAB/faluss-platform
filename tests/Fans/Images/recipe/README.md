# Recette locale jetable — images Fans

## Périmètre et résultat

Le 25 septembre 2026, **59 contrôles HTTP WordPress/MariaDB réels réussis** sur
la branche `feat/fans-image-quarantine`, avant son commit documentaire/code final.
WordPress 7.1.2, PHP 8.5.4 avec GD/Fileinfo, MariaDB 11.8.6 InnoDB, quatre workers
PHP, cookies et nonces WordPress réels, données et identités synthétiques.
Les tests PHPUnit (7 tests / 50 assertions ciblés) ne sont pas inclus dans ce total.

- Refus anonyme, non lié, profil non actif, admin sans profil créateur et nonce absent.
- PNG/JPEG décodés ; SVG, GIF, données PHP, PNG tronqué, dimensions excessives,
  corps >2 Mio refusés ; MIME/nom forgés n’influencent pas le format conservé.
- Aucun fichier ni métadonnée dans la médiathèque, aucun chemin en réponse.
  Même le créateur ne reçoit pas les octets ; admin avec nonce seulement.
- Chemin forgé, paramètres tmp_name/JSON et lien symbolique refusés. Fichier opaque
  réel mode 0600 dans un répertoire 0700 hors racine web. Aucune image servie via
  les chemins HTTP directs testés : la fausse URL privée répond HTML 404, la fausse
  URL uploads peut répondre HTML 200 via ce routeur ; aucune ne contient les octets,
  et aucun fichier n’existe à ces chemins dans la racine web. Cette preuve ne
  remplace pas l’inspection des alias/configurations d’un véritable hébergeur.
- Approbation conservant la confidentialité ; révision périmée refusée, journal
  d’acteur/motif ; suspension révoquant les octets ; retrait autorisé après suspension.
- Rejet et retrait suppriment les fichiers ; une réactivation ne restaure rien.
- Trigger SQL de panne du journal : nouvelle soumission annulée et fichier supprimé ;
  rejet annulé sans effacer l’image encore valide. Triggers retirés en finally.
- Mode de répertoire non privé, attestation absente et racine web : admission 503.
- Mode de fichier invalide empêchant unlink : retrait commité, octets déjà 404,
  réponse cleanup_required ; permissions restaurées puis cleanup supprime le fichier.
- Quatre soumissions concurrentes pour la cinquième place : une 201, trois 429.
  Approbation/retrait simultanés : une 200 et une 409 ; retrait final irréversible.
- Débit 20/heure et 60/24 h malgré les retraits ; heures des seules traces synthétiques
  déplacées à -2h pour exercer le quota quotidien, sans attendre 24 heures réelles.
- 100 fichiers orphelins synthétiques comptent dans le plafond physique ; nettoyage
  administratif les supprime. Aucun fichier restant en quarantaine en fin de recette.
- Flag images désactivé, routes fermées ; serveur et ses quatre workers arrêtés.

## Préparation et exécution

**Uniquement sur l’instance locale jetable**, jamais sur un site existant contenant
des données réelles. La fixture exige WP-CLI et `DB_NAME=faluss_text_publications_recipe`,
base jetable déjà utilisée pour les textes. Aucun import ni accès de production.

1. Utiliser le code de cette branche sur `/var/tmp/faluss-text-publications-recipe`,
   avec les modules Fans SSO/profils configurés localement et leurs schémas installés.
   Le flag texte peut rester désactivé. Client SSO fictif ; aucun échange Me réel.
2. Répertoire dédié `/var/tmp/faluss-image-quarantine`, propriétaire du processus PHP,
   mode 0700 ; un autre répertoire temporaire PHP privé
   `/var/tmp/faluss-image-upload-tmp`, même propriétaire/mode. Pas de symlink/alias HTTP.
3. Configurer hors Git `FALUSS_FANS_IMAGE_PRIVATE_ROOT` et
   `FALUSS_FANS_IMAGE_STORAGE_ATTESTED=true` après inspection de cette instance.
   Activer temporairement `FALUSS_PLATFORM_FANS_IMAGES=true`, puis appeler explicitement
   `Faluss\Platform\Fans\Images\ImagesModule::activate()` via WP-CLI.
4. Exécuter `wp eval-file <repo>/tests/Fans/Images/recipe/fixture.php`.
   Les sessions restent mode 0600 dans `/var/tmp/faluss-image-proof/sessions.json`,
   hors document root et Git ; ne jamais les joindre à une PR.
5. Servir uniquement `127.0.0.1:8113` avec `PHP_CLI_SERVER_WORKERS=4`, GD/Fileinfo,
   `upload_tmp_dir` privé, `upload_max_filesize=4M`, `post_max_size=8M` pour exercer
   le refus applicatif 413 au-delà de 2 Mio. OPcache local désactivé pour les changements
   de configuration de recette. Le routeur fixe HTTPS/host pour WordPress mais le
   transport reste **HTTP loopback, pas TLS**. Bloquer les HTTP sortants par MU-plugin,
   désactiver cron et les redirections canoniques locales. Le client Python refuse
   de suivre les redirections ; aucune requête vers fans.faluss.me, Me ou Hub.
6. Lancer `python3 <repo>/tests/Fans/Images/recipe/rest-test.py` avec l’accès socket
   MariaDB de la base synthétique. Le script utilise le phar WP-CLI
   `/var/tmp/faluss-v3-wp/wp-cli.phar`, à adapter sur une autre machine.
7. **Dans un finally externe même en cas d’échec du script**, remettre le flag images
   à false et arrêter tout le groupe de processus PHP. Vérifier configuration, routes
   et absence d’écoute sur 8113. Ce nettoyage a été effectué pour chaque tentative.

Pour une répétition, vider seulement les deux tables images de cette base jetable
et les fichiers UUID .bin du répertoire dédié vérifié, puis renouveler les sessions
via la fixture. Ne pas effacer les schémas SSO/profils ni les tables texte. Le test
crée des triggers et 100 fichiers orphelins synthétiques : rester strictement local.

## Limites

Aucune preuve TLS, navigateur physique, CDN, alias de production, filtre sémantique,
charge prolongée, disque réellement plein, bascule/reconnexion SQL au commit ou
résistance aux vulnérabilités GD. Les pannes couvertes sont SQL, permissions/configuration
privée et suppression ; pas une simulation d’effacement physique de sauvegardes.
La concurrence est réelle entre workers locaux sur le même MariaDB/volume.
Les temporaires proxy/PHP existent avant les contrôles métier ; voir
[le contrat et les règles de rétention ouvertes](../../../../docs/modules/FANS-IMAGES.md).
L’activation temporaire est une opération de test locale ; aucune activation,
publication, release, paiement ou diffusion d’image en production n’a été effectuée.

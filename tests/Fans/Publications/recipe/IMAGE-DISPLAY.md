# Recette locale jetable — affichage d’image Fans

Le 26 septembre 2026, **77 contrôles HTTP réels réussis** sur la branche
`feat/fans-image-delivery`. WordPress 7.1.2, PHP 8.5.4 GD/Fileinfo, MariaDB 11.8.6
InnoDB, quatre workers PHP sous UID `www-data`. Données et liaisons SSO synthétiques,
cookies/nonces WordPress réels ; affichage public testé sans cookie.

## Lancer uniquement en local

`python3 tests/Fans/Publications/recipe/image-display-test.py`, sous Linux root.
Préparation identique à la [recette association](IMAGE-ASSOCIATION.md) : WP local,
DB_NAME=`faluss_text_publications_recipe`, symlink vers cette branche, module MU
offline bloquant les HTTP sortants, schémas existants installés, port 8113 libre.
Le script exige le schéma texte v3, ne migre aucune base et refuse des dossiers
de recette déjà présents. Il réinitialise les six tables texte/images synthétiques
de cette base jetable, jamais les tables d’un site de production.

Racine privée dédiée `/var/tmp/faluss-display-images` et temporaire upload
`/var/tmp/faluss-display-upload`, UID PHP/0700 ; fichiers de quarantaine 0600.
Sessions mode 0600 hors racine web et Git. Compte SQL socket `www-data` temporaire,
limité à la base jetable, supprimé en finally ; redirections HTTP interdites.
Écoute `127.0.0.1:8113` uniquement. Le routeur simule HTTPS pour WordPress mais
le transport testé est **HTTP loopback, pas TLS**.

## Cas vérifiés

- Flag diffusion fermé par défaut malgré textes/quarantaine actifs ; ouverture
  temporaire locale explicite. Pending refusé malgré une image approuvée.
- JPEG public 1280 × 640 à partir d’un PNG privé 1800 × 900 ; octets différents,
  aucun fichier dérivé créé, nombre d’attachments WordPress inchangé, aucun chemin
  ou UUID image dans les en-têtes. Aucune ouverture de la route PNG de quarantaine
  pour anonyme, propriétaire ou autre membre.
- `no-store` et absence de cache partagé, CORP same-origin, absence de CORS permissif
  pour Origin Me ; HEAD sans corps, conditionnelles sans 304, Range et query refusés.
- Fausses URL uploads/stockage privé et chemin forgé : aucun PNG source ou JPEG
  dérivé. Certaines fausses URL sont rendues par le routeur WordPress en HTML ;
  cette preuve ne prétend pas qu’elles retournent toutes 404.
- Texte approuvé sans association refusé ; références SQL synthétiques vers une
  image étrangère/pending ou une révision périmée refusées indépendamment de l’API
  d’association. Catégorie adulte forgée en base refusée.
- Hash incorrect, données corrompues avec hash concordant, mode de fichier 0644,
  fichier absent, symlink et attestation absente : refus sans octet source de secours.
  Schéma d’association renommé temporairement : route fermée puis reprise après
  restauration. Configurations et fichiers restaurés dans les finally ciblés.
- Verrou de génération occupé par une autre connexion SQL : 503 sans octet.
  Édition non commitée tenant la ligne publication : la requête HTTP attend puis
  lit la nouvelle révision pending après commit, sans utiliser l’ancienne approbation.
- Édition texte, remplacement et détachement révoquent l’ancienne URL ; ancienne
  URL toujours refusée après réapprobation. Nouveau dérivé aux pixels différents
  après remplacement par une image distincte, sans cache de l’image précédente.
- Courses réelles GET contre retrait/rejet image, rejet/retrait texte et suspension
  de profil : le GET concurrent peut finir avec 200 ou 404 selon l’ordre ; la
  demande suivante est refusée. La suspension synthétique utilise le même UPDATE
  InnoDB que le service Profils. Réactivation du profil réautorise les approbations
  inchangées, jamais les éléments retirés.
- Flag diffusion fermé : route publique absente, quarantaine administrative
  toujours disponible. Fermeture du flag quarantaine : diffusion également absente.

Une première passe a été interrompue par le test d’attente SQL : arrêter le client
du verrou nommé ne garantissait pas la fin immédiate de sa requête serveur. Le
script attend désormais explicitement sa fin avant le cas suivant. La passe
complète ci-dessus a été rejouée avec succès, sans modification de règle métier.

## Fin de recette vérifiée

Images restantes retirées par REST, racine de recette vide, aucun dérivé persistant.
Flags diffusion, images et textes **false**, configuration DB/racine/attestation
restaurée, compte SQL temporaire supprimé, serveur et ses workers arrêtés. Aucune
écoute 8113 après recette ; aucun accès, activation ou déploiement en production.

## Limites

Pas de validation TLS, navigateur physique, CDN/cache inverse ou alias de production.
Le test direct de fichiers ne remplace pas l’attestation d’hébergement. Pas de
nouvel échange SSO Me, charge prolongée, OOM réel, crash PHP ou bascule/déconnexion
SQL au commit. Les courses utilisent une barrière client et des verrous InnoDB
réels, sans prétendre couvrir tous les ordonnancements. Aucun effacement de copie
déjà téléchargée n’est possible. Les durées de rétention, sauvegardes, modération,
droits de diffusion et conditions d’activation restent celles à décider dans le
[contrat de diffusion](../../../../docs/modules/FANS-IMAGE-DELIVERY.md).

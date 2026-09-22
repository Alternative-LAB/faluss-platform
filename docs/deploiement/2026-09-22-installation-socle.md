# Installation initiale du socle en production

Le 22 septembre 2026, `faluss-platform` issu de `main` au commit
`6e336da105ad4d385ee5fe199fac379053593db1` a été installé et activé
sur `faluss.me` et `faluss.com`. Le paquet contient le code suivi par Git,
les assets et les dépendances Composer de production. Son archive porte le
SHA-256 `87bd002be3d35b237f518cacb3ae188fb3ab9eb37287aff54661bcc01a328d11`.

La configuration non versionnée définit `FALUSS_PLATFORM_ROLE` à `me` sur
`faluss.me` et à `hub` sur `faluss.com`. Aucun indicateur de module métier
`FALUSS_PLATFORM_*` n'a été activé. Les anciens plugins conservent toutes
leurs responsabilités ; cette installation n'est pas une bascule de module.

## Sauvegarde et vérification

Des exports MariaDB cohérents des deux sites et des copies de leurs
`wp-config.php` sont conservés dans le répertoire privé
`/opt/backups/faluss-platform-migration-20260922` du conteneur Incus `faluss`.
Les deux archives SQL ont passé `gzip -t`. Les sauvegardes ne sont pas
versionnées. La restauration complète des deux bases n'a pas encore été
éprouvée : elle reste une condition avant une bascule de données.

Après activation, les deux WordPress chargent le plugin et le rôle prévu,
les anciens plugins restent actifs, les pages d'accueil HTTPS répondent 200
et les conteneurs WordPress et MariaDB sont `healthy`.

## Retour arrière de cette installation

Sur chaque site concerné, désactiver `faluss-platform` dans WordPress,
restaurer le `wp-config.php` sauvegardé, puis retirer le répertoire
`wp-content/plugins/faluss-platform`. Vérifier la page d'accueil, les
plugins historiques actifs et la santé des conteneurs. La sauvegarde SQL
n'est utile que si une mutation de données a été constatée ; le socle seul
n'en effectue pas.

## Suite

Valider les modules sur des copies représentatives avant chaque bascule.
Après la bascule d'un module, désactiver son plugin historique et garder
ses fichiers pour le retour arrière. La suppression ne vient qu'après les
contrôles de parité et de stabilité propres à ce module.

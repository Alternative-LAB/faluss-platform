# Cause racine des échecs de mise à jour WordPress

## Constat

Les installations de `faluss-platform` sur les deux conteneurs WordPress
étaient détenues par `1000:1000`, alors que le processus Apache/PHP exécute les
opérations WordPress sous `www-data` (`33:33`). Les répertoires étaient en
`755` et les fichiers en `644`. Ils restaient lisibles, mais `www-data` ne
pouvait pas les supprimer ou les remplacer.

WordPress 7.1.2 utilise son système de fichiers direct. Avant la copie du ZIP,
`WP_Upgrader::clear_destination()` vérifie chaque fichier puis
`move_to_temp_backup_dir()` déplace l'ancien dossier. Le message générique
`upgrade-temp-backup` masque l'échec de ce déplacement ; le serveur de mises à
jour et le téléchargement n'étaient pas en cause.

L'archive publiée était lisible et sa racine était correcte, mais elle
contenait aussi `AGENTS.md`, `.gitignore`, `docs/` et les dépendances de
développement. Ces fichiers n'expliquaient pas l'erreur de droits, mais ils
augmentaient le paquet et rendaient le contrôle de publication insuffisant.

## Correction

- `scripts/build-release.sh` construit une liste blanche avec les seules
  dépendances de production et fixe les modes Unix à `755` pour les dossiers et
  `644` pour les fichiers.
- `scripts/validate-release.py` bloque les niveaux de dossier incorrects,
  symlinks, fichiers cachés, dépendances de développement, modes inattendus,
  fichiers obligatoires absents et métadonnées WordPress incohérentes.
- Le workflow de publication utilise ces scripts avant tout tag et upload.
- `readme.txt` et les en-têtes du plugin déclarent `Requires at least: 7.1`,
  `Tested up to: 7.1.2` et `Requires PHP: 8.2`.
- Le client conserve ces valeurs pour les anciennes archives immuables qui ne
  possèdent pas encore le `readme.txt` ; les réponses brutes du serveur seront
  complètes dès la prochaine archive construite par la CI.
- `scripts/repair-wordpress-ownership.sh` sauvegarde puis remet une installation
  sous `www-data:www-data` et vérifie qu'elle est réellement inscriptible. Il
  est destiné à la réparation initiale des deux sites, pas à chaque mise à jour.

## Validation

Sur une copie WordPress 7.1.2, le plugin 0.5.1 a été installé et activé avec
des fichiers `1000:1000`. `Plugin_Upgrader` a échoué avec `false` avant le
remplacement. Après sauvegarde et correction de propriété vers `www-data`, le
même `Plugin_Upgrader` a installé l'archive candidate 0.5.2, a conservé l'état
actif et a chargé la version `0.5.2`.

Les tests d'archive couvrent la racine, les permissions, les dépendances, les
métadonnées, les fichiers de développement et les chemins dangereux. Aucune
nouvelle release n'est publiée par cette correction tant que la PR et ses
contrôles ne sont pas verts.

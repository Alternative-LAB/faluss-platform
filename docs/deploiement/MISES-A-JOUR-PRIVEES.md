# Mises à jour privées de Faluss Platform

Faluss Platform utilise Plugin Update Checker 5.7 pour interroger exclusivement
`https://updates.faluss.com`. Le serveur reçoit des archives construites par
GitHub Actions et ne connaît ni le dépôt GitHub ni un jeton GitHub.

```mermaid
sequenceDiagram
    participant GitHub as GitHub Actions
    participant Server as updates.faluss.com
    participant WP as WordPress Faluss
    GitHub->>Server: ZIP + Bearer de publication
    WP->>Server: Métadonnées + clé de licence
    Server-->>WP: Version et URL de téléchargement
    WP->>Server: Téléchargement + clé de licence
```

## Publication

Le workflow manuel `Prepare release` calcule la prochaine version, génère les
notes depuis les commits postérieurs au dernier tag, crée une branche
`chore/release-vX.Y.Z` et ouvre une PR. Il ne modifie jamais `main` directement.
En mode `auto`, `💥` produit une version majeure, `✨` une version mineure et les
autres commits une version corrective. L'opérateur peut imposer `patch`, `minor`
ou `major` au lancement.

Le secret GitHub Actions `RELEASE_PR_TOKEN` contient un jeton limité au dépôt,
autorisé à créer la PR afin que ses événements déclenchent les contrôles CI. Le
jeton ne rejoint jamais le paquet et reste distinct du Bearer d'upload.

Après fusion et contrôles verts, `.github/workflows/publish-private-release.yml`
détecte la nouvelle version sur `main`, crée le tag correspondant puis publie le
ZIP. Le même workflow accepte encore un tag `v*` créé manuellement, à condition
qu'il corresponde exactement à l'en-tête `Version` de `faluss-platform.php`.
Il installe les dépendances de production, construit une archive dont le dossier
racine est `faluss-platform`, puis l'envoie avec le secret GitHub Actions
`WP_UPDATE_UPLOAD_TOKEN`.

Pour préparer une publication depuis GitHub CLI :

```sh
gh workflow run prepare-release.yml -f bump=auto
```

Relire puis fusionner la PR générée après ses contrôles CI. Le tag et le paquet
sont alors produits automatiquement. Le serveur refuse de remplacer une version
déjà publiée.

## Licence des sites WordPress

La clé se configure dans **Faluss → Mises à jour privées**. Elle est enregistrée
dans l'option non autoloadée `faluss_platform_license_key` et n'est jamais
réaffichée dans le formulaire. En production, la constante suivante peut fournir
la clé depuis une configuration non versionnée :

```php
define('FALUSS_PLATFORM_LICENSE_KEY', 'cle-fournie-hors-du-depot');
```

La constante est prioritaire sur l'option. La même clé accompagne la requête de
métadonnées et le téléchargement du ZIP. Le Bearer de publication n'est jamais
installé dans WordPress.

## Vérification et retour arrière

Après publication, vérifier que le serveur retourne `401` sans licence, des
métadonnées avec une licence autorisée, puis effectuer la mise à jour depuis
l'administration WordPress. WP-CLI n'est pas installé sur les conteneurs Faluss
actuels. Pour revenir en arrière, supprimer le tag avant toute publication ou
retirer le workflow et le client par une PR.
Les packages déjà publiés restent immuables ; leur suppression est une opération
serveur distincte.

Un appel PHP direct à `Plugin_Upgrader::upgrade()` ne reproduit pas tout le
parcours interactif de WordPress. Un lanceur scripté doit utiliser trois
processus séparés : relevé de l'état actif local et réseau, installation, puis
restauration et vérification de cet état avec `activate_plugin()` en mode
silencieux. Il doit aussi tenter la restauration après un échec, sans masquer
l'erreur initiale. Il ne doit jamais modifier directement l'option
`active_plugins` ni simuler un contexte cron. L'incident et la procédure
détaillée sont documentés dans
`docs/incidents/2026-09-24-scripted-update-deactivation.md`.

# Recette d’administration des profils Créateur

Scénarios positifs : file privée, fiche et journal, décision native sans JavaScript,
activation et suspension via la route de statut existante, lectures publiques
seulement après activation, présentation approuvée séparément, pagination.

Scénarios négatifs : invité, membre lié/non lié, autre propriétaire et éditeur sans
permission ; nonce absent/invalide ; révision absente, invalide ou périmée ; champ
injecté ; absence de confirmation ; profil inexistant ; journal indisponible ou
échec SQL. Une erreur de journal ne doit jamais laisser le statut changé.

## WordPress et MariaDB réels, intégralement jetables

```sh
python3 tests/Fans/Profiles/recipe/admission-wordpress.py \
  --source /chemin/checkout-avec-vendor-physique \
  --core /chemin/wordpress-7.1.2 \
  --cli /chemin/wp-cli.phar --test
```

La recette crée son propre répertoire `/var/tmp/fans-admission-wp-*`, sa base
MariaDB via socket privé et son WordPress avec Twenty Twenty-Five. Elle n’utilise
aucune configuration ni base préexistante. SSO, profils, UI et éditorial sont
autorisés **uniquement dans cette configuration jetable**. Mail et HTTP externe
sont bloqués ; les comptes sont générés localement avec mots de passe aléatoires.
Aucun parcours vers une autorité Identity réelle n’est lancé.

Le seed est chargé avec `wp eval-file --use-include`, compatible avec son
`strict_types` sur PHP 8.3 et versions suivantes.

Le seed simule une installation opt-in antérieure au nouveau journal : profils
en attente, aucun journal. Les requêtes HTTP vérifient que seul l’administrateur
peut installer ce journal additif, sans changer les statuts existants. Une paire
de workers PHP avec cookies et nonces WordPress distincts produit exactement un
succès `200` et un conflit `409`. Un trigger MariaDB injecte un échec d’audit et
prouve le rollback. Les contrôles portent aussi sur le HTTP **de la page elle-même**
(`404 → 200 → 404`) et l’absence des profils non actifs dans l’API Explorer.

`checks.json` contient seulement les intitulés et résultats des contrôles. Les
cookies/IDs de fixture restent dans `session.json` privé hors Git ; ne jamais
publier ce fichier, la configuration, les logs bruts ou un export de base.

La CI ajoute cette recette au job WordPress existant, avec les mêmes archives
WordPress 7.1.2 et WP-CLI 2.12.0 épinglées par SHA-256. La suite SQL éditoriale
continue de vérifier les images, présentations et la messagerie indépendantes.

## Navigateur et captures

Avec `--test --keep`, le serveur loopback reste disponible jusqu’à la création
du fichier `STOP` dans son répertoire. Préparer hors Git un fichier privé contenant
le `base` loopback et les données du `session.json` local, puis :

```sh
node tests/Fans/Profiles/recipe/admission-browser.cjs \
  /chemin/fixture-privee.json docs/evidence/fans-creator-admission
```

Le runner utilise Playwright et Chrome déjà installés ; aucune dépendance runtime
n’est ajoutée au plugin. Il interdit les requêtes hors loopback et désactive
JavaScript pour vérifier les formulaires natifs. Vues file, fiche en attente et
fiche active à **1440×1000** et **390×844**, focus, clavier et absence de débordement.
Les captures montrent des comptes et demandes de recette, pas des personnes ou
données réelles ni une validation du site cible. Voir les résultats dans
[les preuves](../../../../docs/evidence/fans-creator-admission/README.md).

# Activation Link sans opt-in chargé

## Détection

Le 23 septembre 2026, la première bascule contrôlée de Faluss Link sur
`faluss.me` a désactivé le plugin historique après avoir tenté d'ajouter
`FALUSS_PLATFORM_LINK`. Le script cherchait le marqueur WordPress avec une
apostrophe typographique différente de celle du fichier réel. La constante
n'a donc pas été écrite et le module Platform n'a pas démarré à la requête
suivante.

Les quatre profils publics répondaient encore HTTP 200, mais affichaient le
gabarit WordPress générique sans contenu Link. La vérification explicite de
`FALUSS_LINK_VERSION` a détecté l'absence du module avant toute mutation de
données.

## Mesure immédiate

`faluss-link` a été réactivé immédiatement. Les quatre profils représentatifs
ont retrouvé leurs tailles de réponse initiales, les cinq shortcodes et la
version historique `0.3.20`. Aucune table, option, carte, bloc, découverte ou
pièce jointe n'a été modifié. Les sauvegardes SQL et de configuration prises
avant la tentative sont conservées sous
`/opt/backups/faluss-platform-migration-20260923` dans le conteneur hôte.

## Correction de procédure

La nouvelle tentative doit écrire la constante dans le bloc déjà utilisé par
les autres opt-ins Platform, juste avant `require_once ABSPATH .
'wp-settings.php'`. Elle doit ensuite relire le fichier et vérifier que la
constante vaut `true` dans une requête WordPress indépendante avant de
désactiver le plugin historique.

La bascule Link reste suspendue jusqu'à cette vérification et à une nouvelle
comparaison des profils, du Studio, des découvertes et du gain quotidien.

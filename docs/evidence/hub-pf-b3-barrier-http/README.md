# B3b3d fermé — transport HTTP des barrières

Recette du 10 octobre 2026 : WordPress 7.1.2, MariaDB 11.8.6, PHP 8.5.4 et
WP-CLI 2.12.0. Deux bases WordPress, deux clés éphémères distinctes et des pairs
fictifs. Racine POSIX 0700, bail physique privé 0600, socket primaire local.
Aucun site, secret de site, flag ou vrai compte SSO.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-barrier-http \
  --source "$SOURCE" --core "$WP_CORE" --cli "$WP_CLI" \
  --output "$PRIVATE_OUTPUT/barrier-http-checks.json"
```

**303 contrôles satisfaits, dont 31 nouveaux HTTP** après les 272 précédents.
Le [rapport expurgé](wordpress-checks.json) contient seulement noms et compteurs,
versions, empreinte du service historique et confirmation de destruction de la
fixture. Aucune identité, clé, requête signée ou export privé publié.

Positifs : register, close et lookup signés ; action/objet/clé exacts ; réponses
privées no-store ; huit requêtes identiques concurrentes, un nonce et un événement.
Perte réelle du corps HTTP après COMMIT de register puis close : lookup primaire
et rejeu frais avec même action/clé, sans événement supplémentaire. Un ancien
lookup register rapporte closed ; il ne réouvre pas la version.

Négatifs : GET, type de contenu, taille/JSON, signature modifiée, audiences,
domaines historiques, clés inconnues/révoquées, permission historique, nonce,
action/digest de réponse et origine/politique étrangères sur référence pauvre.
Contexte expirant après admission derrière le verrou propriétaire : aucune
fermeture. Les octets du ledger historique restent identiques.

Suite PHP complète : 656 tests / 7 013 assertions, zéro échec, deux dépréciations
historiques. PHPStan complet cible PHP 8.3, syntaxe de quatre PHP et trois Python.
Arbre de runtime final 44641cdd26438f6a7ad3f1f1bd4fc8c1ce157d95 ; les huit
sources de runtime sont identiques à celles exécutées. Rapport final et métadonnées
de preuve revérifiés, 12 fichiers scannés et sept liens relatifs valides.
La CI du head final demeure requise avant fusion.

Limites : le contrôleur de recette signe et vérifie les échanges entre les deux
instances ; le client WordPress Fans intégré et la persistance locale
opening/closing suivent. La concurrence économique/fermeture est prouvée par
les tests SQL propriétaires précédents, pas par ce nouveau trajet HTTP.
Aucune preuve de vrai SSO, TLS de production, réplica/restauration ou charge.
Aucune route dans le bootstrap : le MU-adaptateur de tests ne peut s'enregistrer
hors enclave physique, même avec ses constantes copiées. Il est exclu du ZIP.

Retour arrière : retirer le gateway et l'adaptateur fermé, sans migration de
site ni réécriture de ledger. Conservation #150 et RustFS #161 distinctes.
Voir le [contrat approuvé](../../modules/HUB-PF-B3-BARRIER-TRANSPORT.md).

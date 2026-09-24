# Store Fans — catégories visibles et achats fermés

## Portée implémentée

Le module opt-in `fans-store-catalog` dépend des profils créateurs Fans. Il expose deux catégories canoniques distinctes et classables : `hosted_allowed_content` (« Contenu autorisé hébergé par Fans ») et `external_adult_delivery_right` (« Droit à une livraison adulte externe »). Un administrateur peut créer pour un profil actif une fiche structurée contenant uniquement UUID de produit, UUID de créateur, catégorie, visibilité et date. La fiche n'a ni texte libre, image, fichier, aperçu, URL de livraison, prix ni donnée de paiement. Le catalogue ne contient aucun contenu adulte et n'en transmet pas. La **catégorie** adulte reste visible et classable ; une **fiche** qui associe un créateur à cette catégorie est créée `hidden` et exclue des lectures publiques, car aucun mécanisme de consentement explicite du créateur n'existe encore.

La table InnoDB `${prefix}faluss_fans_store_catalog` possède une clé primaire sur le produit, une clé unique d'idempotence et des index de catégorie/visibilité et de créateur. La clé d'idempotence de l'administration n'apparaît pas dans les réponses publiques. L'option `faluss_fans_store_catalog_schema_version=1` est écrite après vérification exacte. Le flag `FALUSS_PLATFORM_FANS_STORE_CATALOG=true` est fourni hors Git ; le module exige le rôle Fans, le SSO, les profils et leurs schémas prêts. Une activation ou réactivation contrôlée installe la table. Retirer le flag ferme les routes mais conserve les fiches pour retour arrière. Aucun site réel n'est activé par cette PR.

## API `faluss-fans/v1`

| Route | Permission | Résultat |
| --- | --- | --- |
| `GET /store/categories` | Public | Les deux catégories avec libellés contrôlés et `purchasable=false`. |
| `GET /store/products` avec filtre `category` optionnel | Public | Au plus 20 fiches hébergées visibles de créateurs encore actifs ; filtre adulte valide mais liste vide en l'absence de consentement. |
| `GET /store/products/{product_id}` | Public | Fiche hébergée visible d'un créateur encore actif ou 404 ; fiche adulte masquée, même si sa visibilité stockée est changée. |
| `POST /store/products` | `manage_options`, nonce REST et `Idempotency-Key` UUID | Crée une fiche structurée pour un profil actif ; fiche adulte toujours `hidden`. Même clé et mêmes données donnent la même fiche, conflit de clé refusé. |
| `POST /store/products/{product_id}/purchase` | Public, pour que toute requête atteigne le refus serveur | `403 external_adult_purchase_blocked` pour la catégorie adulte externe, même masquée ; `503 hosted_purchase_not_open` pour le contenu hébergé ; aucun effet de bord. |

Le refus est dans `PurchaseGate`, appelé par le service utilisé par l'API. Le service relit la catégorie stockée par identifiant de produit, même pour une fiche masquée : un changement de catégorie ne contourne donc pas les codes 403/503. Un flag d'acceptation supposé pour la catégorie adulte n'est pas lu ; le refus reste codé côté serveur. Les fiches ne constituent ni produits payables, ni commandes, ni droits. Il n'existe actuellement aucune route de panier, checkout, paiement, administration de commande, webhook, reprise ou délivrance. Ces futurs chemins devront chacun recevoir leur garde serveur et leurs tests de refus adulte avant fusion ; ils ne sont pas protégés aujourd'hui puisqu'ils n'existent pas.

## Portes avant toute vente

Pour le **contenu autorisé hébergé**, obtenir la validation explicite du prestataire pour ce modèle de plateforme, puis ajouter contenu conforme, prix, commandes, paiement, remboursements et droits dans des PR revues et testées. Le flag du catalogue ne vaut pas validation commerciale. Avant toute utilisation réelle des fiches administratives, vérifier aussi le consentement du créateur à la catégorie affichée ; ce flux n'est pas automatisé ici. Vérifier sur WordPress/MariaDB et avec le prestataire avant activation de vente.

Pour la **livraison adulte externe**, garder cette catégorie visible mais ses fiches nominatives masquées tant qu'un consentement explicite du créateur n'est pas recueilli, persisté et vérifié côté serveur. Ce mécanisme de consentement sera un lot séparé ; l'approbation administrative du profil ou de la fiche ne le remplace pas. Aucun achat, droit ni transfert de contenu n'est permis. Une acceptation explicite du prestataire pour ce parcours distinct serait nécessaire avant même de proposer une modification du refus. Le contenu adulte reste hors de Fans, y compris publications, fichiers et messagerie. Aucune validation du modèle hébergé, réponse du navigateur, métadonnée de fiche ou option WordPress ne lève le refus.

Les tests actuels couvrent les libellés et catégories, l'idempotence et les permissions de création, la fiche adulte masquée malgré création administrative ou changement de catégorie, le refus côté service et API pour les deux catégories et l'absence de champ de contenu ou de livraison. Ils ne prouvent pas les chemins de commande ou de droit futurs, ni une recette WordPress/MariaDB, paiement ou prestataire réelle. Le lot commandes/droits devra tester panier, checkout, API, administration, webhook, reprise, catégorie changée et droit ancien avant toute ouverture.

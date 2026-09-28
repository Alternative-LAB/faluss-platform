# Store Fans — archives privées et achats fermés

## Portée implémentée

Le module opt-in `fans-store-catalog` dépend des profils créateurs Fans. Seule
`hosted_allowed_content` est exposée dans la taxonomie publique. La catégorie
historique `external_adult_delivery_right` et ses anciennes fiches sont archivées :
aucune création, republication ou conversion en produit autorisé par les API.
Un administrateur peut consulter les archives, sans possibilité de les restaurer.
Les UUID, clés d'idempotence, catégories, visibilités et dates historiques restent
inchangés en base. Aucun contenu adulte n'est stocké ou transmis par ce catalogue.

Les fiches structurées n'ont ni texte libre, image, fichier, aperçu, URL de livraison,
prix, commande ou donnée de paiement. Ce lot n'ajoute aucune de ces fonctionnalités.

## Schéma v2 et activation contrôlée

Une seule colonne additive `archived tinyint(1) NOT NULL DEFAULT 0` est ajoutée à
`${prefix}faluss_fans_store_catalog` (InnoDB). À l'installation contrôlée, toutes les
fiches `external_adult_delivery_right` reçoivent `archived=1`. Aucun autre champ
n'est modifié ; aucun enregistrement ni historique n'est effacé. Il n'existe pas
de journal de décisions Store distinct dans le schéma v1 ; ce lot n'en invente pas.
Le marqueur conserve le refus même si la catégorie d'une ancienne fiche est ensuite
changée en base. Il n'existe aucune route de modification de catégorie ou du marqueur.
Un opérateur ayant un accès SQL peut altérer les données : le marqueur n'est pas une
protection contre un administrateur de base malveillant.

L'option `faluss_fans_store_catalog_schema_version=2` n'est écrite qu'après
vérification exacte du schéma et marquage réussi. Le verrou d'installation existant
sérialise les activations. L'ALTER n'est pas transactionnel : si le marquage échoue,
la colonne peut déjà exister ; le module reste fermé (version 1), une nouvelle
activation reprend le marquage sans recréer la colonne. Aucun upgrade au boot.
Une ancienne version de schéma ne passe pas `ready()` : pas de routes Store.

L'activation exige rôle Fans, flags SSO/profils/catalogue, configuration et schémas
prêts. Ces flags restent fermés hors de l'instance locale jetable de recette. Une
future activation réelle exige une opération contrôlée et une autorisation distincte.

## Contrat REST `faluss-fans/v1`

| Route | Permission | Résultat |
| --- | --- | --- |
| `GET /store/categories` | Public | Uniquement contenu hébergé autorisé, `purchasable=false`. |
| `GET /store/products` | Public | Au plus 20 fiches hébergées visibles, profil actif ; archive et catégorie adulte exclues en SQL avant LIMIT. |
| Filtre `category` | Public | Absent ou `hosted_allowed_content` ; catégorie adulte, inconnue ou tableau forgé : 400 `invalid_category`. |
| `GET /store/products/{product_id}` | Public | 404 pour toute archive ou fiche adulte, même avec visibilité forcée ou catégorie changée ; fiche permise si profil actif. |
| `POST /store/products` | `manage_options`, nonce REST, `Idempotency-Key` UUID | Fiche hébergée pour profil actif ; adulte : 403 `category_archived`, même avec visibilité/marqueur forgés. Rejeu d'une ancienne archive : refus, jamais remise en ligne. |
| `POST /store/products/{product_id}/purchase` | Public, refus obligatoire | Archive ou catégorie adulte : 403 `external_adult_purchase_blocked` ; hébergé : 503 `hosted_purchase_not_open` ; inconnu : 404. Catégorie envoyée par le client ignorée. |
| `GET /store/admin/archive` | `manage_options` ET nonce `wp_rest` | `{items,next_cursor}` ; 20 éléments maximum, ordre UUID croissant, curseur exclusif UUID ; curseur invalide 400. |
| `GET /store/admin/archive/{product_id}` | Même permission | Archive, même si créateur suspendu ; fiche non archivée/inconnue : 404. |

Les réponses d'archive comportent UUID produit/créateur, catégorie/libellé,
visibilité/date conservées et `archived=true`, sans clé d'idempotence. Réponses
privées `Cache-Control: private, no-store, max-age=0`, `Vary: Cookie, X-WP-Nonce`.
Aucune route PUT/PATCH/POST de remise en ligne n'existe. Le service contrôle aussi
la capacité administrative indépendamment de la permission REST.

La découverte du catalogue utilise ces listes/détails/filtres. Le module n'inscrit
ni type de post WordPress, ni index de recherche, ni compteur/facette séparé, ni
projection Me/Hub. Les paramètres de recherche ou de comptage non pris en charge
ne réintroduisent pas les archives ; la liste filtrée reste la seule source publique.
Aucun total des archives n'est publié. Tout futur index, recherche ou compteur devra
reprendre cette exclusion avant agrégation et disposer de ses propres tests.

## Refus d'achat et limites

`PurchaseGate` reste inchangé. Le service relit le produit et impose la catégorie
interdite au garde d'achat quand son marqueur est archivé. Aucune acceptation supposée
de prestataire ou flag ne lève ce refus. Le contenu hébergé reste fermé 503.
Il n'existe ni panier, checkout, paiement, webhook, commande, droit ou délivrance.
Les futurs chemins devront recevoir leurs propres gardes et tests ; les refus actuels
ne prouvent pas leur sécurité. Le vendeur contractuel et les autres portes de
l'[ADR 0018](../adr/0018-fans-pf-pc-hof-v3.md) restent ouverts à décision : parcours fermés.
Aucun score ou PC implicite du Shop, aucun wallet ou montant financier côté créateur.
Le Token Engine et les claims historiques sont inchangés.

## Retour arrière

1. Fermer `FALUSS_PLATFORM_FANS_STORE_CATALOG` avant toute intervention : routes fermées.
2. Conserver table, colonne, marqueurs et option ; ne pas remettre l'option à 1,
   supprimer la colonne ou convertir les catégories. Aucune désactivation ne les efface.
3. Préférer une correction en avant. L'ancien code v1 vérifie exactement six colonnes :
   avec la colonne additive, il échoue fermé. Un retour binaire ne permet donc pas de
   réactiver l'ancien catalogue ; une adaptation compatible doit être revue séparément.
4. Avant future réouverture du seul catalogue autorisé, revérifier le schéma, les
   archives et les refus 403/503. Aucune procédure de désarchivage n'est fournie.

## Preuves

Tests ciblés Store : catégories, créations/idempotence, permissions/nonce, archives
après suspension, catégorie/visibilité forgées, migration interrompue/reprise.
[Recette WordPress/MariaDB réelle](../../tests/Fans/Store/recipe/README.md) :
50 fiches synthétiques, migration v1→v2 avec panne, API HTTP et pagination administrative.
Ces preuves locales ne sont ni validation d'un hébergeur réel, ni activation, ni
recette de moteurs commerciaux futurs.

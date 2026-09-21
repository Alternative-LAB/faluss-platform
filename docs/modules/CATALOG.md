# Migration du catalogue de thèmes

Le catalogue historique de `faluss.me` possède les thèmes prédéfinis des cartes Faluss Link. Son option `faluss_catalog_card_themes` est distincte des jetons visuels de `Faluss Theme`. Faluss Link consomme actuellement la classe publique `Faluss_Catalog_Themes` ; cette dépendance devra rester compatible lors de la bascule.

```mermaid
flowchart LR
    Option[faluss_catalog_card_themes] --> Reader[CatalogThemeReader]
    Reader --> Contract[Thèmes normalisés par scope]
    Contract --> Link[Faluss Link]
    Admin[Administration à migrer] --> Option
```

## Première étape : lecture

`CatalogThemeReader` reprend le thème système immuable, la normalisation des enregistrements existants, le tri, le filtrage des thèmes actifs et la résolution par identifiant. Il utilise le même nom d’option et les mêmes champs que l’ancien plugin. Il ne lit ni n’écrit lui-même la base WordPress : le futur adaptateur fournira les valeurs de l’option au constructeur.

Cette étape n’enregistre aucun hook, menu, route ou façade globale. Le plugin historique reste seul actif et l’option demeure sa propriété. Aucun basculement de production n’est possible à ce stade.

## Deuxième étape : validation des écritures

`CatalogThemeEditor` valide les créations et modifications, génère des identifiants uniques, refuse les changements du thème système et prépare les suppressions. Il accepte uniquement les codes de droit fournis par le Connector ; lorsque ce dernier est indisponible, seul un code déjà associé peut être conservé. Les valeurs sont préparées en mémoire, sans écriture WordPress dans cette étape. L’adaptateur administratif à venir devra contrôler capacités et nonces, appliquer `wp_unslash`, persister l’option et émettre le hook de désactivation.

`CatalogEntitlementProvider` isole désormais la lecture des définitions du Connector et ne transmet à l’éditeur que les droits valides de type `theme`. Une réponse absente ou en erreur verrouille l’ajout de nouveaux droits, tout en permettant de conserver un droit déjà associé.

`CatalogAdminActions` prépare les endpoints `admin-post.php` historiques. La capacité `manage_options` et un nonce propre à chaque action sont vérifiés avant toute mutation. Les valeurs sont déséchappées, validées, puis écrites dans la même option ; les désactivations et suppressions réémettent `faluss_catalog_theme_deactivated`. La classe n’est pas encore branchée au démarrage du plugin : elle ne peut donc pas entrer en collision avec les endpoints de l’ancien catalogue.

L’interface de gestion, l’activation contrôlée des actions et la façade PHP consommée par Faluss Link restent à migrer. Aucun ancien plugin ne peut encore être désactivé.

## Dépendances et risques à vérifier avant bascule

- Rôle : `me` uniquement ; `faluss.com` ne possède pas ce catalogue.
- Faluss Link appelle `get_active_theme`, `all_for_scope` et `active_for_scope` sur `Faluss_Catalog_Themes`.
- L’administration historique consulte les droits de thème via `Token_Engine_Connector_Service`.
- Les suppressions et désactivations émettent `faluss_catalog_theme_deactivated`.
- Les identifiants, images de prévisualisation, droits associés et références de cartes doivent survivre au changement de plugin.

Le futur retour arrière conservera l’option historique intacte et réactivera le plugin `faluss-catalog` ; cette procédure devra être éprouvée sur une copie de `faluss.me` avant toute désactivation réelle.

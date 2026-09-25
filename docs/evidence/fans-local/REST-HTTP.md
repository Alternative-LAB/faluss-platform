# Permissions et catalogue — recette HTTPS locale

Exécutée le 25 septembre 2026 sur le runtime de #57 à
`2b26e8b06ecc05f8beb2aa5bce464e7785451a0f`, WordPress 7.1.2,
PHP 8.5.4 et MariaDB 11.8.6. Même proxy local et bases fictives que la
[recette SSO](SSO-HTTP.md). Aucune production consultée ou modifiée.

| Appel HTTPS réel | Résultat |
| --- | --- |
| Création de profil membre, cookie mais sans nonce | 401 `rest_forbidden` : WordPress retire l'authentification REST |
| Même création, cookie et `X-WP-Nonce` valides | 201 |
| Approbation par le membre | 403 |
| Approbation par administrateur | 200 ; `identity_verified=false` |
| Création administrateur adulte et hébergé avec clés d'idempotence distinctes | 201 pour les deux ; fiche adulte `hidden` |
| Liste des catégories | 200 ; deux catégories |
| Détail public adulte / liste adulte | 404 / liste vide |
| Achat direct API du droit externe adulte | 403 |
| Achat direct API du contenu hébergé | 503 |
| Catégorie hébergée changée en adulte directement dans la base fictive, visibilité encore `visible` | Détail 404, achat 403 |

Le refus nominatif ne repose donc pas uniquement sur la valeur initiale de
`visibility`. Le filtre de lecture du serveur reste fermé en l'absence de
mécanisme de consentement. Aucun consentement n'est fabriqué par l'administration.

La première recette par `rest_do_request()` observait 403 sans nonce : elle
n'exerçait pas le traitement HTTP des cookies WordPress. Le résultat ci-dessus
précise cette différence ; il ne faut pas confondre les deux modes d'essai.

Reproduction : préconditions du [SSO](../../../tests/Fans/Sso/recipe/README.md),
activer les quatre flags Fans dans la configuration jetable, puis exécuter
`tests/Fans/Store/recipe/fans-rest-fixture.php` par WP-CLI dans Fans après le SSO.
Avec les deux serveurs locaux et le proxy actifs, lancer `fans-rest-test.py`.
Les fixtures de sessions et nonces restent hors Git dans le dossier jetable.

Les futurs panier, commande, paiement, webhook et délivrance **n'existent pas**
et ne sont pas couverts. Le contrôle du fichier privé, la modération des médias,
le retrait du consentement et la vérification d'identité vendeur sont des lots
à construire. Cette recette ne constitue aucune autorisation commerciale.

## Retrait des flags après la recette

Les quatre flags Fans ont ensuite été remis à false dans la seule configuration jetable. De nouvelles requêtes HTTPS ont confirmé : creators et store/categories en 404, formulaire SSO absent ; le nombre de tables Fans et de liaisons reste identique avant/après. Les serveurs HTTP de recette ont été arrêtés. Les sessions déjà émises ne sont pas révoquées par un flag ; cette limite reste inchangée.

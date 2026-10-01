# Application et composition — recette isolée

WordPress 7.1.2, Twenty Twenty-Five, PHP 8.5.4, MariaDB 11.8.6, Chrome
154.0.8037.58 ; fixture neuve `admission-wordpress.py --app --test --keep`.
Les comptes liés sont générés localement, aucun vrai SSO ou appel cible.
Seules les origines des fetch et formulaires sont ramenées à la boucle locale
par le test navigateur ; les réponses REST et traitements sont réels.

82 contrôles d'admission passent avec les nouvelles routes. La recette
`app-wordpress.cjs` vérifie `/app` pour invité/Fan/Créateur, anciennes routes,
refus des espaces, HoF public, callback conservé, shortcode et membre connecté.
Aux dimensions 1440 × 900 et 390 × 900 : même abscisse et largeur du conteneur
Explorer/HoF/profil, absence de débordement, en-tête compact, focus clavier,
Échap, retour navigateur, émoji inséré, commerce fermé. La soumission du vrai
formulaire crée une publication pending et rien de public.

| Écran | Ordinateur | Mobile |
| --- | --- | --- |
| Explorer | [capture](explorer-1440.png) | [capture](explorer-390.png) |
| HoF | [capture](hof-1440.png) | [capture](hof-390.png) |
| Profil | [capture](mon-profil-1440.png) | [capture](mon-profil-390.png) |
| Sélecteur Créer | [capture](selector-1440.png) | [capture](selector-390.png) |
| Publication | [capture](publication-1440.png) | [capture](publication-390.png) |
| Produit fermé | [capture](product-1440.png) | [capture](product-390.png) |

[Résultat navigateur](browser.json). Aucun secret/cookie de recette dans le dépôt.
Les autres domaines passent la recette SQL/HTTP existante. La partie backend
dispose de 311 tests PHP, 4 959 assertions ; deux dépréciations préexistantes.

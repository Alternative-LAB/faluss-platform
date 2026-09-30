# Retouches de recette Fans

Le bouton natif SSO est limité à 340 px, avec une hauteur de 48 px sur ordinateur et 46 px dans la recette mobile. Son texte, son icône décorative, son formulaire POST, le nonce et le retour ne changent pas. Les styles restent isolés du thème et des autres boutons.

Les huit accès Créateur et les accès Fan utilisent désormais la même règle : libellé visible au survol, au focus clavier et sur l’accès actif. Sur mobile, tous les libellés restent visibles, sans dépendre du survol. Leur emplacement réservé évite de déplacer les accès à chaque focus.

Le conteneur principal commun est plafonné à 1440 px, avec 32 px de padding sur ordinateur, 14 px horizontalement sur mobile. La gouttière de défilement reste réservée sur les pages Fans, y compris lorsque le contenu est court. La hauteur suit le contenu ; les cartes gardent leurs dimensions propres.

## Preuves locales

- WordPress 7.1.2, MariaDB 11.8.6, PHP 8.5.4, Elementor 4.3.1, Chrome 154.0.8037.58, installations jetables en boucle locale, requêtes externes bloquées.
- `tests/Fans/Access/recipe/refinements.cjs` : 36 combinaisons invité/membre lié × six routes × 1440/390/320 px, hauteur 900 px. Position et largeur du main identiques entre les routes à chaque dimension ; absence de débordement, libellés actifs/survol/focus et landing Elementor réelle.
- `tests/Fans/Sso/recipe/browser-button.cjs` : 1286/390/320 px, JavaScript désactivé, vrai formulaire et POST intercepté localement, nonce/retour inchangés, bouton témoin hors périmètre intact, hover/focus et icône chargée.
- `tests/Fans/Sso/recipe/browser-creator-layout.cjs` : 21 combinaisons de vues Créateur × dimensions, huit accès et tous leurs libellés au survol/focus. Ce complément appelle le rendu de production dans WordPress : il ne simule aucun service métier et ne constitue pas une preuve d’accès d’un créateur réel.
- PHPUnit : 308 tests, 4856 assertions, deux dépréciations préexistantes ; PHPStan sans erreur. Les dépréciations PHP 8.5 de WordPress/Elementor dans la recette n’ont pas interrompu les contrôles.

Captures et résultats : [evidence/fans-ui-refinements](evidence/fans-ui-refinements). Les pages Explorer/HoF présentent les états indisponibles réels de cette recette sans flags métier. Aucun site cible, compte réel, secret ou flag de production utilisé. La landing Elementor locale ne reproduit pas le contenu de la landing de production.

Retour arrière : rétablir les deux feuilles CSS précédentes ; aucune migration de données, API, hook ou formulaire modifié.

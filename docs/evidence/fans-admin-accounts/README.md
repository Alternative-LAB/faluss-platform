# Comptes dans les files administratives

WordPress 7.1.2, Twenty Twenty-Five, PHP 8.5.4, MariaDB 11.8.6,
comptes et base jetables. Aucun site cible consulté.

- 314 tests / 5 139 assertions ; PHPStan zéro erreur ; deux dépréciations existantes.
- Suite SQL/HTTP complète profils, images, publications, messages et signalements :
  réussie, y compris refus de preuve privée et conservation avec admission fermée.
- WordPress réel : 84 contrôles admission (le nombre varie de un selon le gagnant
  de la course), puis 19 contrôles de compte dans `account-checks.json`.
- Recherche réelle par e-mail/UUID, permissions invité/membre/éditeur, jokers
  échappés, liaison supprimée dans le fixture : activation refusée sans journal
  parasite, suspension autorisée, liaison restaurée après test.
- Quatre captures et recette `accounts-wordpress.cjs`, 1440×900 et 390×900 :
  cartes admission et présentation, absence de débordement, données locales et
  absence de handle/portrait honnêtes. La chaîne « Fixture private pending name »
  est un contenu synthétique **effectivement approuvé** par la recette admission.

L'administration native WordPress reste visible ici ; le shell privé unifié est
le lot suivant. Aucune preuve de rendu sur le site cible ou Elementor.

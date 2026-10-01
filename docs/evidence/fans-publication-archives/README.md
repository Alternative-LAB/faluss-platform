# Publications et archives — recette isolée

WordPress **7.1.2**, Twenty Twenty-Five, PHP **8.5.4**, MariaDB **11.8.6**,
Chromium (version dans `browser.json`), 1440 × 900 et 390 × 900.
Base et comptes jetables, aucune requête vers les sites cibles. Le navigateur
remappe uniquement l'origine virtuelle du fixture vers la boucle locale ;
les réponses proviennent des vrais contrôleurs WordPress et de MariaDB.

- Suite complète : **312 tests, 5 109 assertions** ; deux dépréciations existantes.
- PHPStan : zéro erreur ; lint PHP modifiés, syntaxe JS et `git diff --check`.
- Recette admission WordPress : **81 contrôles**, dont permissions, nonces,
  décisions concurrentes et visibilité publique.
- `tests/Fans/Ui/recipe/archives-wordpress.cjs` crée huit publications, les refuse
  ou les retire avec les vrais services, puis approuve une publication longue.
  Contrôle archives multi-pages, curseurs incompatibles, invité et autre membre
  refusés, absence de texte purgé et de lien d'ouverture après retrait.
- Aperçu public dépliable avec Entrée, retour à l'aperçu, absence de débordement
  aux deux dimensions. Captures `archive-*` et `publications-*`.

Les données visibles sont des contenus de recette locale. Ces preuves ne valent
pas recette de `fans.faluss.me` ou d'Elementor. Aucun schéma ou flag n'est modifié
par cette évolution ; l'ancien historique API sans `bucket` reste compatible.

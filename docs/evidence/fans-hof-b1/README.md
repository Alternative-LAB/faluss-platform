# B1 — preuves de domaine et registre privé

Recette locale du 6 octobre 2026, WordPress **7.1.2**, PHP **8.5.4**, MariaDB **11.8.6** sur
socket neuf, dans un répertoire jetable privé. Source courante isolée et vendor
copié avec `composer.lock` identique ; aucune jonction Windows utilisée.

- [Rapport WordPress/MariaDB](wordpress-checks.json) : cinquante vérifications
  réussies, comptes locaux et liens SSO fictifs de recette, aucun SSO central.
- Calcul pur B1a : 36 tests / 77 assertions ; suite complète : 475 tests /
  6 179 assertions à la dernière exécution, zéro erreur/échec, deux dépréciations préexistantes.
- PHP lint et PHPStan ciblé/complet : OK. Copies de textes LF pour les hashes
  historiques ; aucun fichier historique ou hash attendu changé.
- CI : commande `--test --hof-b1`, rapport `fans-hof-b1-checks.json` dans l'artefact
  `hub-pf-runtime-checks`. Le résultat GitHub du head exact reste le gate de fusion.

La recette vérifie le vrai stockage, les contrôles locaux de permissions, CAS,
concurrence, rollback de journal et masquage. Elle ne prouve ni réseau Hub B3,
corpus admissible, classement actif, origine réelle, sites, pays autorisés,
politique de rétention #150 ou RustFS #161. Aucun écran modifié : sans capture UI
pour ce lot backend. Aucune migration automatique ou production, aucun flag.

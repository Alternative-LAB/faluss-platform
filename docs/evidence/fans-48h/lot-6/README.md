# Lot 6 — Demander un profil créateur

29 septembre 2026. Base `cbc2cae1751d7dda1a70825470e83e3fd8df39f7`, version 0.6.3.
Export Git LF + diff dans WSL, vendor physique conforme au lock. PHP 8.5.4 :
lint de cinq PHP, PHPStan complet vert, **284 tests / 4 262 assertions**, aucun
échec, deux dépréciations. Scan ciblé de secrets, syntaxe JS et diff contrôlés.

## Recette

`tests/Fans/Ui/recipe/admission.cjs`, Chromium avec JavaScript désactivé,
1440 × 900 puis 390 × 844, serveur PHP et adaptateur REST simulés. **Ni WordPress
réel ni site consulté.** Les identifiants et profils sont des fixtures de test.

- Invité, non lié et administrateur refusés avant écriture (403).
- Nonce invalide 403 ; catégorie inconnue 400 ; erreur 503 sans faux succès et
  catégorie conservée. Une API absente (404 `rest_no_route`) ou un schéma fermé
  n’ouvre pas le formulaire.
- Fan lié : demande pending ; même catégorie rejouée sans second profil ; autre
  catégorie en conflit 409. Champs forgés `status=active`/propriétaire jamais
  transmis au REST. Aucun profil auto-approuvé, nom ou portrait inventé.
- Lien vers Mon profil créateur : huit accès, état pending, fiche publique
  inaccessible, aucun UUID affiché et aucun débordement.
- Recettes de régression : 168 cas rôle/route/viewport et gestion des textes sans JS.

## Captures inspectées

- [Demande ordinateur](demande-desktop.png).
- [Demande mobile](demande-mobile.png), après défilement vers le formulaire.
- [Profil en attente mobile](profil-en-attente-mobile.png), navigation Créateur.

Palette, bandeau et navigation V2 conservés. La progression Fan reste explicitement
indisponible et séparée de la demande de profil. Le test est une preuve d’adaptation
UI, pas une nouvelle preuve MariaDB, vraie session Me ou thème/Elementor. Le moteur
Profils et ses permissions restent inchangés et couverts par les tests existants.
Aucun flag, site, déploiement, migration ou release.

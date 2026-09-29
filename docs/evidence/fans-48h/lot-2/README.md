# Lot 2 — Retour SSO et invitation visiteur

29 septembre 2026. Base `70933b530d4b49a6d8b0c6b2ea6e318a91d11856`,
version 0.6.3, avec le diff de ce lot. Aucun site réel consulté.

## Preuves

- Export Git LF et overlay du diff dans WSL, dépendances copiées physiquement
  et comparées au lock (31 paquets), sans jonction `vendor`.
- PHP 8.5.4 : huit fichiers PHP lintés ; suite complète **284 tests / 4 226
  assertions**, aucun échec, deux dépréciations ; PHPStan complet sans erreur.
- Flux SSO existant exécuté avec adaptateurs WordPress et réponse Me simulés :
  destination autorisée, callback préenregistré inchangé, cookies sécurisés,
  consommation unique, rejeu, nonce invalide, échec distant et compte privilégié.
  Tests séparés de signature falsifiée, autre état, chemins externes/admin/API,
  query/fragment/encodage, et installation en sous-répertoire.
- Serveur PHP de fixtures et Chromium/Playwright à 1440 × 900, 390 × 844,
  puis 320 × 640 avec mouvement réduit. Recette UI complète du lot 1 conservée.
  Invité : proposition SSO sur Explorer/HoF/profil, route privée toujours 403.
  Administrateur non lié : pas de bouton de liaison privilégiée. Aucun débordement.
- Inspection des nouvelles captures : fond sombre, bandeau crème, accent vert,
  CTA lisible, navigation mobile accessible. Pas de nom, alias, portrait, badge
  gagné, contribution ou progression inventés.

## Captures

| Surface | Ordinateur | Mobile |
| --- | --- | --- |
| Invitation sur une page publique | [Explorer](invitation-sso-desktop.png) | [Explorer vide](explorer-mobile-empty.png) |
| Accès personnel refusé, connexion Me proposée | [403](connexion-requise-desktop.png) | [403](connexion-requise-mobile.png) |
| Régression navigation Créateur | [Créer](creer-desktop.png) | [Créer](creer-mobile.png), [bas](creer-mobile-bottom.png) |
| Régression Classement Fans fermé | [écran](classement-fans-desktop.png) | [haut](classement-fans-mobile.png), [bas](classement-fans-mobile-bottom.png) |
| Régression découverte anonyme | [Explorer](explorer-desktop.png), [profil](profil-public-desktop.png) | — |

## Limites

**Ce serveur de fixtures n’est pas WordPress.** Aucun test sur un site, un thème,
Elementor, le SSO Me distant ou un téléphone physique. Le formulaire navigateur
n’envoie pas de demande à Me ; le flux serveur est vérifié par les tests isolés.
Une recette HTTPS réelle reste obligatoire avant activation par le propriétaire.
L’[étude invité](../../../modules/FANS-SSO-RETURN-AND-GUEST.md) n’implémente ni
session provisoire ni récupération de progression. Aucune migration ou activation.

Recette : `node tests/Fans/Ui/recipe/browser.cjs`, `FANS_UI_OUTPUT` vers ce dossier,
serveur PHP local sur 8765 avec `tests/Fans/Ui/recipe/router.php`.

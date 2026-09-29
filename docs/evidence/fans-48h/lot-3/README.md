# Lot 3 — Accueil et lecture

29 septembre 2026. Base `42123d48adfac7e5e7779d56cf060ada7a489950`, 0.6.3.
Export Git LF + diff dans WSL, dépendances physiques conformes au lock (31),
PHP 8.5.4 : lint des trois PHP, **284 tests / 4 235 assertions**, aucun échec,
deux dépréciations ; PHPStan complet sans erreur. Syntaxe JS et diff vérifiés.

## Recette navigateur

Serveur PHP de fixtures + Chromium/Playwright, 1440 × 900 et 390 × 844,
contrôle de débordement à 320 × 640. **Aucun WordPress réel ni site consulté.**

- Recette antérieure complète : Explorer → profil, navigation, permissions,
  HoF invité, SSO proposé et états absents conservés.
- Nouvelle recette `tests/Fans/Ui/recipe/reading.cjs` : page suivante et retour
  première page, lien vers profil ; invité/admin/Fan refusés sur le profil propre ;
  actif avec lien, attente et suspension sans lien ; huit accès Créateur.
- Service fermé (404), erreur 503, vide et réponse mal formée : aucun texte résiduel.
- Charge HTML affichée littéralement, aucune balise exécutée ; réponse ancienne
  après pagehide ignorée ; aucun débordement ni UUID affiché.
- Le texte « Fixture de recette » dans les captures est une donnée de test,
  uniquement dans le routeur sous `tests/`, jamais dans le code produit.

## Captures

| Surface | Ordinateur | Mobile |
| --- | --- | --- |
| Accueil Fan | [capture](accueil-fan-desktop.png) | [capture](accueil-fan-mobile.png) |
| Accueil Créateur | [capture](accueil-createur-desktop.png) | [capture](accueil-creator-mobile.png) |
| Mon profil actif | [capture](mon-profil-desktop.png) | [capture](mon-profil-mobile.png) |
| Profil suspendu, privé | — | [capture](profil-suspendu-mobile.png) |
| Explorer → profil public | [Explorer](explorer-desktop.png), [profil](profil-public-desktop.png) | [vide](explorer-mobile-empty.png) |
| Navigation Créer inchangée | [capture](creer-desktop.png) | [capture](creer-mobile.png) |

Inspection visuelle : composition en cartes sur fond sombre, bandeau crème et
accents verts des planches ; grille en une colonne sur mobile, label Créateur
actif seul visible. Les cartes n’inventent pas d’activité, compteurs ou photos.
Les médias, graphiques et personnes des planches attendent des données approuvées.
Le motif CSS existant du bandeau reste une approximation, pas le relief original.

Limites : ni thème/Elementor cible, ni vrai SSO, ni terminal mobile physique.
Liste de textes globale seulement ; API filtrée par auteur absente. Édition du
profil et textes privés traités séparément. Aucune activation, migration ou release.

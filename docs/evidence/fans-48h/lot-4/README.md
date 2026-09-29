# Lot 4 — Formulaires de textes

29 septembre 2026. Base `1dfcea486ce7795bdea7734e0062edfd7e3544b2`, 0.6.3.
Export Git LF et diff dans WSL, dépendances physiques conformes au lock (31).
PHP 8.5.4, lint des cinq fichiers PHP, suite complète **284 tests / 4 253
assertions**, aucun échec, deux dépréciations ; PHPStan complet sans erreur.

## Environnement et recette

Le navigateur utilise un serveur PHP de fixtures, **pas WordPress**, avec un
adaptateur REST et un stockage de session de recette. Les UUID, cookies de rôle
et textes de recette n’existent que sous `tests/`. Le moteur de publication réel
reste inchangé et couvert par la suite PHP ; ce lot ne prouve pas les transactions
MariaDB ou la distribution WordPress de ces requêtes internes en situation réelle.

`author.cjs`, Chromium, JavaScript désactivé, 1440 × 1000 puis 390 × 844 :

- Invité/admin non lié 403 ; Fan sans profil 404 ; Créateur lié autorisé.
- Nonce invalide : 403 sans écriture. Création pending, rejeu du même POST sans
  doublon, clé conservée et texte en lecture seule après 503 puis réessai réussi.
- Édition avec révision 1, ancien POST refusé en 409 sans faux succès.
- Retrait sans confirmation refusé ; profil suspendu sans édition, mais retrait
  confirmé possible ; texte effacé dans l’affichage après réponse réelle de l’adaptateur.
- Fiche étrangère/absente : aucun texte révélé. Quota 429 explicite. Module fermé :
  aucun formulaire de gestion. Huit accès Créateur et absence de débordement.
- Recettes navigateur navigation et lecture des lots précédents également vertes.

## Captures inspectées

- [Création ordinateur](creation-desktop.png) : les quatre types restent distincts,
  seul le texte a un parcours de gestion ; textarea avec focus visible.
- [Création mobile](creation-mobile.png) : zone de texte après défilement.
- [Édition et retrait mobile](edition-mobile.png) : confirmation distincte et
  navigation fixe ; tous les contrôles fonctionnent sans JavaScript.

Le fond sombre, les accents verts, le bandeau crème et le rail reprennent le socle
V2. Aucun portrait, chiffre financier, contribution, rang ou session HoF ajouté.
La saisie ne possède pas de sauvegarde automatique ; cette limite est affichée.
Après réponse perdue, renvoyer le même POST ou vérifier la liste avant nouvelle
intention. Le retour SSO restaure le chemin canonique, pas la saisie ou les paramètres
de sélection de texte. Aucune promesse de récupération de brouillon.

Limites : aucune recette de site WordPress/Elementor/SSO distant, clavier iPhone
physique ou modération humaine nouvelle. Tous les flags de production inchangés.
Avant activation, le propriétaire doit valider le POST natif et le dispatch REST
sur sa configuration, avec profils/rôles réels. Pas de migration ou release.

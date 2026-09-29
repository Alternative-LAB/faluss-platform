# Fans — chantier du 29 septembre au 1 octobre 2026

Départ vérifié : `origin/main` `ad7c5857a0c1d65c84d8ec56b5fdbbd7dba178af`,
version réelle **0.6.3** (en-tête et constante). Fenêtre de travail : 29 septembre
20 h 52 → 1 octobre 20 h 52, heure de Paris. Aucun accès aux sites WordPress,
serveurs ou updater ; code et GitHub uniquement. Version conservée, pas de release.

## Changements pris en compte depuis #84

- #85 : préparation et publication 0.6.0.
- #86 : retrait de la récompense ALB historique dans Link et ses contrats.
- #87 : corrections de géométrie mobile et rendu Studio V3, version 0.6.2.
- #88 : focus mobile Me, canvas public et contrôles de style, version 0.6.3.
- Aucun changement des fichiers `src/Fans/` depuis #84 dans cette base.

## Lots, dans l’ordre des dépendances

1. Classement Fans fermé, contrat et arbitrages ; navigation conforme V2.
2. Retour SSO vers une destination Fans autorisée, transport côté serveur lié
   à l’état existant, tests positifs/négatifs ; aucune seconde authentification.
   Étude de session/alias/badge invité, sans promettre de récupération absente.
3. Profil propre, accueil et découverte raccordés aux contrats publics disponibles.
4. Création et gestion des textes modérés : nonce, quotas, idempotence,
   révisions et erreurs réelles ; services/prestations/produits fermés.
5. Revue de chaque écran, tests utiles desktop/mobile et matrice de sortie.

## Matrice de suivi initiale

Lot 1 fusionné : [PR #89](https://github.com/Alternative-LAB/faluss-platform/pull/89),
`main` `70933b530d4b49a6d8b0c6b2ea6e318a91d11856`, CI de branche et de main vertes.
279 tests / 4 152 assertions ; [captures et limites](../evidence/fans-48h/lot-1/README.md).
Lot 2 fusionné : [PR #90](https://github.com/Alternative-LAB/faluss-platform/pull/90),
`main` `42123d48adfac7e5e7779d56cf060ada7a489950`, CI de branche et de main vertes.
284 tests / 4 226 assertions ; [retour SSO et étude invité](FANS-SSO-RETURN-AND-GUEST.md),
avec preuve isolée du flux existant ; validation cible réservée au propriétaire.
Lot 3 fusionné : [PR #91](https://github.com/Alternative-LAB/faluss-platform/pull/91),
`main` `1dfcea486ce7795bdea7734e0062edfd7e3544b2`, CI de branche et de main vertes.
[Lecture et accueil](FANS-READING-UI.md), 284 tests / 4 235 assertions,
recettes navigateur isolées et [captures](../evidence/fans-48h/lot-3/README.md).
Lot 4 : [gestion des textes](FANS-AUTHOR-UI.md), 284 tests / 4 253 assertions,
recette sans JavaScript, [captures et limites](../evidence/fans-48h/lot-4/README.md).

Les cases « interface prête mais service absent » n’attestent aucune fonctionnalité
métier. Les captures seront produites depuis un serveur de tests PHP isolé :
**ni WordPress réel, ni Elementor, ni validation cible**. Le propriétaire conserve
la recette réelle avant activation.

| Écran ou parcours | État de départ et limite | Lot prévu |
| --- | --- | --- |
| Explorer → profil public | Rendu API de catégories approuvées testé dans #84 ; nom/portrait approuvé absents | 3, 5 |
| Accueil Fan / Créateur | Navigation et liste globale de textes approuvés raccordées et testées ; personnalisation/statistiques absentes | 3 |
| HoF invité / connecté | Interface prête mais moteur absent | 5 |
| Session HoF | Interface prête mais session absente | 5 |
| Classements HoF | Interface prête mais moteur absent | 5 |
| Classement Fans | Interface prête et testée, service et décisions requis ; #89 fusionnée | 1 terminé |
| Messagerie | Interface prête mais service absent ; modération/blocage requis | 5 |
| Mon espace Fan | Interface prête mais progression PC absente | 5 |
| Progression Créateur | Interface prête mais score HoF absent ; aucune finance | 5 |
| Mon profil Créateur | Lecture du statut et de la catégorie raccordée et testée ; édition éditoriale absente | 3 |
| Créer — contenu texte | UI de création/édition/retrait et liste privée raccordées et testées sur adaptateurs ; moteur de modération existant inchangé | 4 |
| Créer — prestation / service / produit | Interface prête mais services absents | 4, 5 |
| Ma boutique | Interface prête ; pas de gestion propriétaire ni transaction disponible | 5 |
| SSO Me → Fans | Retour borné implémenté ; tests isolés, échange réel à valider par le propriétaire | 2 |
| Session et reprise invité | Décisions requises ; aucune attribution ni progression à préserver disponible | 2 |
| Attribution PF, corrections et remboursements | Contrat Hub non ratifié ; aucun moteur à prétendre disponible | Hors moteur dans ces lots |

Chaque lot doit indiquer ses tests positifs/négatifs, son SHA et ses limites.
Les recettes réelles antérieures ne valent pas preuve du nouveau diff. La matrice
sera actualisée au fil des PR, puis consolidée à l’échéance.

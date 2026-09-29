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
Lot 4 fusionné : [PR #92](https://github.com/Alternative-LAB/faluss-platform/pull/92),
`main` `e5cf47465f91e3459fa2b0518ab5ca967813c951`, CI de branche et de main vertes.
[Gestion des textes](FANS-AUTHOR-UI.md), 284 tests / 4 253 assertions,
recette sans JavaScript, [captures et limites](../evidence/fans-48h/lot-4/README.md).
Lot 5 fusionné : [PR #93](https://github.com/Alternative-LAB/faluss-platform/pull/93),
`main` `cbc2cae1751d7dda1a70825470e83e3fd8df39f7`, CI de branche et de main vertes.
404 dans le shell et 168 contrôles rôle/route/viewport ;
[matrice d’évaluation intermédiaire](FANS-48H-EVALUATION.md) et
[preuves](../evidence/fans-48h/lot-5/README.md). La revue des autres API existantes
se poursuit avant le bilan final des 48 heures.

Lot 6 fusionné : [PR #94](https://github.com/Alternative-LAB/faluss-platform/pull/94),
`main` `d25a07b5b0a291daff00fb427f4c5e423d0329a2`, CI de branche et de main vertes.
[Demande de profil créateur](FANS-CREATOR-ADMISSION-UI.md),
284 tests / 4 262 assertions, parcours sans JavaScript et régressions de rôles ;
[preuves et captures](../evidence/fans-48h/lot-6/README.md).

Lot 7 fusionné : [PR #95](https://github.com/Alternative-LAB/faluss-platform/pull/95),
`main` `9062655bb84afc4fb3e03b9879c239d34bdb0ac7`, CI de branche et de main vertes.
[Lecture des images de publications](FANS-PUBLICATION-IMAGE-UI.md),
contrat de diffusion existant, chargement à la demande et refus explicites ;
[preuves et captures](../evidence/fans-48h/lot-7/README.md).

Lot 8 fusionné : [PR #96](https://github.com/Alternative-LAB/faluss-platform/pull/96),
`main` `2683da08abe24991ee434a0f5e3504f62f8f5be7`, CI de branche et de main vertes.
Filtre de [publications par créateur](FANS-PUBLICATIONS.md#filtre-public-par-créateur),
contrat serveur préalable au raccordement de la fiche publique. 286 tests / 4 361
assertions en environnement PHP isolé ; curseurs liés au filtre, suspension entre
pages, retrait, liste vide, projections publiques et compatibilité v1 vérifiés.
Aucune interface modifiée dans ce lot ; pas de nouvelle capture requise.

Lot 9 fusionné : [PR #97](https://github.com/Alternative-LAB/faluss-platform/pull/97),
`main` `43eec96f74b9236e96ae5feb103629bb8a16964c`, CI de branche et de main vertes.
Raccordement du profil public à la liste filtrée du lot 8 ; quatre rôles,
pagination, refus de réponse étrangère, états vide/erreur et 404 document vérifiés.
[Captures et preuves](../evidence/fans-48h/lot-9/README.md).

Lot 10 : recettes WebKit Windows 26.5, correction du focus clavier des images,
contrôles tactiles des huit accès Créateur à 320/390 px et 168 cas de la matrice.
[Captures, reproduction et limite typographique](../evidence/fans-48h/lot-10/README.md).

### Bilan communiqué le 29 septembre vers 22 h (Paris)

Six PR #89 à #94 fusionnées. Dernière CI vérifiée :
[PHP](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36623769234),
[JS](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36623769331),
[ZIP](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36623769289).
Navigation, retour SSO, lecture, écriture des textes, refus et admission testés en
isolation ; 284 tests / 4 262 assertions et matrice de 168 cas. Le lot médias se
poursuit. HoF/PF, messagerie, commerce et identité éditoriale restent incomplets.
Aucun site ni flag touché ; aucune preuve WordPress cible déduite des captures.

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

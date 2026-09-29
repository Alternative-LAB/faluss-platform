# Fans — matrice d’évaluation intermédiaire

État du 30 septembre 2026, code publié `dc13eda5c772dc3222045e2fc9e26024237f3e7a`
(#89 à #104), version **0.7.0**. Revue visuelle consolidée du lot 14 et correctif
clavier du lot 15 ; les captures conservent leurs SHA de provenance.
**Ce n’est pas encore le bilan final des 48 heures.** Base initiale vérifiée :
`ad7c5857a0c1d65c84d8ec56b5fdbbd7dba178af`, alors en version 0.6.3.
Les modifications Me/Link postérieures à #84 ont été conservées.

La publication supplémentaire autorisée a été accomplie par les workflows
GitHub : tag **v0.7.0**, archive avec dépendances validée et upload réussi.
La [preuve de publication](#livraison-070) ne prouve ni sa proposition dans un
WordPress licencié, ni une installation, ni la recette cible. Ces vérifications
restent au propriétaire. Aucun autre numéro ou workflow de publication n’est
autorisé automatiquement par la poursuite de ce chantier.

« Fonctionnel et testé » signifie ici code de service testé et/ou adaptateur UI
testé, selon la preuve indiquée. **Aucune installation WordPress ni aucun site
n’a été consulté pendant ce chantier.** Les cookies de rôle, textes et comptes
des captures sont des fixtures sous `tests/`, jamais des données produit.

## Écrans et parcours

| Écran/parcours | Route sous `/faluss-fans/` | Classement de l’état | Preuve et limite |
| --- | --- | --- | --- |
| Explorer — lecture structurée | `fan/explorer`, `creator/explorer` | Fonctionnel et testé | Filtres, vide/erreur, catégories et liens testés ; ni nom public ni portrait approuvé disponibles |
| Profil public — fiche structurée et textes | `creators/{uuid}` | Fonctionnel et testé | Accès invité ; pagination par créateur ; HTTP 404 de la page pour absent/suspendu/retiré ; aucune identité inventée |
| Nom, portrait, bio publics approuvés | Explorer et profils | Décision ou accès requis | Contrat éditorial, modération, retrait et rétention absents ; découverte provisoire déclarée |
| Nombre public de suivis | Sur le profil public actif | Fonctionnel et testé | Compteur REST existant seulement ; zéro réel distingué d’une route fermée ; aucune action sociale ouverte |
| Textes récents | Sur Explorer, les accueils et le profil public | Fonctionnel et testé | Liste globale sur accueil/Explorer, filtrée par auteur sur son profil ; curseurs liés au filtre, rendu texte, erreurs et réponses anciennes |
| Image associée à un texte public — lecture | Dans la carte de publication | Fonctionnel et testé | Dérivé JPEG à la demande, opt-in fermé ; aucun portrait ; description alternative éditoriale et recette d’hébergement requises avant activation |
| Gestion privée des images | Futur parcours Créer | Décision ou accès requis | Pas d’aperçu privé autorisé ni de métadonnée suffisante pour sélectionner une image de façon fiable |
| HoF | `fan/hof`, `creator/hof` | Interface prête mais service absent | Invité 200 ; aucun rang, session ou point ; moteur et politiques encore requis |
| Session HoF | `fan/hof/session`, `creator/hof/session` | Interface prête mais service absent | Accès personnel, aucune session à rejoindre |
| Classements HoF | `fan/classements`, `creator/classements` | Interface prête mais service absent | Aucun calcul ou classement public |
| Classement Fans | `fan/classement-fans` | Interface prête mais service absent | PF réellement attribués seulement ; pack seul zéro, PC exclus ; attestations/corrections Hub absentes et politiques non ratifiées |
| Accueil Fan — navigation/lecture | `fan/accueil` | Fonctionnel et testé | Pas de fil personnalisé, compteur de communauté ou progression inventé |
| Accueil Créateur — navigation/lecture | `creator/accueil` | Fonctionnel et testé | Huit accès ; aucun wallet, solde PF utilisable, euro ou statistique financière |
| Mon profil Créateur — lecture | `creator/mon-profil` | Fonctionnel et testé | Catégorie/état propriétaire, lien public seulement si actif ; édition éditoriale absente ; aucune élévation de rôle |
| Messages Fan/Créateur | `fan/messages`, `creator/messages` | Interface prête mais service absent | Aucun envoi ; moteurs, blocage, signalement, rétention et modération à définir |
| Mon espace Fan | `fan/espace` | Interface prête mais service absent | Pas de progression PC, niveau ou récompense attribués |
| Demande de profil Créateur | Dans `fan/espace` | Fonctionnel et testé | Catégorie, nonce, profil pending, rejeu et conflit ; approbation administrative conservée |
| Progression Créateur | `creator/progression` | Interface prête mais service absent | Score HoF absent ; aucune finance |
| Créer — choix entre quatre types | `creator/creer` | Fonctionnel et testé | Contenu/prestation/service/produit distingués, seule gestion des textes raccordée |
| Créer/éditer/retirer un texte | `creator/creer` | Fonctionnel et testé | POST natif sans JS, nonce, REST existant, idempotence, révisions, quota, retrait confirmé ; modération humaine obligatoire |
| Créer une prestation, un service ou un produit | Choix dans `creator/creer` | Interface prête mais service absent | Aucun formulaire, réservation, gestion de stock ou publication commerciale simulés |
| Ma boutique | `creator/boutique` | Interface prête mais service absent | Gestion propriétaire/réservations/commandes absentes ; refus d’achat inchangés |
| Retour SSO — contrat de navigation | Boutons publics et pages privées 403 | Fonctionnel et testé | État consommé, retour local signé, sélection du texte conservée, callback exact ; pas de preuve réseau Me réelle ni conservation du contenu non envoyé |
| Invité provisoire, alias/badge/reprise | Étude seulement | Décision ou accès requis | Aucun `Guest_…` runtime, badge gagné, contribution fictive ou promesse de sauvegarde |
| Barre WordPress — filtre de visibilité | Routes Fans | Fonctionnel et testé | Barre cachée pour membres ordinaires, conservée pour `manage_options` ; rendu WordPress non réévalué ici |
| Navigation mobile et clavier — adaptateurs | Toutes les routes du shell | Fonctionnel et testé | Chromium et WebKit Windows, huit accès Créateur par toucher à 320/390 px, focus image et pagination corrigés ; limites clavier WebKit Windows documentées au lot 15 |
| Recette cible WordPress/Elementor/SSO et Safari physique | Site du propriétaire | Décision ou accès requis | Hors périmètre d’accès du chantier ; typographie WebKit Windows non représentative de Safari réel ; indispensable avant activation |

## Couverture des exigences du chantier

| Exigence | Preuve autoritative et portée | Conclusion de revue |
| --- | --- | --- |
| Dernier main et changements depuis #84 | Base initiale 0.6.3 et historique dans [le suivi](FANS-48H.md) ; code livré 0.7.0 au SHA audité | Les anciens travaux Me/Link sont conservés ; aucun départ du tag 0.6.0 |
| Planche Fan et planche Créateur V2 | [40 captures du même code](../evidence/fans-48h/lot-14/README.md), 18 vues de référence + Classement Fans + Mon profil distinct | DA et navigation du socle conservées ; densité et contenu métier des planches non reproduits sans capacités réelles |
| Invité, Fan lié, Créateur lié, administrateur | [Matrice 312 cas](../evidence/fans-48h/lot-12/README.md), [contrats UI](../../tests/Fans/Ui/FansUiContractTest.php) | Statuts des pages, routes personnelles et profils non publics testés ; aucune preuve de vrais comptes de site |
| API existantes et états indisponibles | Recettes lecture, auteur, admission, images, profil et compteur ; lots 3, 4, 6, 7, 9 et 13 | Adaptateurs raccordés aux contrats disponibles ; aucune API Hub supposée |
| Identity Me seule et retour d’origine | [Flux SSO isolé](../../tests/Fans/Sso/FansSsoReturnFlowTest.php), [contrat](FANS-SSO-RETURN-AND-GUEST.md) | Retour signé après consommation, sélection du texte, sous-répertoire, refus des destinations non autorisées ; pas de nouveau passwordless/SMTP |
| Étude invité, alias, badge et proposition de compte | [Propositions et conditions](FANS-SSO-RETURN-AND-GUEST.md#invité--étude-pas-une-session-implémentée) ; CTA Me dans les captures invité | Étude fournie ; reprise de progression non implémentée et jamais promise |
| Huit accès Créateur, label actif seul, routes distinctes | Contrats UI et recette `browser.cjs`, captures lot 14 | Accueil, Explorer, HoF, Messages, Créer, Ma boutique, Progression, Mon profil ; 320/390 px et ordinateur |
| Quatre types de création, absence de finance Créateur | Contrats UI, recettes auteur/matrice, captures Créer/Progression | Texte réel seulement ; trois autres choix indisponibles ; aucun wallet/solde PF/euro ajouté |
| Classement Fans indépendant, PF attribués seulement | [Contrat et arbitrages](FANS-FAN-RANKING.md), `testFanRankingStaysDistinctPrivateAndUnavailable` | Écran fermé sans score ni rang ; pack seul et PC exclus ; politique publique non décidée |
| Unicité, annulation, remboursement, corrections/rejeux | [Scénarios futurs explicites](FANS-FAN-RANKING.md#garanties-attendues--pas-un-moteur-existant), [façade réelle](../../src/TokenEngine/TokenEngineContract.php) | Service absent. Les tests `FakeHub` ne prouvent pas un ledger ni des compensations opérationnelles |
| HoF 1 PF acheté, attesté et attribué = 1 point | ADR 0018 et contrat Hub ; vues HoF indisponibles | Règle documentée, aucun moteur/rang/session inventé, PC séparés |
| Modération et refus serveur | [Publications](../../tests/Fans/Publications/TextPublicationTest.php), [Store](../../tests/Fans/Store/StoreCatalogTest.php) et CI du SHA audité | Propriétaire, révision, retrait, état public et achats fermés conservés |
| PR courtes, protections, contrôles et captures | Historique #89–#104 et suivi des lots ; [CI PHP](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36637193361), [JS](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36637193458), [ZIP](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36637193340) | Contrôles du main publié réussis : 289 tests, 4 434 assertions ; aucun push direct ni contournement de protection |
| Sites, flags, déploiement et paiement hors périmètre | Aucun diff de workflows, Identity, Token Engine ou Link ; opt-ins serveur conservés, recettes sur fixtures | Aucun site consulté, aucune installation/activation ; la seule publication autorisée séparément est 0.7.0 via GitHub |
| Livraison par le circuit normal de mise à jour | [Prepare release](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36636869815), PR #104, [Publish private release](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36637193352), tag et archive | Upload 0.7.0 confirmé dans GitHub ; contrôle de disponibilité WordPress réservé au propriétaire, aucun contournement de l’updater |
| Bilans quotidiens et clôture 48 h | Bilan du 29 septembre dans le suivi ; échéance 1 octobre 20 h 52 Paris | Bilan du 30 septembre et matrice finale encore à fournir ; ce document reste intermédiaire |

## Droits vérifiés

Les 18 routes Fan/Créateur sont testées avec et sans slash final sur les deux
viewports, avec les quatre rôles. Les liens canoniques restent sans slash final.
Les doubles slashs et segments supplémentaires ne donnent aucun accès.

- Invité et administrateur non lié : Explorer, profil public actif et HoF en
  lecture ; pages personnelles HTTP 403. Le SSO n’accorde pas de rôle privilégié.
- Fan lié : espaces Fan ; espace Créateur sans profil HTTP 404, retour Explorer.
- Créateur lié : huit accès et routes distinctes ; état du profil ne vaut ni
  identité vérifiée, ni autorisation de vente. Le serveur contrôle chaque écriture.
- Profil pending/suspended : fiche publique absente ; lecture propriétaire,
  retrait possible, nouvelle création/édition refusée selon le moteur.
- Les outils de modération administrative existants restent séparés. Leur recette
  WordPress antérieure ne constitue pas une preuve du nouveau diff.

## Livraison 0.7.0

[PR #104](https://github.com/Alternative-LAB/faluss-platform/pull/104) fusionnée
après six contrôles verts sur `9ba511fb5c7e4a7f3cf73309b903c2acdae5a992`.
Le tag annoté `v0.7.0` résout vers le code publié indiqué en tête de cette matrice.
Le workflow a exécuté avec succès l’installation des dépendances de production,
la construction, la validation WordPress du ZIP, le tag, l’upload et le stockage
de l’artefact. Son journal d’upload retourne `success: true`, `version: 0.7.0`.

- [Artefact officiel](https://github.com/Alternative-LAB/faluss-platform/actions/runs/36637193352/artifacts/11064268331), rétention de 30 jours.
- ZIP du plugin à l’intérieur : `faluss-platform.zip`, 4 323 446 octets,
  SHA-256 `8a8916154c0756ac8abc0da9bedf30c5bf74991c79b24f02c0327e0ba4ce89d3`.
- [Compte rendu de livraison](https://github.com/Alternative-LAB/faluss-platform/pull/104#issuecomment-5899944067), dont distinction entre le digest de l’enveloppe GitHub et celui du ZIP du plugin.

L’upload est attesté par GitHub exclusivement. Aucun accès direct au serveur,
aucune vérification de licence/cache/notification dans WordPress. Aucun ZIP
installé ou plugin remplacé manuellement ; aucun flag activé. Les changements
documentaires postérieurs ne modifient pas le paquet publié.

## Ce qui empêche de déclarer Fans terminé

1. **Contrat économique Hub non opérationnel** : preuves d’achat, attribution,
   correction, remboursements partiels, rejeux et réconciliation manquent. Aucun
   moteur Fans parallèle ne peut les remplacer. HoF : 1 PF acheté, attesté et
   effectivement attribué = 1 point ; classement Fan distinct, PC séparés.
2. **Décisions du Classement Fans** : période, égalités, pseudonymes, invités et
   abus restent proposées dans [le contrat](FANS-FAN-RANKING.md), pas ratifiées.
3. **Identité éditoriale et découverte** : nom/portrait/bio approuvés, modération,
   retrait et rétention à contractualiser. Les fiches anonymes sont provisoires.
4. **Moteurs absents** : messagerie, progression PC, sessions HoF, réservations,
   gestion commerciale et transactions. Les composants indisponibles ne sont pas
   présentés comme des fonctionnalités terminées.
5. **Gestion privée des médias à résoudre** : la liste Images expose seulement
   identifiant, état et révision, sans aperçu autorisé au créateur. Une sélection
   fiable dans une galerie ne peut pas être prétendue disponible. La lecture par
   auteur et celle des images publiques à la demande sont raccordées, avec les
   limites du [contrat UI](FANS-PUBLICATION-IMAGE-UI.md). L’admission par catégorie
   est raccordée, sans auto-approbation. Le suivi social minimal
   ne fournit pas blocage/signalement/rétention ; aucune ouverture sociale complète
   n’est prétendue. Ces limites ne doivent pas être cachées par l’UI.
6. **Validation cible par le propriétaire avant activation** : thème/Elementor,
   vrais comptes et SSO HTTPS/cookies, nonces REST internes, styles/barre WP,
   navigateur mobile physique, modération humaine et procédure de retrait.
   Cette validation n’est pas remplacée par la CI ou les captures de fixtures.
   Le port WebKit Windows utilisé pour les tests fonctionnels rend les fontes
   variables très fines malgré leurs poids déclarés ; ses captures ne valident
   donc pas la typographie Safari macOS/iOS. Voir le diagnostic du lot 10.

## Preuves

- [Suivi des PR et base](FANS-48H.md).
- [Lot 1 : navigation/classement](../evidence/fans-48h/lot-1/README.md).
- [Lot 2 : retour SSO](../evidence/fans-48h/lot-2/README.md).
- [Lot 3 : lecture](../evidence/fans-48h/lot-3/README.md).
- [Lot 4 : textes natifs](../evidence/fans-48h/lot-4/README.md).
- [Lot 5 : matrice routes/HTTP](../evidence/fans-48h/lot-5/README.md).
- [Lot 6 : demande de profil](../evidence/fans-48h/lot-6/README.md).
- [Lot 7 : images publiques](../evidence/fans-48h/lot-7/README.md).
- [Lot 8 : filtre serveur, PR #96](https://github.com/Alternative-LAB/faluss-platform/pull/96).
- [Lot 9 : profil et publications](../evidence/fans-48h/lot-9/README.md).
- [Lot 10 : WebKit et clavier](../evidence/fans-48h/lot-10/README.md).
- [Lot 11 : retour vers un texte, PR #99](https://github.com/Alternative-LAB/faluss-platform/pull/99).
- [Lot 12 : chemins avec slash final](../evidence/fans-48h/lot-12/README.md).
- [Lot 13 : nombre public de suivis](../evidence/fans-48h/lot-13/README.md).
- [Lot 14 : couverture visuelle consolidée](../evidence/fans-48h/lot-14/README.md).
- [Lot 15 : lecture au clavier](../evidence/fans-48h/lot-15/README.md).

Aucun flag activé, déploiement, paiement ou modification des sites. Publication
0.7.0 vérifiée séparément ; aucune conclusion d’activation ou de chantier terminé.

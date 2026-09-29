# Fans — matrice d’évaluation intermédiaire

État du 29 septembre 2026, après les lots #89 à #92 et la recette transversale du
lot 5. **Ce n’est pas encore le bilan final des 48 heures.** Version conservée :
0.6.3. Base initiale vérifiée : `ad7c5857a0c1d65c84d8ec56b5fdbbd7dba178af`.
Les modifications Me/Link postérieures à #84 ont été conservées.

« Fonctionnel et testé » signifie ici code de service testé et/ou adaptateur UI
testé, selon la preuve indiquée. **Aucune installation WordPress ni aucun site
n’a été consulté pendant ce chantier.** Les cookies de rôle, textes et comptes
des captures sont des fixtures sous `tests/`, jamais des données produit.

## Écrans et parcours

| Écran/parcours | Route sous `/faluss-fans/` | Classement de l’état | Preuve et limite |
| --- | --- | --- | --- |
| Explorer, invité/Fan/Créateur | `fan/explorer`, `creator/explorer` | Fonctionnel et testé pour la lecture structurée ; identité éditoriale requise | Filtres, vide/erreur, catégories et liens testés ; ni nom public ni portrait approuvé disponibles |
| Profil public | `creators/{uuid}` | Fonctionnel et testé pour sa fiche structurée | Accès invité ; HTTP 404 de la page pour absent/suspendu/retiré ; aucune identité inventée |
| Textes récents | Sur Explorer et les accueils | Fonctionnel et testé sur adaptateurs | REST public paginé existant ; textes approuvés, rendu texte, curseurs, erreurs et réponses anciennes ; liste globale, pas de filtre auteur |
| HoF | `fan/hof`, `creator/hof` | Interface prête mais service absent | Invité 200 ; aucun rang, session ou point ; moteur et politiques encore requis |
| Session HoF | `fan/hof/session`, `creator/hof/session` | Interface prête mais service absent | Accès personnel, aucune session à rejoindre |
| Classements HoF | `fan/classements`, `creator/classements` | Interface prête mais service absent | Aucun calcul ou classement public |
| Classement Fans | `fan/classement-fans` | Interface prête mais service absent ; décisions requises | PF réellement attribués seulement ; pack seul zéro, PC exclus ; attestations et corrections Hub absentes |
| Accueil Fan | `fan/accueil` | Fonctionnel et testé pour navigation/lecture | Pas de fil personnalisé, compteur de communauté ou progression inventé |
| Accueil Créateur | `creator/accueil` | Fonctionnel et testé pour navigation/lecture | Huit accès ; aucun wallet, solde PF utilisable, euro ou statistique financière |
| Mon profil Créateur | `creator/mon-profil` | Fonctionnel et testé en lecture ; édition éditoriale absente | Catégorie/état propriétaire, lien public seulement si actif ; aucune élévation de rôle |
| Messages Fan/Créateur | `fan/messages`, `creator/messages` | Interface prête mais service absent | Aucun envoi ; moteurs, blocage, signalement, rétention et modération à définir |
| Mon espace Fan | `fan/espace` | Interface prête mais service absent | Pas de progression PC, niveau ou récompense attribués |
| Progression Créateur | `creator/progression` | Interface prête mais service absent | Score HoF absent ; aucune finance |
| Créer — quatre types | `creator/creer` | Fonctionnel et testé pour choix et texte ; autres services absents | Contenu/prestation/service/produit distingués, seule gestion des textes raccordée |
| Créer/éditer/retirer un texte | `creator/creer` | Fonctionnel et testé sur adaptateurs | POST natif sans JS, nonce, REST existant, idempotence, révisions, quota, retrait confirmé ; modération humaine obligatoire |
| Ma boutique | `creator/boutique` | Interface prête mais service absent | Gestion propriétaire/réservations/commandes absentes ; refus d’achat inchangés |
| Retour SSO Me | Boutons publics et pages privées 403 | Fonctionnel et testé en isolation ; accès cible requis | État consommé, retour local signé, callback exact ; pas de preuve réseau Me réelle |
| Invité provisoire, alias/badge/reprise | Étude seulement | Décision requise | Aucun `Guest_…` runtime, badge gagné, contribution fictive ou promesse de sauvegarde |
| Administration WordPress | Outils existants | Fonctionnel au niveau du filtre testé ; accès cible requis | Barre cachée pour membres ordinaires sur routes Fans, conservée pour `manage_options` ; rendu WordPress non réévalué ici |

## Droits vérifiés

- Invité et administrateur non lié : Explorer, profil public actif et HoF en
  lecture ; pages personnelles HTTP 403. Le SSO n’accorde pas de rôle privilégié.
- Fan lié : espaces Fan ; espace Créateur sans profil HTTP 404, retour Explorer.
- Créateur lié : huit accès et routes distinctes ; état du profil ne vaut ni
  identité vérifiée, ni autorisation de vente. Le serveur contrôle chaque écriture.
- Profil pending/suspended : fiche publique absente ; lecture propriétaire,
  retrait possible, nouvelle création/édition refusée selon le moteur.
- Les outils de modération administrative existants restent séparés. Leur recette
  WordPress antérieure ne constitue pas une preuve du nouveau diff.

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
5. **Revue des parcours existants à poursuivre** : admission d’un profil créateur
   par catégorie, lecture par auteur et éventuels médias. Le suivi social minimal
   ne fournit pas blocage/signalement/rétention ; aucune ouverture sociale complète
   n’est prétendue. Ces limites ne doivent pas être cachées par l’UI.
6. **Validation cible par le propriétaire avant activation** : thème/Elementor,
   vrais comptes et SSO HTTPS/cookies, nonces REST internes, styles/barre WP,
   navigateur mobile physique, modération humaine et procédure de retrait.
   Cette validation n’est pas remplacée par la CI ou les captures de fixtures.

## Preuves

- [Suivi des PR et base](FANS-48H.md).
- [Lot 1 : navigation/classement](../evidence/fans-48h/lot-1/README.md).
- [Lot 2 : retour SSO](../evidence/fans-48h/lot-2/README.md).
- [Lot 3 : lecture](../evidence/fans-48h/lot-3/README.md).
- [Lot 4 : textes natifs](../evidence/fans-48h/lot-4/README.md).
- [Lot 5 : matrice routes/HTTP](../evidence/fans-48h/lot-5/README.md).

Aucun flag activé, déploiement, release, paiement ou modification des sites.

# F1b R2 — règles produit approuvées, capacités à livrer

**Décideur : ALB-Origine. Validation explicite du 6 octobre 2026.**
La validation remplace les recommandations de R2 sur le fuseau du relevé et les
égalités. B1–B6 sont autorisés selon leurs dépendances ; tout contrat Hub nouveau
ou modifié exige un accord distinct avant son implémentation.
[F1a](FANS-PF-F1A-CLOSED.md) reste une projection privée fermée, pas un classement
public opérationnel. Fusion de code, publication et activation sont trois actes
distincts. Aucun accès aux sites, achat réel, migration de production ou flag.

## Trois objets approuvés

| Objet | Calendrier et fonction | Gestion |
| --- | --- | --- |
| Relevé mensuel privé | Mois civil Europe/Paris ; vue des contributions et de leur net corrigé dans le mois d'origine | Membre autorisé, sans compétition ni remise à zéro d'un classement |
| Classements persistants | Général et catégories, projections Créateurs/Fans séparées ; cumul sans remise à zéro mensuelle | Origine d'ouverture réelle des attributions admissibles, enregistrée explicitement |
| Sessions HoF | Compétitions bornées, indépendantes du relevé ; portées locale, nationale et internationale | Créateurs seuls ou avec coorganisateurs acceptant leur rôle ; administration pour modération et recours |

Un relevé Créateur ne présente ni wallet, solde PF, montant, reçu privé d'achat
ou information financière des Fans. Aucun ledger PF parallèle. Achat du pack
seul = zéro score ; PC et PF historiques non admissibles sont exclus.

## P1 — calendrier et origine approuvés

- Relevé par mois civil **Europe/Paris**, début inclus / fin exclue, avec gestion
  été/hiver. Construire les bornes dans ce fuseau, puis comparer en UTC.
- Instants techniques stockés en UTC ; dates de présentation avec fuseau indiqué.
  Le back-office utilise le fuseau configuré dans WordPress.
- Une correction révise l'attribution dans son mois d'origine, déterminé par
  `confirmed_at` Hub ; ni sa réception réseau ni la date de correction ne changent
  ce rattachement.
- Classements persistants général et catégories, familles Créateurs/Fans séparées,
  sans remise à zéro au changement de mois. Catégorie approuvée fixée dans le
  contexte de contribution ; aucune édition de profil ne déplace l'historique.
- Origine : instant de l'ouverture **réelle** des attributions admissibles,
  explicitement enregistré et versionné. Aucune origine de recette ne devient
  une ouverture réelle ; aucune donnée fictive ou historique sans contexte attesté.
- Net positif et visibilité admissible requis pour une place publique. Aucun rang
  inventé pour un compte absent, masqué ou à zéro.

Exemples : mars 2026 commence à `2026-02-28T23:00:00Z` et finit à
`2026-03-31T22:00:00Z` (743 heures). Octobre 2026 commence à
`2026-09-30T22:00:00Z` et finit à `2026-10-31T23:00:00Z` (745 heures).
Une attribution confirmée le 31 mars à 22:00 UTC appartient à avril à Paris.
Une correction reçue en mai reste dans le mois de cette confirmation.

## P2 — sessions, participation et plafonds approuvés

### Création et droits

Créateur lié par SSO, actif et doté d'une présentation approuvée : création solo
ou invitation de coorganisateurs. Chaque coorganisateur accepte explicitement
son rôle. L'initiateur gère programmation, annulation et nomination ; les
coorganisateurs invitent et traitent les admissions avec droits revalidés à chaque
action, sans transfert implicite de propriété ni édition de score.

Organisateur et participant sont distincts. Création solo n'impose pas un seul
participant ; création collective ne crée ni compte collectif ni solde commun.
Scores individuels ; participation volontaire, règles acceptées et admission
selon critères annoncés. Les organisateurs peuvent participer avec cette qualité
visible et les mêmes contrôles. Refus motivé et recours accessibles.
Une session à un seul participant ne fabrique ni compétition ni récompense.

Règles, dates, catégorie et portée figées après ouverture. Une admission tardive
concerne seulement les attributions futures. Changement structurel : nouvelle
version avant ouverture ou nouvelle session, sans transfert silencieux des faits.
Admission du profil et participation ne valident aucun partenariat commercial.

### Portées et calendrier

| Portée | Règle approuvée | Dépendance à construire |
| --- | --- | --- |
| Locale | Territoire d'activité principal du Créateur, nommé et rattaché à un pays | Référentiel, déclaration et examen selon critères publics |
| Nationale | Pays du territoire d'activité principal déclaré et examiné | Même procédure d'admission territoriale |
| Internationale | Aucune restriction territoriale de session pour les Créateurs | Identité liée, profil actif, présentation approuvée et règles acceptées |

Les Fans peuvent soutenir depuis les **pays autorisés par Faluss**, sans
restriction territoriale supplémentaire liée à la session. Aucune autorisation
universelle de pays n'est inventée. IP, langue et SSO ne certifient pas un domicile.
L'examen territorial est une admission éditoriale, pas une certification d'identité.

Dates choisies par les Créateurs, indépendantes du mois civil, début inclus / fin
exclue. Fuseau IANA conservé ; instants enregistrés/comparés en UTC. Heure locale
inexistante refusée, heure ambiguë explicitement désambiguïsée.

Plafonds initiaux : **90 jours par session**, **trois sessions simultanément
ouvertes par organisateur**, coorganisation comprise, **dix sessions par attribution**.
Révision future explicite ; aucune modification silencieuse des règles déjà
ouvertes. La durée maximale est vérifiée entre les instants, sans calcul de mois.

### Attribution à plusieurs projections

Sessions choisies explicitement **avant attribution**, parmi celles auxquelles le
Créateur est admis. Une seule consommation Hub alimente le général, les catégories,
la projection Fan et les sessions admissibles : une contribution par attribution
et dimension. Aucun rattachement rétroactif ni ajout automatique à toutes les
sessions. Les dimensions ne s'additionnent pas en total économique.

Le contexte canonique (origine, politique, catégorie, versions de session et
admissions) doit être attesté et lié à la consommation. Le choix navigateur,
une association SQL locale ou le profil actuel ne suffisent pas.
Contexte expiré, fermeture ou admission retirée : refus avant consommation,
sans supprimer silencieusement un choix. Après résultat incertain : intention
et clé originales, lookup primaire, jamais un second débit ou une nouvelle clé.

Cette capacité n'existe pas encore dans les DTO H3/H4. Son
[extension Hub proposée](HUB-PF-B3-RANKING-PROPOSAL.md) reste soumise à accord.

## P3 — places uniques et date d'atteinte approuvées

Ordre identique pour Créateurs, Fans et sessions :

1. Score net corrigé décroissant.
2. À score égal, date d'atteinte du score croissante, reconstruite depuis les
   consommations confirmées Hub encore admissibles après corrections.
3. À horodatage identique, ordre autoritatif stable des attributions Hub.
4. Ultime départage : identifiant immuable non affiché.

### Définition reconstruisible

Pour un participant et une dimension, regrouper les allocations d'une attribution
une seule fois. Pour chaque attribution `a`, retenir le net `n(a)` de la dernière
révision complète attestée, après corrections/litige et règles d'admission.
Retirer les contributions nulles et non admissibles ; aucune valeur négative,
PC, achat du pack ou ancien reçu n'entre dans le calcul.

Ordonner les contributions par `(confirmed_at Hub, ordre Hub)`.
Le score `S = somme n(a)` ; le préfixe `C_i = somme des n(a_j), j <= i`.
La date d'atteinte est celle du **premier préfixe corrigé tel que C_i = S**.
Puisque les contributions conservées sont positives, il s'agit de la dernière
contribution encore admissible ; son ordre Hub départage une date identique.
Une contribution totalement annulée ou en litige ne conserve donc aucun avantage.
Une réduction partielle conserve uniquement le net restant à sa date d'origine.
La résolution n'invente pas une nouvelle consommation.

Ne pas rechercher la première fois où le score avait été atteint dans l'historique
**non corrigé** : cela préserverait l'ancienneté d'une contribution annulée.
La date de notification, de remboursement, de pack, ou du navigateur est exclue.

Le fait Hub actuel conserve `confirmed_at` et un UUID de ledger mais **aucun ordre
autoritatif de consommation**. Un UUID trié, un auto-incrément local Fans ou l'ordre
de réception réseau ne le remplacent pas. La projection de rang reste indisponible
tant que cette autorité et un corpus complet ne sont pas attestés.
L'ancien F1a continue de calculer ses points privés sans devenir un classement.

### Exemples à traduire en tests B1/B4

Les heures et ordres ci-dessous sont fictifs et propriétaires dans les fixtures ;
ils ne constituent pas une extension Hub déjà opérationnelle.

| Cas | Contributions encore admissibles | Résultat attendu |
| --- | --- | --- |
| Égalité simple | A : 10 à 09:00 ; B : 10 à 09:05 | A puis B, places uniques 1 et 2 |
| Remboursement partiel | A : 10 à 09:00 + 3 nets à 10:00 (5 originaux) ; B : 13 à 09:30 | B puis A ; A atteint 13 à 10:00, sans garder les 2 annulés |
| Annulation totale | A : 10 à 09:00, seconde contribution entièrement annulée ; B : 10 à 09:30 | A puis B ; la seconde contribution n'intervient plus |
| Litige puis résolution | Seconde contribution de A suspendue à zéro, puis résolue à 3 nets | Pendant litige, seule la première date ; après résolution, date de la seconde pour le nouveau score ; jamais 5 restaurés |
| Corrections désordonnées | Révision 3 annule une contribution, puis arrivent révision 2 et ancien reçu | La révision 3 reste l'autorité ; score et date ne sont pas restaurés |
| Horodatage identique | A et B : 12 à 09:00 ; ordres Hub respectifs 42 et 41 | B puis A ; ordre réseau et UUID de transport ignorés |
| Même fait dans deux familles | Fan et Créateur tirent leur score de la même attribution | Même confirmation/ordre, une consommation ; dernier départage par identité immuable dans chaque tableau |
| Snapshot incomplet / ordre absent | Manque une page, une filiation ou l'ordre Hub | Indisponible, aucune place calculée par approximation |

## P4 — visibilité approuvée

Fan privé par défaut ; classement public avec pseudonyme approuvé et consentement
révocable. Le tableau classe les seuls participants visibles ; le retrait masque
la ligne et recalcule les places publiques sans changer les faits. Aucun rang
caché ne révèle des comptes masqués. UUID, e-mail, achat et montant invisibles.

Créateur : profil actif, présentation approuvée et accord explicite de classement
distinct du partenariat commercial. Invités exclus avant leur contrat
identité/attribution/reprise. Service d'alias et consentement Fan à construire.
La conservation réelle demeure #150, sans défaut de 24 mois ni purge ajoutée.

## P5/P6 — annulation, suspension et corrections approuvées

- Annulation de session : fermer les nouvelles contributions, conserver
  l'historique, afficher l'état annulé et **aucun vainqueur**. Ni annulation
  économique ni suppression des scores persistants associés.
- Suspension temporaire : fermer et masquer les surfaces appropriées sans
  réécriture économique. Corrections Hub toujours appliquées ; réadmission
  reconstruite depuis les faits corrigés et permissions/consentements valides.
- Litige attesté Hub : net non comptabilisable jusqu'à résolution plus récente ;
  seule la part non annulée revient.
- Correction attestée : réviser le relevé du mois d'origine, les persistants
  général/catégories et toutes les sessions associées, **même clôturées**.
  Historique versionné, résultats et dates d'atteinte recalculés.
- Aucune récompense irréversible, titre ou PC induit. Aucun ancien reçu ne
  restaure des points annulés ; aucun report dans un mois ou une session future.

## Lots autorisés et portes distinctes

| Ordre | Lot | Dépendance / preuve |
| --- | --- | --- |
| B1 | Dimensions, calendriers, origine enregistrée, catégories, consentements/pseudonymes ; calcul pur de départage | Règles ci-dessus ; tests été/hiver, bornes, net corrigé, permissions et versions |
| B2 | Gestion privée des sessions solo/collectives, invitations/admissions, trois portées, dates et modération | B1 ; concurrence, limites, droits, gel et admission territoriale |
| B3 | Contexte et ordre Hub attestés, rapprochement complet | B1/B2 et **accord Hub distinct avant implémentation** ; consommation unique, concurrence/reprise, compatibilité des anciennes preuves |
| B4 | Relevés et classements persistants Créateurs/Fans reconstruisibles | B1/B3, faits H4 complets ; corrections et corpus exhaustif |
| B5 | Projections de sessions dans tous les modes et portées | B2/B3 ; rattachement multiple, annulation, suspension et corrections après clôture |
| B6 | Lectures autorisées et UI des trois objets dans la DA V2 | B4/B5 ; source à jour, visibilité, clavier/mobile, recette ; activation opérateur distincte |

B2 et les préparatifs indépendants de B4/B6 avancent pendant l'attente d'un accord
Hub ; leur livraison ne doit pas être présentée comme un classement opérationnel.
La séquence conserve toutes les capacités cible. Chaque lot passe par PR bornée,
revue, contrôles et protections ; publication seulement par les workflows établis.

Dépendances ouvertes : contrat Hub B3 ; producteur d'achat/preuve réelle ;
admission réseau et vrai SSO ; corpus admissible exhaustif/fraîcheur et remise
durable des corrections ; référentiel territorial et pays autorisés ;
conservation #150, décisions PC/commerciales et recette opérateur avant activation.
RustFS #161 est distinct. La validation produit ne ratifie aucun de ces contrats.

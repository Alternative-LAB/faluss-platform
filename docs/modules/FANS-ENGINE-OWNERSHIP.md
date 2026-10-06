# Fans et moteurs communs — frontières de développement

## Statut au 28 septembre 2026

Contrat documentaire cible `fans.economy-contract/3.0.0`, défini par
[l’ADR 0018](../adr/0018-fans-pf-pc-hof-v3.md). Base :
`d2d8cf158458097125ce667583e341f64c75931a`. Les règles acquises remplacent les
anciennes décisions économiques de #71 ; les propositions restent non acceptées.
Aucun moteur économique, changement Token Engine, migration ou activation.

**État PF actualisé le 6 octobre 2026 :** la phrase précédente décrit le lot
documentaire initial. H1–H4 puis [F1a](FANS-PF-F1A-CLOSED.md) sont implémentés et
testés en recette fermée WordPress/MariaDB, preuves fictives. Aucun producteur
réel ni opération économique/score public sur les sites. Les lignes hors PF de
cette matrice conservent leur contexte historique ; [F1b](FANS-PF-F1B-PROPOSAL.md)
reste non validé et #150 ouverte.

## Matrice des propriétaires

| Moteur | Propriétaire des données et décisions | Consommateurs | Contrat public | État actuel |
| --- | --- | --- | --- | --- |
| Identity / SSO | Me : identité, preuve, codes ; chaque client : compte WP et session locale | Hub, Fans ; Pro à préparer | OAuth confidentiel, PKCE S256, UUID opaque | Me existant ; client Fans #67 fusionné, distinct des adaptateurs Hub |
| Créateurs | Fans : profil, admission, suspension | Fans ; projections autorisées Me | `CreatorProfileService`, REST Fans v1 | #68 fusionnée ; `active` = publication approuvée, `identity_verified=false` ; vérification vendeur absente |
| Relations sociales | Fans : follow et futures décisions de blocage/signalement | Fans | `FollowersService`, REST Fans v1 | #69 fusionnée, locale ; blocage, signalement, suppression et rétention à construire |
| Publications et fichiers | Fans : contenu, modération, sélection teaser, accès au fichier | Fans ; Me reçoit une projection bornée | `fans.creator-teasers` v1 proposé | Textes, quarantaine, associations, dérivé contrôlé Fans et panel fusionnés (#74–#79), flags fermés ; transport teaser absent |
| Messagerie / bibliothèque | Fans : conversations, pièces jointes, collection de références et droits locaux | Fans | Contrats à implémenter, aucun accès par simple SSO | Absents |
| Catalogue / commandes | Fans : fiches et futurs contrats de commandes/droits ; vendeur contractuel à décider | Fans | Catalogue REST v1 ; futurs contrats commerce distincts | #70 fusionnée : deux catégories, achats fermés ; commandes et reversements absents |
| PC / progression membre | Propriétaire et barèmes PC à décider | Fans ; cosmétiques futurs | Contrat v3 documentaire ; pas de source PF | Gain, consommation et moteur PC fermés |
| HoF | Fans : faits attestés et projections de classement ; Hub : consommation PF | Fans | Contrat v3 ; ratio validé, sessions à décider | F1a : points privés persistants reconstruisibles depuis H4 ; aucun classement/session public actif |
| PF | Hub / Token Engine : ledger, classe économique et compensation | Fans via un contrat serveur à étendre ; Me/Hub actuels | Contrat PF existant, jamais accès direct aux tables | Implémenté sur Hub ; aucun débit cosmétique ou soutien Fans |
| Cosmétiques | Catalogue commun proposé ; propriétaire PC et droits à décider ; dérivé : rendu | Fans en premier, Me compatible | Futur `faluss.cosmetics` v1 distinct de Catalog thèmes | Catalog actuel garde `faluss_catalog_card_themes` et `faluss-link` ; nouveau catalogue absent |
| Faluss Max | Hub / Subscriptions : abonnement et entitlement ; application : fonction exposée | Fans, Pro ; autres dérivés ensuite | Projection de fonctionnalités versionnée à ajouter | Abonnements existants ; offre Max et droits dérivés non implémentés |
| Prestations Pro | Pro : offre, disponibilités, règles de réservation, publication | Pro ; carte Me en lecture | `pro.published-services` v1 proposé | Rôle et module Pro non implémentés |
| Composition / transport | Hub compose les capacités ; Apps Registry valide ; Federation transporte ; Events livre | Me/Hub existants, Fans/Pro à admettre explicitement | Contrats CAP, Federation et EVT existants | Me/Hub seulement ; aucune extension globale des rôles dans ce document |

Les futurs modules doivent consommer les contrats de cette matrice, jamais les
tables ou classes internes d'un autre propriétaire. La liaison SSO ne remplace
ni la relation métier, ni une autorisation d'accès à un contenu.

## Publications et projections Me

Fans garde les fichiers privés hors URL publique WordPress et toute décision
d'accès. Avant conservation en quarantaine, le moteur vérifie le membre lié, le profil
autorisé, le nonce, les quotas et le type/taille réels. Sur multipart WordPress,
proxy et PHP peuvent recevoir/tamponner le corps avant ces contrôles ; les limites
en amont et temporaires privés relèvent de l’hébergement. Voir [FANS-IMAGES.md](FANS-IMAGES.md).
Stockage en quarantaine,
analyse et modération précèdent toute publication. Une simple déclaration
« autorisé » du navigateur ne constitue pas une validation. Aucun contenu adulte
dans un original, aperçu, teaser, message ou pièce jointe. Un contenu inconnu,
en cours d'examen, refusé, retiré ou appartenant à un créateur suspendu reste fermé.

Un créateur choisit explicitement jusqu'à cinq teasers. La projection Me proposée
contient seulement la version, l'identifiant public du créateur, l'identifiant du
teaser, un libellé borné, une URL d'aperçu public approuvé, un lien canonique Fans,
la date de décision, la révision et l'expiration. Aucun original verrouillé, e-mail,
Faluss ID ou détail de vente. Fans est l'émetteur ; Me ne réhéberge rien. Un retrait
invalide la projection et l'aperçu ; une réponse absente/périmée masque le teaser.
Le transport signé, la révocation, le cache et leurs tests restent à implémenter.

## PF, PC, progression et HoF — cible v3 fermée

L’[ADR 0018](../adr/0018-fans-pf-pc-hof-v3.md) est la référence normative.
Le pack PF ne crée aucun score. Seule l’attribution attestée de PF achetés,
effectivement consommés, est éligible. Un fait canonique peut alimenter plusieurs
classements sans second débit PF ; chaque projection est idempotente par
classement/session/dimension. Les corrections concernent toutes ces projections.

Achat, attribution et remboursement PF ne créditent jamais de PC, même par badge,
seuil ou événement dérivé. Les PC gagnés servent aux cosmétiques ; propriétaire,
sources et barèmes non décidés : gain et consommation fermés. Intentions produit : daily reward et ramassage
quotidien sur le profil d’un créateur attribueraient des PC au fan, sans
propriétaire technique, barème, plafond ou date d’activation fixés. Les claims
Hub/Me restent des PF historiques, sans renommage ni migration de solde. Les PF `earned` et
`promotional` historiques restent inchangés, ne deviennent ni PC ni PF éligibles
au nouveau HoF. Aucun financement ajouté ne contourne cette exclusion.

**1 PF acheté, attesté et effectivement attribué = 1 point HoF est une décision produit validée.** Aucune équivalence
EUR dans le contrat de score. Le soutien EUR direct du simulateur historique
n’est pas une source du nouveau HoF. Aucun score actif avant validation des politiques de classement, du protocole PF
et des autres portes. Un éventuel badge de soutien reste distinct des PC, sans montant ni
récompense PC dérivée ; son calcul reste fermé tant que ses critères manquent.
Pseudo/badge : relation créateur vérifiée, visibilité publique sur consentement.

Aucun wallet ou montant financier dans l’interface ou l’API destinée au créateur,
même privée. Le score public ne représente pas un revenu ; il peut toutefois
permettre une estimation économique indirecte. Ne promettre aucune impossibilité
d’inférence, notamment si des prix de packs sont connus.

### Token Engine préservé et dépendances bloquantes

La [façade actuelle](../../src/TokenEngine/TokenEngineContract.php) ne fournit que
statut et claim quotidien Hub. Le [service PF](../../src/TokenEngine/Legacy/includes/class-token-engine-points-service.php)
refuse pack, bonus, support Fans et débit cosmétique avec `pf_feature_not_enabled`.
Le ledger PF et ses classes appartiennent au Hub ; aucun accès Fans aux tables
internes ni second ledger PF. Le futur contrat devra attester provenance achetée,
consommation unique, identités canoniques, référence, politique et révision.
Aucune donnée navigateur ne constitue une preuve ; auto-attribution refusée.

La compensation standard actuelle reprend le montant entier et n’autorise qu’une
compensation par écriture. Elle ne fournit pas les compensations partielles
successives ; H4 les implémente désormais par une primitive propriétaire distincte
dans la recette fermée, sans modifier cette compensation historique. Le parcours
réel reste fermé, sans producteur d'achat ni politique de rétention validés.
Les anciens claims Me/Hub restent inchangés ; leur devenir n’est pas décidé.

### Corrections, remboursements et décisions ouvertes

Faits originaux conservés, corrections référencées et révisions authentifiées :
rejeu identique sans effet, révision conflictuelle refusée. Remboursement avant
attribution : aucun score à corriger. Après attribution : retrouver les allocations
et corriger toutes les projections une fois, sans double traitement pack/cadeau.
Le score ne fixe aucun montant EUR remboursé et sa correction ne rembourse pas
le fan automatiquement. Litige : contribution identifiée non comptabilisable ;
restauration seulement par révision authentifiée plus récente, nette des corrections.

Remboursements partiels, insuffisance de solde, sessions et suspensions, critères
du badge, titres après correction : décisions requises, parcours correspondants
fermés. Une correction de session clôturée doit réviser le classement d’origine
avec trace, sans déplacer la contribution dans une session courante. Les règles
complètes et portes figurent dans l’ADR. Aucun crédit PC lié à ces opérations.

### Simulateur historique et v3 distincte

Le [simulateur v2](PROGRESSION-SIMULATION.md) et ses tests restent inchangés :
ils décrivent des fixtures historiques, dont soutien EUR direct et dépense EUR.
Ils ne valident pas le contrat v3 et ne doivent pas être raccordés au runtime.
La [v3 hors runtime](FANS-HOF-SIMULATION-V3.md) ajoute des fixtures distinctes pour
pack zéro, PF sans PC aux trois étapes et via dérivés,
multi-classements sans double consommation, refus `earned`/`promotional`, auto-attribution entre UUID fictifs et corrections/rejeux. Identité réelle, API
créateur sans finances et Shop sans récompense implicite restent à vérifier dans
leurs futurs moteurs. Le lot documentaire initial n’adaptait aucun code ; cette
v3 ne modifie pas le simulateur v2 et n’ouvre aucun moteur.

## Cosmétiques et Max

Les nouveaux cosmétiques consommeront des PC via leur propriétaire à décider ;
le débit PF cosmétique historique fermé n’est pas une implémentation PC.
Aucun changement du Catalog de thèmes ou de Subscriptions.

Un futur catalogue `Cosmetics` séparé est préférable à l'élargissement implicite du
Catalog de thèmes Me : identifiants stables, types fermés, version et état approuvé.
Il décrit les éléments sans octroyer de droit. Un octroi acquis durablement demeure
dans l'autorité des droits après expiration de Max ou retrait de la surface Fans.
Un prêt lié à l'abonnement expire explicitement ; le retrait d'un article interdit
une nouvelle acquisition sans effacer l'historique. Le rendu peut être suspendu pour
modération ; ce n'est pas une suppression de l'octroi. Tests de compensation et
de retrait de surface requis avant branchement.

Un entitlement Max doit nommer application, fonctionnalité, version, sujet privé,
autorité Hub, révision, dates de validité et révocation. Fans/Pro vérifient la
réponse serveur fraîche au moment de l'action et leur propre politique locale.
Indisponibilité ou expiration = fonction premium fermée. Le niveau technique
`pro` de Subscriptions ne désigne pas le produit Faluss Pro. Un flag charge du code,
il n'accorde ni abonnement ni fonctionnalité. Catalogue Max, limites, remboursements
et droits durables doivent être définis avant configuration commerciale.

## Pro et admission réseau

Le futur propriétaire Pro doit fournir un agrégat offre : identifiant opaque,
propriétaire local, définition bornée, état brouillon/publié/suspendu, révision,
disponibilité et règles de réservation. Me ne doit recevoir que les offres publiées
d'un compte explicitement lié/configuré ; sans preuve de parcours de paiement,
la projection porte `purchasable=false`. La réservation reste sur Pro.

L'admission réseau se fera par lots explicites : `fans-node` / `faluss-fans` /
`https://fans.faluss.me`, puis identité Pro dont l'origine sera configurée et validée
dans son lot. Aucun pair n'est créé par un flag. Chaque opération exige contrat et
version exacts, clé du pair, politique locale et producteur/consumer autorisé.
Tests obligatoires : mauvaise identité/origine, mauvais contrat, refus directionnel,
rejeu, révocation, indisponibilité, absence de clé/Sodium et conservation des seuls
adaptateurs Me/Hub existants. Ne pas ajouter `fans` à une liste de rôles sans ces
politiques et sans adapter les vérifications d'identité fermées des moteurs.

## Commerce fermé et décisions avant comptabilité

`external_adult_delivery_right` est désormais archivée hors du catalogue public.
Le [Store v2](FANS-STORE.md) conserve les fiches pour consultation administrative
privée, sans conversion ni suppression. Création et republication refusées ; achat
403, y compris ancienne référence dont la catégorie aurait été changée.
`hosted_allowed_content` répond 503 tant que le commerce reste fermé. Les futurs
panier, commande, paiement, webhook et délivrance nécessiteront leurs propres gardes.

Les commandes autorisées futures exigent prix figé, devise et centimes entiers,
clé d'idempotence métier, preuve du prestataire, transitions transactionnelles,
réconciliation et compensation des droits/remboursements. Un retour navigateur
ne confirme jamais un paiement. Une référence webhook rejouée n'octroie rien de plus.

Le Shop cible couvre contenus, prestations, services et produits autorisés, sans
score ni PC implicitement ajoutés. Vendeur contractuel, responsabilités, traitement
financier hors surfaces créateur et remboursements restent à décider : vente,
paiement et délivrance commerciale fermés. Aucun taux de commission n’est acquis
par ce contrat. Validation prestataire et vérification vendeur distincte nécessaires
avant toute vente autorisée ; elles n’ouvrent pas le parcours adulte externe.

L'archivage effectif est implémenté dans le lot Store v2 distinct du contrat v3.
Il ne ratifie ni protocole économique ni activation. Voir [Store](FANS-STORE.md).

## Séquence de lots et portes de validation

1. Présent lot : ADR v3 et alignement documentaire uniquement.
2. Simulateur v3 distinct sur fixtures, sans runtime ; ratio validé ; politiques de classement, protocole PF et autres portes restent fermés.
3. Contrats propriétaires : PF H1–H4 disponibles en recette fermée ; PC et ouverture réelle toujours à valider.
4. Stockage/concurrence H1–H4 et points privés F1a testés sur instances jetables ; aucun second ledger PF.
5. Shop autorisé après vendeur contractuel et autres portes ; social complet et messages séparés.
6. UI, admission réseau et activation seulement après autorisation distincte.

Références relues : modules [Identity](IDENTITY.md), [client Fans](FANS-SSO.md),
[Catalog](CATALOG.md), [Token Engine](TOKEN-ENGINE.md), [Subscriptions](SUBSCRIPTIONS.md),
[Apps Registry](APPS-REGISTRY.md), [Federation](FEDERATION.md), [Events](EVENTS.md) ;
historique `Alternative-LAB/faluss`, `docs/POINTS_FALUSS_CONTRACT.md` (PF-02A) et
`docs/ECONOMY_PROTOCOL.md` (EC-01). Les formulations historiques « futur PF » ne
remplacent pas l'état implémenté de Token Engine dans Platform.

## Contrat Fans ↔ Hub pour PF achetés — portes de production

Le [draft versionné 0.1.0](FANS-HUB-PURCHASED-PF-CONTRACT.md) définit les exigences
proposées de preuve par lot, réservation/confirmation, reçus et réconciliation.
Le propriétaire ALB-Origine a validé les périmètres fermés H1–H4/F1a ; la production
et F1b ne le sont pas. La façade normale reste limitée aux claims quotidiens.
Les anciens tests sur faux serveur ne prouvaient pas SQL/reprise ; les recettes
fermées ajoutées les vérifient séparément, avec HTTP local et corrections partielles.
Elles ne prouvent ni vrai SSO, ni paiement, ni cible réelle. Claims et ledger
historique restent préservés ; aucune autorité PF parallèle Fans.

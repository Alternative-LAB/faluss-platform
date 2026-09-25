# Fans et moteurs communs — frontières de développement

## Statut au 25 septembre 2026

Ce contrat organise les lots après les brouillons #54–#57. **Il ne constitue pas
une implémentation des moteurs manquants, une admission réseau ou une ouverture
commerciale.** `main` de référence : `7672807698744d84b6f33fa47af904527711d8cf`.
Les écrans conceptuels ne prouvent aucun droit, paiement ou badge de vérification.

## Matrice des propriétaires

| Moteur | Propriétaire des données et décisions | Consommateurs | Contrat public | État actuel |
| --- | --- | --- | --- | --- |
| Identity / SSO | Me : identité, preuve, codes ; chaque client : compte WP et session locale | Hub, Fans ; Pro à préparer | OAuth confidentiel, PKCE S256, UUID opaque | Me existant ; client Fans #54 distinct des adaptateurs Hub |
| Créateurs | Fans : profil, admission, suspension | Fans ; projections autorisées Me | `CreatorProfileService`, REST Fans v1 | #55 ; `active` = publication approuvée, `identity_verified=false` ; vérification vendeur absente |
| Relations sociales | Fans : follow et futures décisions de blocage/signalement | Fans | `FollowersService`, REST Fans v1 | #56 local ; blocage, signalement, suppression et rétention à construire |
| Publications et fichiers | Fans : contenu, modération, sélection teaser, accès au fichier | Fans ; Me reçoit une projection bornée | `fans.creator-teasers` v1 proposé | Moteur et transport non implémentés |
| Messagerie / bibliothèque | Fans : conversations, pièces jointes, collection de références et droits locaux | Fans | Contrats à implémenter, aucun accès par simple SSO | Absents |
| Catalogue / commandes | Fans : fiches, commandes, preuves, droits, remboursements, revenus EUR | Fans | Catalogue REST v1 ; futurs contrats commerce distincts | #57 : deux catégories, achats fermés ; commandes et reversements absents |
| Progression membre | Moteur commun Progression : faits acceptés, corrections, niveaux | Fans en premier ; autres dérivés ensuite | `faluss.member-progression` v1 proposé | Contrat ; pas de score actif ni de stockage |
| HoF | Fans : sessions, dimensions et classements, contribution membre distincte du score créateur | Fans | `fans.hof` v1 proposé | Contrat ; aucun paiement producteur |
| PF | Hub / Token Engine : ledger, classe économique et compensation | Fans via un contrat serveur à étendre ; Me/Hub actuels | Contrat PF existant, jamais accès direct aux tables | Implémenté sur Hub ; aucun débit cosmétique ou soutien Fans |
| Cosmétiques | Catalogue commun : définitions ; Hub : octrois et ledger ; dérivé : rendu | Fans en premier, Me compatible | Futur `faluss.cosmetics` v1 distinct de Catalog thèmes | Catalog actuel garde `faluss_catalog_card_themes` et `faluss-link` ; nouveau catalogue absent |
| Faluss Max | Hub / Subscriptions : abonnement et entitlement ; application : fonction exposée | Fans, Pro ; autres dérivés ensuite | Projection de fonctionnalités versionnée à ajouter | Abonnements existants ; offre Max et droits dérivés non implémentés |
| Prestations Pro | Pro : offre, disponibilités, règles de réservation, publication | Pro ; carte Me en lecture | `pro.published-services` v1 proposé | Rôle et module Pro non implémentés |
| Composition / transport | Hub compose les capacités ; Apps Registry valide ; Federation transporte ; Events livre | Me/Hub existants, Fans/Pro à admettre explicitement | Contrats CAP, Federation et EVT existants | Me/Hub seulement ; aucune extension globale des rôles dans ce document |

Les futurs modules doivent consommer les contrats de cette matrice, jamais les
tables ou classes internes d'un autre propriétaire. La liaison SSO ne remplace
ni la relation métier, ni une autorisation d'accès à un contenu.

## Publications et projections Me

Fans garde les fichiers privés hors URL publique WordPress et toute décision
d'accès. Avant de recevoir un octet, un futur upload doit vérifier le membre lié,
le profil autorisé, le nonce, les quotas et le type/taille ; stockage en quarantaine,
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

## Progression et HoF : politique proposée, scores inactifs

Les faits admis devront provenir du moteur propriétaire, via un producteur fermé
Events. Un champ JSON `verified=true` ne prouve rien. Chaque fait porte une référence
opaque stable, une révision, la version de politique, sa date UTC et l'autorité.
La clé métier associe propriétaire et référence ; même clé/révision avec contenu
différent = conflit, même contenu = rejeu sans effet. Les compensations ciblent
l'original et sont rejouables sans double correction.

Pour les simulations uniquement : un centime EUR de soutien admissible et confirmé
donne une unité de contribution membre et une unité de score créateur. Base proposée :
montant hors taxes affecté au soutien, hors frais et pourboires non affectés. Aucun
score pour un paiement échoué, en attente, contesté ou annulé, un achat de PF, un
débit cosmétique ou des PF `earned`/`promotional`. Remboursement partiel : retrancher
les centimes remboursés, sans solde négatif. Un litige suspend toute contribution
de la transaction jusqu'à résolution officielle ; une restitution exige une nouvelle
révision vérifiée. Aucun ordre d'arrivée réseau ne peut réactiver une vieille révision.

La progression cumulée est recalculable à partir des faits nets, sans être remise
à zéro à chaque session HoF. Les seuils de niveaux et badges doivent être approuvés
avant activation ; aucun seuil commercial arbitraire n'est fixé ici. Les sessions
HoF ont des bornes UTC `[début, fin[`, une portée locale/nationale/internationale et
un domaine ou le général. L'attribution de territoire doit être explicite et
non dérivée d'une IP. Un fait contribue au plus une fois à une session/dimension.
Une correction met à jour les sessions concernées, y compris clôturées, avec trace.

La visibilité du membre est privée par défaut. Un pseudonyme public requiert son
opt-in par surface ; retirer cet opt-in masque la projection sans modifier le fait
comptable. Ne pas exposer un Faluss ID, un montant individuel ou une relation avec
la catégorie adulte externe dans le classement public. Les règles de classement,
les seuils, l'assiette et la procédure de contestation restent des décisions produit
à confirmer avant tout score actif.

## PF, cosmétiques et Max

Les cinq grandeurs restent distinctes : PF par classe, progression, badge, HoF,
revenu EUR. Le contrat historique PF autorise `cosmetic_redemption` sur les trois
classes sans revenu EUR, HoF ou progression. Le ledger Hub doit conserver l'allocation
par classe et compenser chaque débit dans sa classe originale. Aucun nouveau ledger
Fans, aucune conversion ALB/PF et aucun droit issu d'une valeur navigateur.

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

`external_adult_delivery_right` reste classable et visible comme catégorie ; toute
fiche nominative est masquée faute de consentement. #57 ne possède aucun mécanisme
de consentement : il masque toutes ces fiches côté serveur, même si la base dit
`visible`. L'achat actuel répond 403. `hosted_allowed_content` répond 503 tant que
le commerce est fermé. Les futurs panier, commande, paiement, webhook, attribution
de droit et délivrance devront chacun refuser la catégorie adulte et vérifier
leur propre garde. Ils ne sont pas réputés protégés par la seule route #57.

Les commandes autorisées futures exigent prix figé, devise et centimes entiers,
clé d'idempotence métier, preuve du prestataire, transitions transactionnelles,
réconciliation et compensation des droits/remboursements. Un retour navigateur
ne confirme jamais un paiement. Une référence webhook rejouée n'octroie rien de plus.

Commission envisagée : **10 %**, sans calcul comptable implémenté. Avant ce calcul,
confirmer l'assiette HT/TTC, inclusion des remises, frais prestataire séparés ou
absorbés, arrondi et allocation des remboursements partiels, frais de litige,
échéance/réserve de reversement et solde insuffisant. Le même prix ne doit jamais
servir implicitement de revenu créateur ou d'assiette HoF. Fournisseur acceptant
explicitement le modèle de plateforme et vérification distincte des vendeurs
obligatoires avant vente hébergée ; accord séparé pour l'adulte externe et lot
spécifique avant une éventuelle ouverture de ce parcours.

## Séquence de lots et portes de validation

1. #54–#57 : consolidation, preuves HTTP locales et correction des blocages CI.
2. Politiques de publications/teasers : tests de refus, limite cinq, retrait,
   autorisation du créateur ; puis stockage privé, quarantaine, upload et recette WP.
3. Progression/HoF : contrats et simulateur sur faits fictifs ; puis producteur
   Events authentifié, stockage/idempotence et concurrence MariaDB avant scores.
4. Pro : admission de rôle isolée, module d'offres et tests ; projections signées
   Me/Fans/Pro après admission réseau explicite.
5. Cosmétiques et Max : catalogue/entitlements publics étroits sur autorités Hub,
   révocation et continuité des octrois, sans paiement implicite.
6. Social complet, messagerie/bibliothèque et commerce : moteurs, modération,
   suppression/rétention, recettes d'accès aux fichiers, puis validations prestataire.

Références relues : modules [Identity](IDENTITY.md), [client Fans](FANS-SSO.md),
[Catalog](CATALOG.md), [Token Engine](TOKEN-ENGINE.md), [Subscriptions](SUBSCRIPTIONS.md),
[Apps Registry](APPS-REGISTRY.md), [Federation](FEDERATION.md), [Events](EVENTS.md) ;
historique `Alternative-LAB/faluss`, `docs/POINTS_FALUSS_CONTRACT.md` (PF-02A) et
`docs/ECONOMY_PROTOCOL.md` (EC-01). Les formulations historiques « futur PF » ne
remplacent pas l'état implémenté de Token Engine dans Platform.

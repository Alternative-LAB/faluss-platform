# Fans et moteurs communs — frontières de développement

## Statut au 25 septembre 2026

Ce contrat documentaire de la PR #71 organise les lots après les PR #67–#70 fusionnées. **Il ne constitue pas
une implémentation des moteurs manquants, une admission réseau ou une ouverture
commerciale.** `main` de référence : `962a0368415f45e09c9ac15479c40f33421fcf0c`.
Les écrans conceptuels ne prouvent aucun droit, paiement ou badge de vérification.

## Matrice des propriétaires

| Moteur | Propriétaire des données et décisions | Consommateurs | Contrat public | État actuel |
| --- | --- | --- | --- | --- |
| Identity / SSO | Me : identité, preuve, codes ; chaque client : compte WP et session locale | Hub, Fans ; Pro à préparer | OAuth confidentiel, PKCE S256, UUID opaque | Me existant ; client Fans #67 fusionné, distinct des adaptateurs Hub |
| Créateurs | Fans : profil, admission, suspension | Fans ; projections autorisées Me | `CreatorProfileService`, REST Fans v1 | #68 fusionnée ; `active` = publication approuvée, `identity_verified=false` ; vérification vendeur absente |
| Relations sociales | Fans : follow et futures décisions de blocage/signalement | Fans | `FollowersService`, REST Fans v1 | #69 fusionnée, locale ; blocage, signalement, suppression et rétention à construire |
| Publications et fichiers | Fans : contenu, modération, sélection teaser, accès au fichier | Fans ; Me reçoit une projection bornée | `fans.creator-teasers` v1 proposé | Moteur et transport non implémentés |
| Messagerie / bibliothèque | Fans : conversations, pièces jointes, collection de références et droits locaux | Fans | Contrats à implémenter, aucun accès par simple SSO | Absents |
| Catalogue / commandes | Fans : fiches, commandes, preuves, droits, remboursements, revenus EUR | Fans | Catalogue REST v1 ; futurs contrats commerce distincts | #70 fusionnée : deux catégories, achats fermés ; commandes et reversements absents |
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

## Progression et HoF : décision produit, moteurs inactifs

Cette décision remplace la proposition « un centime = une unité » ; elle ne
modifie aucun code de calcul ni paiement dans #71. Trois projections sont séparées :

| Grandeur | Source et règle | Visibilité |
| --- | --- | --- |
| Score du créateur HoF | Achat d'un pack : **0 point**. Cadeau de **300 pièces financées effectivement dépensées = 300 points de session**. Soutien direct de **1 EUR confirmé = 1 point**. | Classement de session ; ce score ne représente jamais le revenu du créateur. |
| Progression du donateur | Dépense réelle confirmée consommée, nette des corrections ; jamais au simple achat du pack, ni selon les pièces nominales, le score créateur ou les bonus gratuits. | Pseudo et badge visibles au créateur concerné ; visibilité publique sur consentement. Seuils et conversion en niveaux à décider. |
| Revenu du créateur | Montants EUR issus des preuves économiques, avec brut, commission, frais, remboursements, réserves et net à reverser distingués. Aucun taux revenu/point implicite. | Statistiques privées du créateur et accès administratifs explicitement autorisés ; aucun montant individuel dans le classement public. |

Un pack acheté ne donne aucun score HoF et ne constitue pas un revenu créateur.
La progression du badge intervient **lors de la consommation financée**, jamais
au simple achat du pack. La même dépense ne doit jamais être comptée aux deux
étapes. Pour les pièces consommées en soutien, le Hub devra
attester le coût EUR réellement payé et alloué aux pièces dépensées, avec remises
et lots d'origine ; aucun taux nominal PF/EUR ne remplace cette preuve.
Un cadeau de 300 pièces ne signifie donc ni 300 EUR de dépense ni 300 EUR de revenu.
Une preuve de financement seule ne prouve pas une dépense personnelle du donateur.

Pour le soutien direct, le calcul utilise des **centièmes de point entiers** :
1 centime EUR confirmé = 1 centième de point, donc 20 EUR = 2 000 centièmes
= 20 points et 1,25 EUR = 125 centièmes = 1,25 point. Aucune troncature à l'euro
ni calcul flottant ; les corrections utilisent la même unité entière. Pour une
projection commune, 300 points de cadeau sont représentés par 30 000 centièmes,
sans convertir les pièces en euros. Le soutien direct confirmé constitue une
consommation immédiate de la dépense réelle pour la progression. L'assiette des
euros confirmés (taxes, remises et frais) reste à préciser sans remplacer le taux
produit de 1 point/EUR par l'ancien taux de 1 point/centime.

Les faits admis devront provenir du moteur propriétaire, via un producteur fermé
Events. Un champ JSON `verified=true` ne prouve rien. Chaque fait porte une référence
opaque stable, une révision, la version de politique, sa date UTC et l'autorité.
La clé métier associe propriétaire et référence ; même clé/révision avec contenu
différent = conflit, même contenu = rejeu sans effet. Les compensations ciblent
l'original et sont rejouables sans double correction.

Aucun score pour un paiement échoué, en attente, contesté ou annulé, un achat de
pack, un débit cosmétique ou des PF `earned`/`promotional` sans financement explicite.
Le fait de cadeau devra porter séparément la quantité financée réellement débitée,
la référence du débit Hub, la preuve de financement et le coût EUR alloué ; le fait
de soutien direct porte la preuve de confirmation du prestataire. Les statistiques
de revenu dépendent de la comptabilité commerce, pas de la projection HoF.

### Corrections, litiges et remboursements à implémenter

Un remboursement partiel corrige séparément les pièces du cadeau annulées, les
euros du soutien direct annulés, la dépense réelle retenue pour la progression et
les montants privés du revenu. Exemple de quantité : annuler 100 des 300 pièces
financées laisse 200 points de session ; cela ne fixe aucun montant EUR remboursé.
Un soutien direct de 20 EUR dont 5 EUR sont remboursés laisse 15 points. La
réconciliation ne soustrait jamais des centimes directement d'un nombre de pièces.
Un remboursement complet annule les effets de l'opération sans double correction.

Un litige suspend les contributions concernées jusqu'à résolution officielle et
place le revenu contesté en réserve selon une politique à définir ; il ne crée pas
automatiquement une restitution de PF dépensables. Un remboursement du pack après
dépense doit retrouver tous les cadeaux financés par ce lot et corriger leurs effets,
sans rembourser deux fois pack et cadeau. Règles de réserves, solde insuffisant,
financement partagé et remboursements partiels à contractualiser avec le Hub.
Les écritures économiques restent immuables, compensées par leur propriétaire ;
les projections sont recalculables. Une restitution après litige exige une nouvelle
révision authentifiée, jamais un vieux message reçu en retard. Conserver raison,
référence d'origine, révision, politique et traces d'accès pour toute correction.

La progression cumulée est recalculable à partir des faits nets, sans être remise
à zéro à chaque session HoF. Les seuils de niveaux et badges doivent être approuvés
avant activation ; aucun seuil commercial arbitraire n'est fixé ici. Les sessions
HoF ont des bornes UTC `[début, fin[`, une portée locale/nationale/internationale et
un domaine ou le général. L'attribution de territoire doit être explicite et
non dérivée d'une IP. Un fait contribue au plus une fois à une session/dimension.
Une correction met à jour les sessions concernées, y compris clôturées, avec trace.

Le **pseudo et le badge du donateur sont visibles pour le créateur concerné**
par le soutien, via une projection bornée avec contrôle serveur de cette relation.
Cela n'ouvre ni le ledger, ni l'historique des autres soutiens, ni la dépense globale,
ni l'identité SSO aux créateurs. Hors de cette relation, les données restent privées.
La visibilité publique du pseudo et du badge exige un consentement explicite par
surface ; retirer ce consentement masque la projection publique sans modifier le fait
comptable. Ne pas exposer un Faluss ID, un montant individuel ou une relation avec
la catégorie adulte externe dans le classement public. Les règles de classement,
les seuils, l'assiette précise et la procédure de contestation restent des décisions produit
à confirmer avant tout score actif.

## PF, cosmétiques et Max

Les cinq grandeurs restent distinctes : PF par classe, progression, badge, HoF,
revenu EUR. La possibilité contractuelle historique de `cosmetic_redemption` sur
les trois classes n'est pas une capacité active : le code actuel la refuse.
Un futur débit cosmétique ne produit ni revenu EUR, ni HoF, ni progression.
Le ledger Hub doit conserver l'allocation
par classe et compenser chaque débit dans sa classe originale. Aucun nouveau ledger
Fans, aucune conversion ALB/PF et aucun droit issu d'une valeur navigateur.

### Réutilisation des PF `funded` : étude, pas activation

Sources relues : [Token Engine](TOKEN-ENGINE.md),
[`TokenEngineContract`](../../src/TokenEngine/TokenEngineContract.php),
[`Token_Engine_Points_Service`](../../src/TokenEngine/Legacy/includes/class-token-engine-points-service.php)
et [tests de modèle du ledger](../../tests/TokenEngine/TokenEngineLedgerModelTest.php).
Le Hub possède déjà le ledger PF append-only, les classes `funded`, `earned`,
`promotional`, l'idempotence et des compensations liées de même classe. Cependant
`normalise_entry()` refuse actuellement `pf_pack_purchase`, `pf_pack_bonus`,
`fans_support`, `cosmetic_redemption` et `manual_adjustment` avec
`pf_feature_not_enabled`. La façade Platform ne propose que le statut et le gain
journalier Hub ; elle n'expose pas de débit Fans ni de preuve de financement.
La compensation actuelle reprend le montant entier et est unique par écriture ; un protocole de corrections
partielles répétées ne doit pas être présumé disponible.

Direction retenue pour étude : utiliser les PF `funded` comme support économique
des pièces Fans, sous réserve de valider leur correspondance et leur allocation
aux lots financés. Ne pas ouvrir un second wallet ou ledger de pièces dans Fans.
Fans conserve seulement ses références métier et projections ; le Hub reste
autorité du solde, de la classe, du débit et de sa compensation. Aucun accès Fans
aux tables ou classes internes : une façade serveur Hub versionnée et authentifiée
devra fournir un reçu de débit effectivement commis, quantité par classe,
provenance du financement, coût EUR alloué, révision et référence d'idempotence.
La coordination Hub/commerce/Events devra traiter succès partiel, retry, timeout,
réconciliation et compensation avant tout affichage définitif.

Les PF `earned` et `promotional` ne produisent **aucun revenu ni score monétisable
sans financement explicite**. Les cadeaux gratuits n'en produisent pas non plus,
sauf financement explicite par **Faluss**, prouvé et alloué à l'opération. Les PF
`earned` et `promotional` **ne sont pas reclassés**, même dans ce cas : la preuve
du financement Faluss est distincte de leur classe d'origine, conservée dans le
ledger Hub et ses compensations. Ce financement n'est ni une conversion gratuite
en PF `funded`, ni une dépense personnelle du donateur ; il ne fait pas progresser
son badge. Les effets éventuels sur le score et le revenu exigent un reçu économique
et une politique dédiée, sans réutiliser un euro déjà alloué à un autre cadeau.
Un bonus de pack ne devient pas financé parce que
le pack est payé ; une métadonnée ou un flag ne vaut pas preuve. Un financement
promotionnel éventuel exige une source économique, une allocation et une politique
revues ; aucune reclassification des PF `earned` ou `promotional` n'est admise.
Par défaut, un mélange de classes non admissibles ne contribue pas au score ;
l'éligibilité, le refus ou la ventilation du cadeau doivent être décidés et testés.
Un financement par la plateforme ne compte pas comme dépense réelle du donateur.

### Porte obligatoire pour la PR #73 et tests futurs

**Le simulateur de #73 doit être adapté avant sa fusion.** Son état actuel
`fans.support-simulation/1.0.0` convertit les centimes nets en deux totaux identiques
(`member_contributions`, `creator_scores`) pour `eur_support` et `funded_support`.
Cette règle ne satisfait pas la décision HoF. #71 ne modifie pas la branche #73.
Il faudra versionner sa politique, séparer pièces dépensées, euros confirmés,
dépense réelle et revenu privé, puis ajouter des tests pour :

- pack payé sans score ; 300 pièces financées débitées = 300 points de session ;
- 20 EUR confirmés = 2 000 centièmes de point ; 1,25 EUR = 125 centièmes,
  remboursement de 0,25 EUR = retrait de 25 centièmes, sans assimilation au revenu net ;
- progression sur coût réel à la consommation financée, zéro au simple achat du
  pack et absence de double comptage ; zéro progression pour un cadeau financé par Faluss ;
- bonus, PF gagnés/promos et financement absent ou forgé sans score monétisable ;
- cadeau gratuit sans revenu ni score monétisable ; exception Faluss avec preuve
  allouée, sans reclassification PF ni double utilisation du financement ;
- cadeau annulé partiellement, remboursement de pack déjà consommé, litige et
  résolution, rejeu, ordre inversé, conflit de révision et sessions clôturées ;
- pseudo/badge accessibles au seul créateur concerné ; refus pour un autre créateur,
  consentement et retrait pour l'affichage public, sans exposition du ledger ;
- projections publiques sans revenu privé, confidentialité des donateurs,
  refus adulte inchangé et absence de tout débit/paiement réel dans la simulation.

Avant implémentation restent ouverts : correspondance pièce/PF `funded`, preuve et
allocation des lots EUR, traitement des classes mixtes et preuve de financement Faluss,
seuils et règles de sessions, arrondis d'allocation économique, assiette du soutien, commission,
réserves et protocole de corrections partielles du Hub. Les exemples de score
ci-dessus sont décidés ; ces paramètres d'exécution ne sont pas encore approuvés.

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
fiche nominative est masquée faute de consentement. #70 ne possède aucun mécanisme
de consentement : il masque toutes ces fiches côté serveur, même si la base dit
`visible`. L'achat actuel répond 403. `hosted_allowed_content` répond 503 tant que
le commerce est fermé. Les futurs panier, commande, paiement, webhook, attribution
de droit et délivrance devront chacun refuser la catégorie adulte et vérifier
leur propre garde. Ils ne sont pas réputés protégés par la seule route #70.

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

1. #67–#70 fusionnées : fondations isolées ; aucune activation commerciale.
2. Politiques de publications/teasers : tests de refus, limite cinq, retrait,
   autorisation du créateur ; puis stockage privé, quarantaine, upload et recette WP.
3. Progression/HoF : adapter impérativement #73 à cette décision avant sa fusion,
   avec tests sur faits fictifs ; puis producteur
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

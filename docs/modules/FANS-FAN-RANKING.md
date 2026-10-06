# Classement Fans — contrat de préparation v0.1

Statut public : écran prêt, **service de classement absent**. Le lot
[F1a fermé](FANS-PF-F1A-CLOSED.md) apporte des projections de points persistantes
et reconstruisibles dans la seule recette jetable. Aucune route REST de classement,
réception d'événement en production, cron, badge gagné ou tableau public ajouté.
La route privée `/faluss-fans/fan/classement-fans` est distincte des classements
HoF `/faluss-fans/fan/classements`. Elle requiert une liaison SSO Fans ; un
administrateur sans liaison ne contourne pas cette règle. Un Créateur lié peut
ouvrir son espace Fan, sans neuvième accès dans la navigation Créateur.

## Règles acquises et séparation des projections

Le classement mesure exclusivement le **nombre net de PF effectivement attribués
par le Fan à des créateurs**, attestés par l’autorité propriétaire. Un achat de
pack sans attribution donne zéro point. Aucun euro, prix, conversion, solde PF
disponible, PC gagné ni badge PC ne participe au calcul ou à cet écran.

Le HoF des créateurs reste régi par l’[ADR 0018](../adr/0018-fans-pf-pc-hof-v3.md) :
**1 PF acheté, attesté et effectivement attribué = 1 point HoF**. Une attribution
admissible aux deux projections doit être consommée une seule fois. Le classement
Fan groupe par donateur, le HoF par créateur ; ne jamais additionner ces projections
ni déduire un solde ou un revenu de l’une d’elles. Les classes de provenance des PF
admises au classement Fan doivent être ratifiées avec Hub ; aucune ouverture
implicite aux PF historiques `earned` ou `promotional`.

## Projections fermées existantes et portes du classement public

Le [protocole Fans ↔ Hub](FANS-HUB-PURCHASED-PF-CONTRACT.md) de production reste
fermé. H1–H4 sont implémentés et testés sous accords limités : preuves fictives,
lots, consommations dans le ledger officiel, reçus privés, corrections cumulatives
et snapshots complets sur WordPress/MariaDB jetables. H4 utilise une primitive
propriétaire distincte, sans changer la compensation historique entière et unique.
F1a lit leur inbox privée rapprochée ; l'écran ne l'appelle pas. Aucun accès aux
tables Hub ou compteur navigateur, aucun producteur d'achat réel ou score public.

La projection fermée reconstruit la somme des quantités nettes admissibles par
identifiant canonique d’attribution, selon la dernière révision propriétaire
acceptée. Les quantités sont des entiers, jamais des proratas calculés depuis un
montant monétaire. Les originaux et corrections restent traçables.

| Scénario de calcul fermé / règle proposée | Résultat exigé |
| --- | --- |
| Pack de 300 PF acheté, rien attribué | Aucun point |
| Attribution attestée de 120 PF, même reçu rejoué | 120, une seule contribution |
| Même attribution, nouvelle clé de transport | Aucun double comptage |
| Même identifiant/révision mais contenu contradictoire | Conflit fermé, pas de score déclaré exact |
| Révision attestée réduisant le net de 120 à 90 | 90, même correction dans chaque projection admissible |
| Annulation totale attestée puis ancien reçu rejoué | Zéro, l’ancien reçu ne restaure rien |
| Remboursement de PF jamais attribués | Aucun changement de score |
| Snapshot incomplet, trou de révision ou autorité indisponible | Classement indisponible, aucun rang périmé présenté comme actuel |
| Correction après clôture | Révision de la période d’origine ; pas de report arbitraire |
| Gain de PC, achat Shop ou montant EUR | Aucun point ni affichage dans cette progression |

Les scénarios de net/doublon/correction/snapshot sont vérifiés par la
[recette F1a](../../tests/TokenEngine/recipe/README.md#f1a--projections-privées-reconstruisibles),
distincte des simulateurs. La correction après clôture et les politiques de rang
restent des propositions F1b ; aucune période/rang n'est implémenté dans F1a.

## Propositions à arbitrer avant toute publication

La [proposition complète F1b R2](FANS-PF-F1B-PROPOSAL.md), P1–P6, est **non validée**.
Elle distingue relevé mensuel privé, classements persistants général/par catégorie
et sessions HoF ouvertes par les Créateurs seuls ou à plusieurs, aux portées
locale, nationale ou internationale. Ces sessions ne sont pas réservées à
l'administration. Ce tableau résume les portes, sans les ratifier.

| Sujet | Proposition, non activée | Décision requise |
| --- | --- | --- |
| Calendrier | Relevé par mois civil UTC proposé ; classements persistants sans remise à zéro mensuelle ; sessions à dates choisies | Fuseau du relevé, origine du cumul, bornes de sessions et historique |
| Catégories et sessions | Contribution au général, catégories autorisées et plusieurs sessions admissibles, avec une seule consommation | Catégorie versionnée, choix explicite des sessions, contexte attesté et admissions territoriales |
| Égalités | Même rang pour même score net, sans avantage de date ou de dépense monétaire | Rang dense ou compétition ; tri neutre stable sans départage artificiel |
| Pseudonymes | Opt-in public révocable, alias approuvé ; score personnel privé par défaut | Modération, anonymisation, effet du retrait et rétention |
| Invités | Exclus du tableau public tant que session, attribution attestée et reprise de compte ne sont pas garanties | Identité provisoire, consentement et rattachement atomique sans doublon |
| Abus | Refus auto-attribution côté propriétaire, preuve d’origine, idempotence, limitations et revue des anomalies | Collusion, multi-comptes, suspension, recours et durée de conservation |
| Badges/récompenses | Aucun badge de niveau ni gain PC automatique | Critères, consentement et retrait après correction |

## Scénarios de l’interface et retour arrière

Positifs : Fan lié et Créateur lié dans son espace Fan ouvrent cette route ; six
accès Fan, huit Créateur ; une seule destination active. Bandeau crème, fond
sombre et accents verts des planches V2. Le moteur absent est annoncé sans rang,
score, montant ou fausse jauge ; clavier et mobile restent utilisables.

Négatifs : invité, compte non lié et administrateur non lié refusés (403) ; route
Créateur `classement-fans` inconnue (404) ; aucun formulaire ou endpoint de score,
aucune nouvelle permission économique. Les flags existants restent fermés.

Retour arrière : revert du lot UI/documentation, aucune migration ni donnée à
restaurer. Avant activation réelle, validation cible WordPress/thème/Elementor et
SSO par le propriétaire du site ; les captures de tests isolés ne la remplacent pas.

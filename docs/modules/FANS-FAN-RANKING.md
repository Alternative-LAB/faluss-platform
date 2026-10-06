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
sont approuvées dans F1b R2 ; aucune période/rang n'est implémenté dans F1a.

## Règles F1b R2 approuvées et dépendances distinctes

Le [contrat F1b R2](FANS-PF-F1B-PROPOSAL.md) a sa validation produit du 6 octobre 2026.
Elle distingue relevé mensuel privé, classements persistants général/par catégorie
et sessions HoF ouvertes par les Créateurs seuls ou à plusieurs, aux portées
locale, nationale ou internationale. Ces sessions ne sont pas réservées à
l'administration. La validation produit autorise B1–B6 selon leurs dépendances ;
elle ne ratifie aucun nouveau contrat Hub, achat réel ou activation.

| Sujet | Règle approuvée, non activée | Dépendance restante |
| --- | --- | --- |
| Calendrier | Relevé Europe/Paris été/hiver ; persistants sans remise à zéro mensuelle depuis l'ouverture réelle enregistrée ; sessions à dates choisies | Services de calendrier/origine ; aucune donnée fictive ou historique sans contexte |
| Catégories et sessions | Général/catégories et plusieurs sessions explicitement choisies, une seule consommation ; 90 jours / 3 ouvertes par organisateur / 10 par attribution | Contexte attesté B3, catégorie versionnée, admissions territoriales et pays autorisés |
| Départage | Places uniques : net décroissant, date d'atteinte reconstruite après corrections, ordre Hub puis identité immuable non affichée | Extension Hub B3 approuvée séparément pour recette fermée, à implémenter/tester ; aucun ordre réseau ou UUID trié comme substitut |
| Pseudonymes | Opt-in public révocable, alias approuvé ; score personnel privé par défaut | Service, modération, anonymisation et conservation #150 |
| Invités | Exclus du tableau public tant que session, attribution attestée et reprise de compte ne sont pas garanties | Identité provisoire, consentement et rattachement atomique sans doublon |
| Abus | Refus auto-attribution côté propriétaire, preuve d’origine, idempotence, limitations et revue des anomalies | Collusion, multi-comptes, suspension, recours et durée de conservation |
| Badges/récompenses | Aucun badge de niveau ni gain PC automatique | Critères, consentement et retrait après correction |

Annulation : fermer les nouvelles contributions, conserver l'historique et afficher
l'état annulé sans vainqueur ; aucun effet économique ou suppression des persistants.
Suspension : fermer/masquer, continuer les corrections Hub et reconstruire à la
réadmission. Résultats versionnés et révisables, même après clôture.

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

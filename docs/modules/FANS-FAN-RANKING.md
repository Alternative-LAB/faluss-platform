# Classement Fans — contrat de préparation v0.1

Statut : écran prêt, **service absent**. Aucun score persistant, route REST de
classement, réception d’événement, cron, badge gagné ou tableau public ajouté.
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

## Garanties attendues — pas un moteur existant

Le [protocole Fans ↔ Hub](FANS-HUB-PURCHASED-PF-CONTRACT.md) reste proposé et
non ratifié. Il manque les preuves, allocations, reçus canoniques, révisions,
corrections et snapshots complets nécessaires. La compensation historique entière
et unique du Token Engine ne fournit pas les remboursements partiels successifs.
L’écran ne doit donc ni appeler ce protocole fictivement, ni lire les tables Hub,
ni accepter des compteurs envoyés par le navigateur.

La future projection reconstruira la somme des quantités nettes admissibles par
identifiant canonique d’attribution, selon la dernière révision propriétaire
acceptée. Les quantités sont des entiers, jamais des proratas calculés depuis un
montant monétaire. Les originaux et corrections restent traçables.

| Scénario futur à vérifier | Résultat exigé |
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

Ces scénarios sont des exigences d’acceptation du futur moteur, **pas des tests
runtime réalisés par ce lot**. Le simulateur HoF existant ne prouve pas leur
implémentation pour un classement Fan.

## Propositions à arbitrer avant toute publication

| Sujet | Proposition, non activée | Décision requise |
| --- | --- | --- |
| Période | Mois civil UTC, corrections rattachées à la date d’attribution originale | Fuseau, borne, historique et éventuel classement global |
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

# F1b — proposition produit à valider, révision R2

**Statut : recommandations non validées. Aucun comportement F1b implémenté ou
activé par F1a.** Décideur : ALB-Origine. Révision du 6 octobre 2026 suivant sa
demande de préserver la cible produit. Elle remplace P1/P2 de R1 et précise P6.
[F1a](FANS-PF-F1A-CLOSED.md) est livré en recette fermée : ses points privés ne
définissent ni rang, ni calendrier produit, ni session.

## Cible demandée et objets distincts

| Objet | Fonction et calendrier | Gestion |
| --- | --- | --- |
| Relevé mensuel | Vue privée des attributions du mois et de leur net corrigé ; pas une compétition ou une remise à zéro des autres projections | Consultation par le membre autorisé |
| Classements persistants | HoF Créateurs général et par catégorie, distinct du classement Fans ; accumulation des contributions admissibles sans remise à zéro mensuelle | Calcul serveur reconstruisible et visibilité autorisée |
| Sessions HoF | Compétitions ou parcours bornés dans le temps, distincts du relevé et des classements persistants ; portée locale, nationale ou internationale | Créateurs seuls ou à plusieurs ; administration chargée de la modération et des recours |

L'ouverture et la gestion de sessions par les Créateurs font partie de la cible.
Les sessions ne sont pas réservées à l'administration. Un découpage en lots ne
supprime ni la création collective, ni une portée, ni les classements persistants.
Les recommandations ci-dessous précisent les règles encore à valider ; cette
correction documentaire n'autorise pas F1b ou une ouverture économique.

Le relevé Créateur présente seulement les points et informations de contribution
autorisées, sans wallet, montant, solde PF, reçu privé d'achat ou détail financier
des Fans. Il ne constitue pas un ledger PF parallèle.

## P1 — calendrier du relevé et classements persistants

### Recommandation

- Relevé : mois civil **UTC**, début inclus et fin exclue, selon la date de
  consommation confirmée par Hub. Une correction révise le net de l'attribution
  dans son mois d'origine ; elle ne devient pas une seconde attribution.
- Classements persistants : cumul depuis une origine d'admission explicite et
  versionnée, sans expiration au changement de mois. Général et catégories sont
  des projections distinctes des mêmes contributions ; jamais des soldes PF.
  Recommander comme origine l'instant futur de début des attributions économiques
  admises, validé par l'opérateur, et la date de confirmation Hub pour l'inclusion.
  Aucune donnée fictive F1a n'entre dans ce cumul réel.
- Maintenir séparées les familles Créateurs et Fans. Pour la famille Fans,
  recommander également général et catégories des contributions aux Créateurs ;
  l'étendue de cette déclinaison par catégorie reste un choix à valider.
- Fixer la catégorie approuvée de la contribution lors de son attribution.
  Une édition ultérieure du profil ne déplace pas automatiquement son historique.
  L'ensemble général reste indépendant des catégories et des sessions.
- Afficher seulement les participants admissibles au net positif dans les
  tableaux publics ; aucun rang inventé pour un compte absent ou à zéro.

**Conséquences :** un nouveau mois ouvre un relevé, sans effacer un rang ou le
cumul des classements persistants. Une correction tardive peut changer à la fois
le relevé original, le classement persistant et les sessions concernées. UTC
donne une frontière commune, décalée de minuit à Paris selon la saison. Un relevé
Europe/Paris est une alternative, avec calendrier et changements d'heure propres.
Une vue mensuelle comparative éventuelle serait un objet supplémentaire ; elle
ne remplacerait pas les classements persistants.

Les instants techniques restent UTC. La présentation indique son fuseau ; les
horaires du back-office utilisent le fuseau WordPress. Cette présentation ne
change ni les bornes du relevé ni celles des sessions.

**À valider :** calendrier UTC ou Europe/Paris du relevé, origine du cumul
admissible, catégorie figée par contribution et déclinaison catégorielle Fans.
Les PF historiques non admissibles restent exclus. Un historique sans contexte
de catégorie ne reçoit pas la catégorie actuelle par supposition ; aucune
reclassification rétroactive avant une décision et une preuve versionnée.

## P2 — sessions ouvertes par les Créateurs

### Création solo et collective

Recommandation : un Créateur SSO lié, actif et doté d'une présentation approuvée
peut créer, programmer et ouvrir une session depuis Fans. Il peut être seul ou
inviter des coorganisateurs ; chacun accepte explicitement avant d'obtenir des
droits. Organisateurs et participants sont deux rôles distincts : une création
solo n'impose pas un seul participant, et une création collective n'invente pas
un compte collectif ou un solde commun.

Chaque participant Créateur accepte les règles et son admission. Dans une
session collective, garder des scores individuels par Créateur ; un classement
d'équipes serait une règle supplémentaire, pas une somme implicite. Un
organisateur peut participer, avec cette qualité visible et sans pouvoir éditer
les scores. Une session à un seul participant reste possible, sans fausse
concurrence, titre ou récompense automatique.

Le propriétaire de session gère invitations, programmation et demandes de
participation ; les coorganisateurs obtiennent des droits explicites. La
recommandation précise est : initiateur responsable de la programmation,
annulation et nomination des coorganisateurs ; coorganisateurs autorisés à
inviter et traiter les admissions, sans cession de propriété ou édition de score.
Chaque action revalide son habilitation. La modération éditoriale, suspension
et procédure de recours restent obligatoires
selon leurs contrats. L'administrateur peut intervenir pour ces fonctions ; il
ne devient pas le seul acteur autorisé à ouvrir les sessions. Admission de
profil et participation ne valident aucun partenariat commercial.

### Portées et participation

| Portée cible | Recommandation de périmètre | Condition à construire |
| --- | --- | --- |
| Locale | Territoire d'activité nommé et stable, rattaché à un pays ; participants Créateurs admis pour ce territoire | Référentiel territorial, déclaration du Créateur et décision d'un modérateur Fans habilité |
| Nationale | Pays d'activité explicite ; participants Créateurs admis pour ce pays | Même admission territoriale et critères publics |
| Internationale | Aucune restriction de pays pour les participants | Identité liée, profil actif, présentation approuvée, règles acceptées |

Recommander que la portée encadre les participants Créateurs, sans limiter le
soutien aux Fans du même territoire. Un Fan lié peut attribuer selon ses droits,
quel que soit son pays ; auto-attribution refusée. Une restriction territoriale
des Fans serait un choix différent avec ses propres preuves et conséquences.
Ni IP, ni langue, ni identité Me ne prouvent un domicile. Le profil public actuel
ne fournit pas de preuve territoriale suffisante : locale et nationale attendent
ce service dans leur lot, sans être retirées de la cible ni présentées comme
vérifiées avant son implémentation.

Recommander le territoire d'activité principal déclaré, examiné selon des
critères publics par la modération Fans, plutôt qu'une adresse de résidence.
Cette admission éditoriale n'est pas une certification de domicile ou d'identité.
Le choix activité/résidence et le niveau de justification restent à valider.

Recommander une entrée volontaire des participants, acceptée par les
organisateurs selon des critères annoncés, avec motif et recours en cas de refus.
Une admission après ouverture ne vaut que pour les attributions futures. Le
retrait ou la suspension ferme les nouvelles participations ; l'effet sur les
surfaces et résultats antérieurs relève de P5/P6, sans annulation PF locale.

### Dates et règles stables

Recommander des dates choisies par les Créateurs, **début inclus / fin exclue**,
indépendantes du mois civil. Le fuseau IANA choisi à la programmation est conservé
pour expliquer le calendrier ; le serveur enregistre et compare les instants UTC.
Une heure locale inexistante est refusée ; une heure ambiguë exige un choix
explicite d'occurrence. La date de consommation confirmée Hub détermine
l'appartenance, jamais celle de réception réseau.

Au lancement proposé : durée maximale de **90 jours** et **trois sessions
simultanément ouvertes par organisateur**, coorganisation comprise. Ce sont des
plafonds à valider pour limiter les abus, pas des règles actives ni une suppression
des sessions collectives. Une autre limite modifie disponibilité et charge.
Recommander le gel des dates, portée, catégorie et règles après ouverture ; de
nouveaux participants peuvent être admis prospectivement. Une modification
structurelle nécessite un nouveau brouillon, sans transfert silencieux des faits.

### Rattachement d'une attribution aux projections autorisées

Une attribution admissible peut alimenter le général, les catégories autorisées,
la projection Fan et **plusieurs sessions admissibles**, avec **une seule
consommation officielle**. Ne pas additionner ces dimensions pour créer un total
économique. La même attribution ne compte qu'une fois dans chaque dimension.
La contribution est unique par `attribution_id` et dimension ; une révision
remplace son net, elle n'ajoute pas une seconde contribution.

Recommander un choix explicite des sessions par le Fan, parmi celles auxquelles
le Créateur participe ; aucun ajout automatique à toutes ses sessions. Proposer
au plus **dix sessions par attribution**, plafond de traitement à valider qui
préserve le rattachement multiple. Une limite d'une session serait un choix produit
différent, pas une conséquence implicite du premier lot.

Recommander des sessions cumulables par défaut, avec cette règle annoncée avant
ouverture. Une éventuelle exclusivité entre sessions demande une décision
explicite et un contrôle serveur du jeu de sessions sélectionné.

Fans valide côté serveur un ensemble canonique de dimensions : identifiant,
révision de politique, catégorie, session et admission du participant. Ce contexte
doit être lié de manière attestée au fait propriétaire de consommation avant
qu'une projection puisse l'utiliser. Les identifiants choisis dans le navigateur
ou la catégorie actuelle du profil ne suffisent pas. Chaque session doit être
ouverte et chaque admission valide à la confirmation ; contexte expiré ou choix
devenu inadmissible = refus avant consommation, sans supprimer silencieusement
une session choisie. Les courses fermeture/confirmation doivent échouer fermées.

Après réponse incertaine, reprendre l'intention et la clé originales ; changer
de sessions ne contourne pas le lookup primaire. Aucun rattachement rétroactif
à une session créée après l'attribution. Une correction Hub actualise toutes
les projections associées, sans nouvelle consommation ou compensation Fans.

**Conséquences :** création réellement déléguée aux Créateurs, soutien compréhensible
et corrections communes. Le choix multiple peut donner les mêmes points dans
plusieurs compétitions distinctes ; cela doit être annoncé aux participants.
L'admission territoriale et le contexte attesté demandent des capacités nouvelles.
Les coûts, récompenses, PC ou avantages commerciaux ne sont pas induits.

**À valider :** droits des coorganisateurs, admission et participation des
organisateurs, preuve territoriale, accès des Fans indépendamment du territoire,
gel des règles, calendrier et plafonds proposés, sélection multiple explicite
des sessions, cumul/exclusivité et contrat de rattachement attesté.

## P3 — égalités

Recommandation : rang de compétition (1, 2, 2, 4) ; même net admissible = même
rang. Aucun avantage de date d'achat, de vitesse d'attribution ou de montant
monétaire. Pour afficher les ex æquo : nom public approuvé puis identifiant
opaque comme dernier ordre technique stable, sans départage du rang.

Conséquences : aucun encouragement à dépenser plus tôt ; les rangs suivants
peuvent sauter. Le rang dense (1, 2, 2, 3) serait plus compact mais changerait le
sens des places. Aucun titre ni récompense en cas d'égalité avant une politique
distincte. **À valider : rang de compétition et ordre visuel neutre.**

## P4 — visibilité des Fans et Créateurs

Recommandation : points personnels privés par défaut ; apparition du Fan dans
le tableau public sur consentement explicite, révocable, avec pseudonyme public
approuvé. Le tableau porte sur les participants visibles, pas sur tous les
comptes ; aucun rang global privé ne doit révéler implicitement les membres
masqués. Le retrait masque la ligne, puis recalcule les rangs publics sans
changer les faits privés. UUID, e-mail, achat et montant restent invisibles.

Créateurs : profil actif, présentation approuvée et consentement explicite à
l'affichage du classement ; l'admission commerciale reste distincte. Invités
exclus tant que leur identité, attribution et reprise n'ont pas leur contrat.

Conséquences : choix réel de visibilité et périmètre public compréhensible ; le
rang public peut changer sans variation des points. Une participation publique
obligatoire ou un rang incluant les comptes cachés serait une autre politique.
**À valider : consentement, périmètre visible et règles d'historique/anonymisation.**
La durée de conservation demeure la décision #150, sans défaut proposé ici.
Le service de pseudonyme public approuvé et consentement Fan n'existe pas encore ;
aucune identité locale WordPress ou alias technique ne peut le remplacer.

## P5 — suspensions

Recommandation : suspension locale d'un profil/compte = retrait des surfaces et
classements publics, sans réécriture des attributions Hub. Les points privés
restent des faits, accessibles seulement selon les permissions encore valides.
Une suspicion de fraude permet de masquer provisoirement une ligne ; elle ne
permet pas à Fans d'inventer une annulation économique.
Suspendre une session ferme ses nouvelles participations sans effacer le général
ou les autres sessions.

Litige propriétaire Hub : contribution suspendue, net comptabilisable zéro
jusqu'à résolution plus récente ; seule la part non annulée revient. Reprise
d'un profil local : recalcul à partir des faits corrigés et du consentement
encore valide, jamais restauration d'un ancien rang mémorisé.

Conséquences : modération et économie restent séparées ; suspendre un Créateur
ne supprime pas arbitrairement les points de ses Fans. Une sanction de classement
au-delà du masquage nécessiterait une décision, un motif et un recours propres.
**À valider : portée des suspensions, motifs, recours et réadmission.**

## P6 — clôture et corrections tardives

Recommandation : le relevé mensuel peut avoir des révisions datées ; les
classements persistants restent cumulatifs et corrigibles, sans clôture mensuelle.
La fin d'une session ferme ses nouvelles attributions, pas les corrections.
Une correction attestée révise chaque projection concernée : relevé d'origine,
persistant général/catégorie, sessions sélectionnées même clôturées. Ne pas
déplacer la correction dans une autre session ou un nouveau mois pour éviter de
corriger l'original. Les anciens reçus ne rétablissent jamais un net annulé.

Pas de délai arbitraire « définitif » ou de récompense irréversible au lancement :
les délais de contestation/remboursement du futur achat ne sont pas définis.
Titres, PC, cosmétiques et autres récompenses demandent leurs propres décisions.
Une trace privée de la cause conserve les droits d'accès ; elle n'expose pas
d'identité, reçu d'achat ou détail de litige dans le tableau public.

Conséquences : historique fidèle mais résultats de sessions révisables ; un gel
irréversible rendrait les remboursements et litiges incompatibles avec le score
exact. **À valider : historique révisable, présentation des corrections et sort
des résultats de session après annulation ou suspension.**

## Capacités actuelles et ordre des lots proposé

F1a calcule seulement Fan/Créateur sur `reconciled_members`, pas un inventaire
global ou une fraîcheur continue. Les DTO H3/H4 actuels et F1a n'attestent ni
catégorie, ni session, ni territoire, ni admission à une session. Une simple
association SQL ou un champ navigateur ne comble pas cette absence. Les contrats
historiques, le ledger officiel et les tests existants doivent rester intacts.

Après **validation explicite de F1b et autorisation de chaque périmètre** :

| Ordre | Lot borné recommandé | Dépendance et preuve attendue |
| --- | --- | --- |
| B1 | Registre des dimensions et calendriers, origine du cumul, catégories versionnées, consentements et pseudonymes | Règles validées ; visibilité privée/publique, droits et révisions testés en isolation |
| B2 | Gestion privée des sessions par les Créateurs : solo/collectif, invitations, admissions, trois portées, dates et modération | B1 ; construire la preuve territoriale locale/nationale, pas les déclarer disponibles sur simple libellé ; tests concurrence et permissions |
| B3 | Extension additive et versionnée du contexte attesté d'attribution et de son rapprochement complet | B1/B2 + accord Hub propre ; contexte et bornes vérifiés à la consommation unique, reprise à clé stable, anciennes preuves préservées |
| B4 | Relevé mensuel et classements persistants général/par catégorie, familles Créateurs/Fans séparées | B1/B3 + faits H4 rapprochés ; reconstruire, corriger sans remise à zéro mensuelle, vérifier le corpus admissible complet |
| B5 | Projections de sessions solo/collectives et locales/nationales/internationales, sans nouvelle consommation | B2/B3 ; rattachement multiple, clôture concurrente, corrections tardives et suspensions testés |
| B6 | Lectures autorisées et UI des trois objets, puis recette avant toute ouverture publique | B4/B5 ; consentements, modération, source à jour, clavier/mobile et parcours SSO ; activation reste une décision opérateur séparée |

B2 peut avancer en parallèle des préparatifs de B4 après B1 ; le calcul
catégoriel de B4 attend B3. Le découpage reporte une implémentation, pas une
capacité cible. Aucun lot n'autorise à lui seul achat réel, score public,
récompense, flag ou déploiement. RustFS reste un sujet séparé dans #161.

## Scénarios à traduire en tests après validation

- Changement de mois : nouveau relevé, points/rangs persistants non remis à zéro.
- Même attribution vers général/catégorie/Fan et plusieurs sessions : un débit,
  une contribution par dimension ; rejeu sans doublon et correction commune.
- Création solo puis collective ; refus d'une invitation, permissions retirées,
  profil suspendu ; aucune création ou admission par un tiers non habilité.
- Trois portées ; déclaration territoriale falsifiée ou non vérifiée, Fan
  d'un autre pays ; refus conforme à la règle validée, sans inférence d'IP.
- Heure ambiguë/inexistante, début/fin exacts, confirmation concurrente avec
  clôture ou retrait ; résultat incertain repris sans nouveau débit.
- Catégorie éditée après attribution ; pas de réaffectation silencieuse.
- Correction partielle/totale, litige, ancien reçu, snapshot incomplet et
  session clôturée ; aucune réapparition de points annulés ou rang non attesté.

## Portes avant F1b/publication

1. Validation explicite P1–P6 R2 et du périmètre des lots ; aucune recommandation
   ne devient une règle active par sa publication dans le dépôt ou #147.
2. Services de dimensions/admission, rattachement attesté, consentements et
   modération ; pas de réservation commerciale ou paiement implicite.
3. Avant exploitation réelle : propriétaire d'achat/preuve, admission réseau,
   vrai SSO, inventaire admissible complet et fraîcheur/livraison durable des
   corrections ; politique #150 sans défaut de 24 mois.
4. Permissions, recours et validation cible avant flags/activation par l'opérateur.
   Les tests isolés de F1a ne remplissent pas ces portes.

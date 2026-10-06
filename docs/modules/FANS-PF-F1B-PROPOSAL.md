# F1b — proposition produit à valider

**Statut : recommandations non validées. Aucun comportement F1b implémenté ou
activé par F1a.** Décideur : ALB-Origine. Les points mathématiques privés de
[F1a](FANS-PF-F1A-CLOSED.md) ne définissent ni rang, ni période, ni titre.

## P1 — périodes des classements

Recommandation : deux tableaux publics distincts, Créateurs et Fans, sur mois
civil **UTC**, début inclus et fin exclue. Une attribution relève de sa date de
consommation confirmée par Hub ; les corrections restent rattachées à cette
date. L'historique mensuel est versionné. Ne pas lancer simultanément un
classement glissant ou global ; le total privé cumulatif n'est pas un troisième
classement public.

N'afficher que les participants admissibles au net strictement positif pour cette
période ; zéro n'octroie aucune place. Les absents du mois n'ont pas un rang zéro.

Conséquences : frontière commune et reconstruction déterministe ; l'heure de
clôture diffère de minuit à Paris selon la saison. Un fuseau Europe/Paris serait
possible mais impose le calendrier et ses changements d'heure. Le glissant
rendrait les rangs moins comparables et entraînerait des sorties quotidiennes.
**À valider : UTC et mois civil, ainsi que l'absence initiale d'autre période.**

## P2 — sessions HoF et relevé mensuel

Recommandation : une session HoF est un objet éditorial distinct, avec identifiant,
thème/catégorie, dates UTC et participants admis. Elle ne se crée pas
automatiquement à partir d'un relevé mensuel. Le relevé demeure une vue privée
des attributions/corrections, sans gagnant ou récompense.

Lors d'une future attribution, le contexte de session doit être explicitement
choisi et confirmé par son propriétaire. Au lancement : au plus une session
thématique par attribution, en plus des projections générales/catégorielles
autorisées ; aucun second débit. Une attribution hors session peut compter
dans le mois général sans être affectée rétroactivement à une session.

Conséquences : intention lisible, absence de double dépense et pas de placement
arbitraire après coup. Autoriser plusieurs sessions thématiques demanderait une
politique d'admission et un contexte canonique plus complexe. **À valider :
participation, dimensions et limite d'une session.** Aucun tel contexte n'est
présent dans H4/F1a ; son contrat futur devra être ajouté explicitement.
La catégorie d'une contribution serait celle admise et versionnée au moment
de l'attribution, sans déplacement automatique d'historique après édition du
profil ; ce choix fait aussi partie de la validation P2.

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

Litige propriétaire Hub : contribution suspendue, net comptabilisable zéro
jusqu'à résolution plus récente ; seule la part non annulée revient. Reprise
d'un profil local : recalcul à partir des faits corrigés et du consentement
encore valide, jamais restauration d'un ancien rang mémorisé.

Conséquences : modération et économie restent séparées ; suspendre un Créateur
ne supprime pas arbitrairement les points de ses Fans. Une sanction de classement
au-delà du masquage nécessiterait une décision, un motif et un recours propres.
**À valider : portée des suspensions, motifs, recours et réadmission.**

## P6 — clôture et corrections tardives

Recommandation : la fin de période ferme les nouvelles attributions de cette
période, pas les corrections. Publier un état daté et versionné ; une correction
attestée produit une nouvelle révision du tableau d'origine, avec indication
discrète de révision. Ne pas retirer les points dans le mois suivant pour éviter
de corriger l'ancien. Les anciens reçus ne rétablissent jamais un net annulé.

Pas de délai arbitraire « définitif » ou de récompense irréversible au lancement :
les délais de contestation/remboursement du futur achat ne sont pas définis.
Titres, PC, cosmétiques et autres récompenses demandent leurs propres décisions.
Une trace privée de la cause conserve les droits d'accès ; elle n'expose pas
d'identité, reçu d'achat ou détail de litige dans le tableau public.

Conséquences : historique fidèle mais gagnants/rangs révisables ; un gel
irréversible rendrait les remboursements et litiges incompatibles avec le score
exact. **À valider : historique révisable et présentation des corrections.**

## Portes avant F1b/publication

1. Validation explicite P1–P6 et du périmètre F1b ; aucune recommandation n'est
   une règle active.
2. Contrat de contexte de session/admission, consentements et modération des
   pseudonymes ; pas de réservation ou paiement implicite.
3. Propriétaire d'achat réel et preuve serveur, admission réseau, reprise durable,
   traitement des corrections en exploitation et politique #150.
4. Permissions, recours et validation cible avant flags/activation par l'opérateur.
   Les tests isolés de F1a ne remplissent pas ces portes.

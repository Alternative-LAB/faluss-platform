# B5 — projections distinctes des sessions HoF

## B5a : calcul privé depuis le corpus exhaustif

F1b R2 est approuvé ; B5 poursuit B2/B3/B4 sans modifier un contrat Hub.
`RankingSessionProjection` valide le corpus complet puis sélectionne les
rattachements explicites attestés lors de la confirmation. Elle n'invente pas
un manifeste pour une sélection de faits ou pour les seuls membres locaux.

Chaque attribution contribue une fois par session choisie et une fois à chaque
famille Fan/Créateur. Dix choix ne multiplient pas les points du général ou des
catégories. Pack seul, PC et anciens PF restent exclus par la source B3. Aucun
débit, ledger parallèle, prix, portefeuille ou indicateur financier.

Les sessions sont groupées par identifiant immuable et règles figées : révision,
empreinte, dates, portée et territoire/politique. Deux contenus figés différents
pour la même session rendent le résultat indisponible. Une nouvelle version
de barrière ou d'admission après suspension ne scinde pas la session : les
contributions confirmées avant et après réadmission restent cumulées. Une
admission tardive ne compte que pour les confirmations futures, contrôlées
par le contexte Hub avant tout calcul. Aucune attribution rétroactive.

Les portées locale, nationale et internationale sont conservées ; aucune
restriction supplémentaire sur le territoire du Fan n'est ajoutée. Les scores
individuels ne deviennent pas des scores d'équipe ou de coorganisation.
La liste de résultats privés reprend l'ordre et l'ancienneté corrigée Hub de
`RankingFacts`, séparément pour Fans et Créateurs. Corrections partielles,
annulation totale, litiges et résolution recomposent ces deux familles à partir
du dernier net rapproché, y compris après l'échéance d'une session.

## Responsabilités encore à raccorder

Ce calcul pur ne vérifie pas de signature, ne possède pas de stockage et ne
constate pas l'ouverture ou la clôture. Son appelant doit utiliser la génération
courante authentifiée de l'inbox, jamais une ancienne réponse restituée seule.
B5b doit persister/reconstruire sous cette même transaction et refuser les
anciennes générations comme B4b. Ce premier sous-lot ne le revendique pas.

Les règles éditoriales et la catégorie propre à une session sont obtenues de
la gouvernance B2 avec égalité de l'empreinte figée ; elles ne sont pas déduites
de la catégorie d'un Créateur ni ajoutées au contrat Hub. Le calcul n'expose
ni titre, ni statut, ni gagnant. Les acquisitions du pack et dates réseau ne
contribuent jamais au départage.

La lecture autorisée B6 appliquera l'état courant, les consentements et la
visibilité. Une annulation conserve les contributions historiques et les
scores persistants, ferme les nouveaux choix et ne désigne aucun vainqueur.
Une suspension masque la session ou ses participants selon la gouvernance,
sans réécrire les contributions : les corrections continuent et la réadmission
reconstruit le net actuel. La clôture normale requiert l'acquittement primaire
[barrières 1.1](https://github.com/Alternative-LAB/faluss-platform/blob/a30bb95f5a475834b00afc4a1a2937dc2055e2e1/docs/modules/HUB-PF-B3-SESSION-COMPLETION.md), pas une date du navigateur.

## Vérification et limites

Scénarios positifs : une attribution/dix sessions, trois portées, réadmission,
égalité d'horodatages départagée par Hub, corrections après échéance,
litige/résolution et reconstruction déterministe. Négatifs : corpus tronqué,
doublon, net altéré, PC, admission future et contenus figés contradictoires.

Ces tests purs ne sont ni une recette réseau, ni la preuve du véritable SSO,
ni une ouverture sur site. Aucun bootstrap, hook, route, migration, cron,
flag ou score public n'est ajouté. Retour arrière : retirer ce calcul inerte.
Conservation #150, RustFS #161, producteur d'achat et publication sont distincts.

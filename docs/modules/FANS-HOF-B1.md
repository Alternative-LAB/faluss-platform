# B1 — fondations des règles HoF / classement Fans

## Autorisation et séparation des livraisons

Les règles [F1b R2](FANS-PF-F1B-PROPOSAL.md) sont validées le 6 octobre 2026.
B1 est scindé en code mathématique/calendriers (B1a), puis registre persistant
des dimensions, origine, consentements et pseudonymes (B1b). B2 suit pour les
sessions. Aucune capacité cible n'est supprimée par ce découpage.

Le [nouveau contrat Hub B3](HUB-PF-B3-RANKING-PROPOSAL.md) attend un accord
distinct. Ces classes ne modifient ni H3/H4, ni ledger, claims, PF/PC ou sessions
WordPress. Aucun bootstrap, REST, cron, flag, migration ou score public.

## B1a — calendriers et calcul pur

`RankingPolicy` conserve les règles/version, catégories existantes, trois portées
et plafonds 90 / 3 / 10. `RankingCalendar` fournit :

- relevé civil **Europe/Paris**, bornes UTC, été/hiver et année bissextile ;
- rattachement à la confirmation d'origine, intervalle début inclus / fin exclue ;
- durée de session au plus 90 × 24 heures entre instants UTC, sans assimiler
  la session au mois ; fuseau IANA de programmation explicite ;
- conversion de programmation locale refusant les heures inexistantes ;
  sélection d'occurrence par décalage UTC explicite pour une heure ambiguë ;
- précision à six décimales, aucun horodatage du navigateur ou réseau.

L'origine réelle, les décisions territoriales et les pays autorisés ne sont pas
inventés par une constante ou un réglage de recette.

### Calcul et format interne

`RankingFacts` est un **calculateur pur**, pas un validateur de signature ni un
nouveau format de transport Hub. Entrées : sources canoniques **complètes et déjà
authentifiées** par le futur adaptateur B3/B4, une révision entière par membre,
une époque propriétaire d'ordre et des attributions regroupées multi-lots.

Chaque attribution comprend identifiants immuables, original/net PF, confirmation
UTC et ordre Hub. Ce format interne ne ratifie ni ne fournit l'autorité de ces
champs : les preuves H3/H4 actuelles n'en fournissent pas tous et restent inchangées.
Aucun appel normal de l'application ne peut donc annoncer un classement avec B1a.

Reconstruction déterministe : dernière révision complète de chaque source,
identités immuables et unicités, exclusion du net nul, sommes distinctes par Fan
et Créateur. À même score : dernière contribution encore positive, confirmation
et ordre Hub, puis identité immuable. Places uniques. Revisions plus anciennes
ne restaurent ni points ni avantage d'ancienneté. Conflits, source qui perd une
attribution connue, ordre absent/dupliqué ou époque étrangère : refus fermé.

Les résultats internes contiennent les références nécessaires au rapprochement ;
ils ne sont pas une réponse publique. Consentement, alias approuvé, permission,
origine et corpus exhaustif/à jour sont des portes B1b/B3/B4/B6, pas inférés ici.
La catégorie/session impose son contexte attesté avant son calcul B4/B5.

## Scénarios écrits avant implémentation

Positifs : mars 2026 (743 h), octobre 2026 (745 h), année bissextile ; bornes
exactes et microsecondes ; occurrences automne explicites ; égalités/places uniques ;
remboursements partiels/totaux ; retrait de l'avantage d'une contribution annulée ;
litige/résolution au seul net restant ; sources désordonnées/rejouées ; même fait
pour les projections Fan/Créateur sans consommation additionnelle.

Négatifs : date invalide, heure inexistante/ambiguë sans occurrence, faux offset,
durée supérieure à 90 jours ou nulle ; ordre Hub absent/dupliqué, époque mixte,
faits/identités/revisions contradictoires, source incomplète, net négatif/excessif,
auto-attribution, PC/champs étrangers, pack seul/net zéro sans place.

Les tests mathématiques utilisent uniquement des sources fictives en mémoire.
Ils ne prouvent pas encore l'attestation d'ordre, la provenance ou le réseau Hub,
le véritable SSO ou un classement complet. Les tests historiques restent distincts.

## Retour arrière et exploitation

Classes inertes et sans données persistées en B1a : revert du lot sans migration.
B1b aura son installation explicite/testable et retour arrière propre. Aucun
appel ne réécrit les ledgers, claims ou anciennes projections F1a. Conservation
#150 et stockage RustFS #161 distincts. Aucune activation réelle par publication.

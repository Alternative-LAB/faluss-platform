# Progression / HoF — simulation hors runtime

## Implémenté

`Progression\SupportSimulation` calcule une **simulation** déterministe à partir
de faits fictifs complets. Elle n'est enregistrée dans aucun module, route, hook,
consumer Events ou cron. Aucun score durable, niveau, badge, paiement, revenu,
PF ou entitlement n'est écrit. Son résultat contient `simulation=true` et une
politique explicitement nommée `fans.support-simulation/1.0.0`.

Cette fondation teste l'idempotence et les corrections proposées avant un moteur
actif. Elle ne constitue pas une preuve de paiement et ne doit pas être appelée
directement par une API recevant des données utilisateur.

## Faits de simulation v1

L'ensemble des champs est fermé : `version`, `owner`, `reference`, `revision`,
`member`, `creator`, `category`, `source`, `amount_cents`, `refunded_cents`,
`status`, `occurred_at`. Seul `owner=faluss-fans` et `version=1.0.0` sont admis.
Références et sujets sont des UUID opaques fictifs ; montants EUR en centimes
entiers, positifs, plafonnés, remboursement cumulatif borné par le montant.

Les références sont distinctes : référence transaction, sujet membre privé et
identifiant créateur. Les valeurs `member` ne doivent jamais sortir vers un
classement public. Le champ `owner` seul n'authentifie aucune source ; une future
intégration devra vérifier le producteur fermé, la signature et l'autorité métier
avant tout effet. Ajouter `verified=true` est rejeté, pas accepté comme preuve.

Chaque référence possède des instantanés cumulatifs versionnés, et non des deltas.
Le dernier numéro de révision gagne indépendamment de l'ordre réseau ; un doublon
identique ne compte qu'une fois. Une même révision avec un contenu différent
rejette le lot. Membre, créateur, catégorie, origine, montant initial et date
d'origine sont immuables à travers les corrections. Aucun transfert implicite de
score ou réécriture du bénéficiaire n'est possible.

Pour la simulation seulement : `confirmed` avec source `eur_support` ou
`funded_support`, catégorie hébergée autorisée, produit `amount-refunded` unités.
La valeur EUR d'un soutien funded devra provenir de son propriétaire économique,
jamais d'un solde PF ou d'un taux inventé. `pf_purchase`, `earned_pf`,
`promotional_pf`, `cosmetic`, catégorie adulte et états `pending`, `failed`,
`refunded`, `disputed` produisent zéro. Le litige suspend toute contribution ;
une nouvelle révision officielle devra confirmer une restitution éventuelle.

Deux projections privées restent séparées : contribution par membre et score
par créateur. Même si leur total est identique dans cette politique de test, elles
ne sont ni un wallet, ni un revenu, ni un droit, ni un classement public.

## Tests et limites

Six tests / 20 assertions : rejeu, ordre inversé, remboursement partiel/complet,
litige puis résolution, sources PF/cosmétique exclues, paiement non confirmé,
catégorie adulte, conflit de révision, bénéficiaire modifié, faux claim de preuve,
remboursement supérieur au montant. PHPStan ciblé et lint sans erreur.

Les tests s'exécutent en mémoire. **Aucune concurrence SQL ni livraison Events
réelle n'est prouvée pour cette simulation.** Ils ne complètent pas les preuves
SSO/REST par une prétendue recette commerciale.

Avant le moteur actif : approuver assiette, seuils de niveau/badge, visibilité,
règles de sessions et corrections ; construire stockage transactionnel et reçus,
autorités productrices, admission Federation/Events/Apps Registry, consumer fermé,
réconciliation, conflits et retards réseau. HoF doit ajouter sessions UTC bornées,
domaines et portées locale/nationale/internationale, appartenance consentie,
correction des sessions closes et séparation affichage privé/public.

Les projections Me/Pro, rôle Pro, module d'offres, catalogue cosmétique commun et
Max restent les lots décrits dans [la matrice](FANS-ENGINE-OWNERSHIP.md). Aucun
paramètre produit encore à décider n'est transformé ici en score actif.

Rollback : retirer le simulateur ; aucune donnée persistante, migration ou
configuration de production à modifier.

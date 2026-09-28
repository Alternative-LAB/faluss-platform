# ADR 0018 — Contrat Fans PF / PC / HoF v3

- Date : 28 septembre 2026.
- Identifiant : `fans.economy-contract/3.0.0` (contrat documentaire, pas version du simulateur).
- Statut : règles acquises ci-dessous ; propositions et arbitrages explicitement non acceptés.
- Référence : `main` `d2d8cf158458097125ce667583e341f64c75931a` et audit local du 28 septembre.
- Portée : lot 1 documentaire exclusivement ; aucun moteur, migration, paiement, UI, flag activé ou changement du Token Engine.

## Contexte et remplacement

Cette ADR remplace pour la cible Fans les règles économiques de #71 : soutien EUR direct donnant du score, statistiques de revenu créateur, progression assimilable à une récompense et usage des PF pour les nouveaux cosmétiques. Le [simulateur v2](../modules/PROGRESSION-SIMULATION.md) reste inchangé et historique : il n'implémente pas ce contrat. Aucun historique ni solde n'est recalculé ou converti.

## Règles acquises

1. L'achat d'un pack PF crée **zéro score**. Seule l'attribution attestée de **PF achetés**, effectivement consommés par leur propriétaire, peut compter pour le HoF. Une classe `funded` ou une déclaration du client seule ne prouve pas cette provenance. Soutien EUR direct, cadeau gratuit et bonus sans preuve de PF achetés sont exclus de ce flux.
2. Une attribution canonique peut alimenter plusieurs classements admissibles **sans dépenser deux fois les PF**. Un seul reçu de consommation ; projections distinctes par classement/session/dimension, chacune idempotente. Une correction se propage à toutes les projections concernées sans seconde compensation économique.
3. Achat, attribution et remboursement PF créditent **zéro PC**, directement ou via un événement dérivé, seuil, badge, classement ou récompense. Les PC sont gagnés par les fans et utilisables pour des cosmétiques ; leurs sources restent fermées jusqu'à décision de leur propriétaire et des barèmes.
4. Les anciens PF `earned` et `promotional` ne deviennent **ni PC ni PF éligibles au nouveau HoF**, y compris par ajout d'une métadonnée de financement ou renommage. Aucun changement des anciens claims ou du Token Engine dans ce lot.
5. Le Shop est limité aux contenus, prestations, services et produits autorisés. Une opération Shop n'ajoute aucun score ou PC implicitement. Les PF de soutien et les droits Shop ont des contrats distincts.
6. Aucun wallet, solde, montant financier, revenu, dépense du donateur ou équivalent EUR n'est exposé dans l'interface ou l'API destinée au créateur, même privée. Le score public n'est pas une créance ni un revenu. **Il peut néanmoins permettre une estimation économique indirecte**, notamment par rapprochement avec des prix publics : aucune promesse d'impossibilité d'estimation.
7. Un éventuel badge de soutien reste distinct des PC, sans montant affiché et sans récompense PC dérivée. Pseudo/badge accessibles au seul créateur concerné via une relation vérifiée ; public sur consentement révocable. Les critères du badge restent à valider, donc son calcul demeure fermé.
8. Identités réelles résolues côté serveur, auto-attribution refusée, reçus authentifiés et absence de preuve = refus. L'égalité d'UUID fictifs du simulateur ne remplace pas cette vérification.

## Décision produit validée, sans activation

**1 PF acheté, attesté et effectivement attribué = 1 point HoF** est une décision produit validée. L’achat du pack seul donne zéro point ; aucune équivalence EUR n’entre dans le calcul du score. Cette décision ne constitue pas une implémentation : politiques de classement, protocole PF et autres parcours restent fermés jusqu’à leurs validations propres. Un futur format entier versionné devra éviter les flottants ; il ne doit pas reprendre l’équivalence EUR du simulateur v2.

## Intentions produit PC — sans activation

Dans la cible, le **daily reward** et le **ramassage quotidien sur le profil d’un
créateur** attribueraient des **PC au fan**. Ce sont des intentions produit :
propriétaire technique, barèmes, plafonds et date d’activation ne sont pas fixés.
Ces parcours restent fermés jusqu’à leur contractualisation et autorisation.
Les claims Hub/Me existants distribuent des PF historiques : ils ne sont pas
renommés en PC, aucun solde n’est migré et leur comportement reste inchangé.
Acheter, attribuer ou rembourser des PF ne génère jamais de PC, même indirectement.

## Frontières des propriétaires

Hub / Token Engine conserve l'autorité PF et ses ledgers. Fans n'écrit pas dans ses tables internes et ne crée pas un second ledger PF. Fans pourra conserver des références de faits et des projections de score reconstruisibles. L'autorité PC et celle de ses droits cosmétiques restent à décider ; aucun ledger PC Fans n'est implicitement autorisé. Les façades publiques et leur admission réseau devront être revues séparément.

La façade actuelle ne fournit que statut et claim quotidien Hub ; pack, support Fans et débit cosmétique restent fermés. La compensation standard du Token Engine reprend **le montant entier**, avec **une seule compensation par écriture**. Elle ne fournit pas de protocole de compensations partielles successives. Le parcours partiel est bloqué ; ne pas le simuler par écritures internes, fractionnement rétroactif ou ledger parallèle Fans.

## Corrections, litiges et remboursements

- Conserver le fait original, son autorité, référence, politique, date et révisions. Même clé/révision et même contenu = rejeu sans effet ; contenu différent = conflit. Les corrections référencent l'original et ne modifient pas silencieusement l'historique.
- Un remboursement de PF jamais attribués n'affecte aucun score. Après attribution, retrouver les allocations achetées concernées ; corriger chaque classement dérivé une fois, sans compter pack et attribution deux fois. La correction de score n'est pas une preuve de remboursement économique.
- Aucun montant EUR n'est déduit du score ; aucun nombre de PF annulés n'est inventé à partir d'un prorata monétaire. Les remboursements partiels restent fermés tant que allocation, capacités propriétaires, insuffisance de solde et reprise après panne ne sont pas contractualisées.
- Un litige doit rendre non comptabilisable la contribution identifiée jusqu'à résolution authentifiée ; une révision plus récente peut restaurer uniquement le net valide. Un événement ancien ne rétablit pas un score annulé. Le protocole runtime reste à construire.
- Une correction de session clôturée doit rester traçable et produire une nouvelle révision du classement d'origine, pas un débit dans une nouvelle session. Politique des sessions et sort des titres/cosmétiques après correction non décidés : clôture avec récompense et attribution de titres restent fermées.
- Achat, attribution, remboursement et correction PF : delta PC nul. Une correction PC éventuelle relève de son propre fait gagnant et d'une politique PC séparée. Aucun wallet créateur n'est crédité ou débité.

## Arbitrages et parcours fermés

| Décision attendue | Parcours fermé jusque-là |
| --- | --- |
| Propriétaire PC, sources gagnantes, barèmes, plafonds, fraude, expiration et corrections | Gain/dépense PC et acquisition de cosmétiques |
| Devenir des anciens claims Me/Hub et PF historiques | Toute nouvelle conversion, reprise ou extinction de claims ; les implémentations existantes sont préservées |
| Sessions, dimensions, multi-classements, suspensions et critères du badge | Classements actifs, sessions et badge calculé |
| Allocation et remboursements partiels, insuffisance de solde, coordination des autorités | Remboursement partiel et ouverture économique qui en dépend |
| Sort des titres et cosmétiques après correction | Attribution définitive de récompenses de classement |
| Vendeur contractuel Shop, responsabilités et traitement financier hors surfaces créateur | Vente, paiement, commande et délivrance commerciale |
| Activer éventuellement le catalogue autorisé après revue de l’archivage Store v2 | Activation réelle fermée ; achat toujours refusé |

## Shop et historique adulte externe

Cible retenue : **retirer `external_adult_delivery_right` et ses anciennes fiches du Shop et de la découverte publics**, même si elles sont actuellement classables. Conserver leurs identifiants, fiches, décisions et traces pour l’administration, sans effacer l’historique ni les transformer en produits autorisés. L’archivage effectif fera l’objet d’un lot distinct ; aucune suppression ou migration dans #80. Dans le code inchangé, la catégorie reste actuellement classable ; fiches nominatives masquées sans consentement, achat refusé **403** ; contenu hébergé encore fermé **503**. Aucun contenu adulte dans Fans, messages compris. Aucun accord prestataire ne lève automatiquement le refus. Aucun futur panier, webhook ou droit n'est déclaré protégé avant son implémentation et ses tests propres.

Mise en œuvre ultérieure : le [Store v2](../modules/FANS-STORE.md) réalise maintenant cet archivage dans un lot distinct de #80. La description du code inchangé ci-dessus est historique : catégorie et fiches sont désormais retirées des surfaces publiques, conservées en administration, sans conversion ni suppression. Aucun flag ouvert.

## Vérification attendue et retour arrière

Lot documentaire : vérifier cohérence des liens et séparation état implémenté/cible. Lots futurs : pack sans score, PF sans PC pour achat/attribution/remboursement et événements dérivés, multi-classements avec consommation unique, sources non achetées refusées, rejeux, corrections toutes projections, identité réelle, API créateur sans finances et achat Shop sans récompense implicite. Aucun test v2 ne vaut validation v3.

Retour arrière : revert documentaire de cette ADR et de ses alignements, sans migration ni effet sur les données. Aucun flag n'est ouvert par cette ADR. Références : [Fans](../modules/FANS.md), [ownership](../modules/FANS-ENGINE-OWNERSHIP.md), [Shop](../modules/FANS-STORE.md).

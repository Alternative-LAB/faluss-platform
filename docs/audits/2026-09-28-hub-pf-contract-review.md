# Revue ciblée Hub / Token Engine — PR #82

Code propriétaire examiné : `main` `634eee2a8556f4b8458822641a03114e88334c3e` ;
contrat initial : `98d070eebb34e09bf6a1923406418141f28233a8`.
Cette revue de code ne vaut **ni approbation du propriétaire Hub, ni recette Hub réelle**.

## Propriétaire technique et validation

Le domaine propriétaire est **Hub / Token Engine**, sous `src/TokenEngine` :
façade Platform, service PF et schéma historiques. Fans reste consommateur.
Le producteur de preuve économique d’achat doit encore être désigné.

## Rectificatif de la porte de fusion

Cette proposition demeure **proposée, non ratifiée par Hub**. Aucun compte ou
équipe Hub ne doit être désigné artificiellement pour sa fusion documentaire.
L’approbation du responsable Hub sera exigée **avant l’implémentation du protocole**,
pas avant la fusion de #82. La revue ciblée ne vaut pas cette approbation.
Les contrôles et protections ordinaires du dépôt restent requis pour la fusion ;
toutes les capacités économiques restent fermées.

## Matrice de revue

« Retenu » signifie cohérent avec le code et acceptable comme exigence documentaire
dans cette revue, pas livré ni approuvé par le propriétaire.

| Sujet | Retenu | Doit changer / précision apportée | Reste bloqué et responsable |
| --- | --- | --- | --- |
| Preuve d’achat | `funded` seul ne prouve pas un achat | Référence générique `source_event_reference` et `metadata` ne sont pas un modèle de lots attestés | Autorité économique à désigner ; Hub doit valider le lien preuve/lot/fan |
| Réservation/confirmation | Intention canonique et consommation unique | Verrous existants par sujet/classe et daily claim, pas par réservation/lot ; ne pas les présenter comme garantie du protocole proposé | Hub : états, TTL, atomicité réserve/expire/libère/confirme/litige |
| Lot–attribution | Rattachement explicite, fermeture si incomplet | Aucun index/table de lot, tranche ou attribution dans le schéma PF actuel ; ne pas détourner une métadonnée pour supposer cette intégrité | Hub : modèle propriétaire et snapshots complets ; Fans : consommation des seules preuves autorisées |
| Idempotence | Rejeu même clé sans seconde écriture existant | **Pas de comparaison de payload dans le chemin de replay** ; empreinte canonique et conflit sémantique exigés par le nouveau contrat ne sont pas disponibles | Hub : protocole dédié, sans changer silencieusement les claims existants |
| Reprises | Retrouver l’état après réponse perdue | `COMMIT` en erreur retourne une erreur ; ce résultat n’est pas un reçu d’absence de débit. Aucun lookup Fans authentifié ou outbox de consommation attestée disponible | Hub : état autoritatif durable et outbox ; Fans : état inconnu, inbox idempotente, aucun nouveau débit de secours |
| Corrections | Original conservé, compensation liée | Compensation standard montant intégral, unique ; insuffisance de solde de classe possible. Même le remboursement total d’un pack consommé n’est pas garanti par cette primitive | Hub : allocation des corrections, états litigieux, insuffisance et compensations partielles ; Fans ne compense pas le ledger |
| Claims historiques | 20 PF Hub, 75 PF Me ; `earned`, clés par date logique Europe/Paris, preuves distinctes | Ne pas remplacer `valid_server_proof` interne par un contrat réseau, ni traiter ses booléens comme une signature | Hub et consommateurs historiques : aucune modification dans #82 |
| Reçu authentifié | Audience/émetteur/version exacts, liaison requête et révisions | Les résultats actuels renvoient une référence et des balances, pas un reçu de lot signé. HMAC du fake sans valeur probante Hub | Hub et propriétaire transport : format, signature, clés et révocation avant intégration |

## Références relues dans le propriétaire

- [Façade](../../src/TokenEngine/TokenEngineContract.php) : deux méthodes quotidiennes,
  version/schema vérifiés ; aucun protocole d’achat ou consommation Fans.
- [Service PF](../../src/TokenEngine/Legacy/includes/class-token-engine-points-service.php) :
  `validate_future_entry`, `normalise_entry` refusent les capacités futures ;
  `write_entry` retrouve la clé avant de comparer un quelconque contenu, utilise
  `GET_LOCK`, transaction et vérification de solde, puis vérifie le résultat de COMMIT.
  `compensate_entry` reprend le montant entier ; `daily_definition`,
  `valid_server_proof`, `daily_context` conservent les contrats Hub/Me.
- [Schéma](../../src/TokenEngine/Legacy/includes/class-token-engine-schema.php) :
  schéma v5, unicité PF par entry UUID, clé d’idempotence et original compensé ;
  pas d’unicité lot/tranche/attribution.
- [Tests existants](../../tests/TokenEngine/TokenEngineContractTest.php) : périmètre
  de la façade quotidienne ; [faux serveur](../../tests/Fans/PfContract/FakeHub.php)
  et [tests proposés](../../tests/Fans/PfContract/PurchasedPfContractTest.php) :
  spécification exécutable séparée, pas validation de l’implémentation Hub.

## Limites et changements nécessaires avant intégration

Le faux serveur pose les garanties attendues ; il ne les démontre pas pour Hub.
Les Fibers entrelacent seulement deux réservations autour d’un verrou mémoire.
La sérialisation confirmation/expiry/libération/litige n’est pas prouvée par ces
tests, ni la survie à un crash, la pagination cohérente, multi-lots ou crypto réelle.
Ces écarts sont des dépendances explicites, sans changement du Token Engine ici.

Avant l’implémentation, le responsable Hub devra ratifier les garanties proposées,
confirmer l’autorité de chaque donnée et les blocages, ou demander leur révision.
Cette approbation ne certifiera pas à elle seule leur disponibilité et n’autorisera
ni paiement ni flag. La fusion de cette proposition documentaire n’est pas une ratification.

Pour toute implémentation ultérieure : lot propriétaire autorisé, contrat réseau
et capacités réellement livrés, non-régression des claims et recette réelle de
concurrence/pannes/réconciliation. #82 peut être fusionnée après vérification des
contrôles et protections, en conservant son statut proposé et non ratifié.

# Recette du Hub PF actuel — lot H0

Cette recette appelle le **module Token Engine livré**, avec son bootstrap Platform,
son schéma v5 et ses transactions réelles, sur un WordPress/MariaDB Hub jetable.
Elle complète les modèles PHPUnit et le fake de #82. Elle n'implémente aucun
protocole PF acheté et n'interroge aucun site cible.

## Autorisation et décisions

Le porteur du projet a désigné **ALB-Origine** comme propriétaire habilité Hub /
Token Engine le 5 octobre 2026. Il autorise cette recette isolée avec données
fictives, pas les opérations économiques nouvelles. Les décisions D1 à D6 et
l'autorisation d'implémentation sont soumises dans
[l'issue #147](https://github.com/Alternative-LAB/faluss-platform/issues/147).
Le [contrat 0.1.0](../../../docs/modules/FANS-HUB-PURCHASED-PF-CONTRACT.md)
reste proposé et non ratifié.

## Exécution reproductible

Prérequis Linux : PHP avec mysqli/mbstring, Python 3, MariaDB server/client,
dépendances Composer installées, WordPress 7.1.2 propre et WP-CLI 2.12.0.
La CI utilise les téléchargements épinglés et vérifiés de son job WordPress.

```sh
python3 tests/TokenEngine/recipe/run.py \
  --source "$PWD" \
  --core /chemin/wordpress \
  --cli /chemin/wp-cli.phar \
  --output /chemin/hub-pf-checks.json
```

La recette crée un répertoire privé neuf `hub-pf-wp-*` sous `/var/tmp`, copie le
plugin et le cœur sans wp-config existant, installe MariaDB sans écoute réseau
et active le rôle Hub uniquement dans ce wp-config jetable. Aucun serveur HTTP
n'est lancé. Les workers WP-CLI utilisent des connexions SQL distinctes.
Les processus et le répertoire sont supprimés en fin de recette, y compris en
cas d'échec. Le JSON exporté ne contient que noms de contrôles, résultats,
versions et hash du service, aucune identité, écriture, clé ou configuration.

## Scénarios positifs et négatifs

| Déclencheur | Résultat attendu du code actuel |
| --- | --- |
| Installation réelle du module Hub | Schéma v5 vérifié, ledger PF InnoDB, façade limitée aux deux méthodes quotidiennes |
| Preuve Hub absente ou preuve Me sans carte publiée | Ineligible, aucune écriture |
| Huit processus réclamant simultanément Hub et Me pour le même sujet | Une écriture de 20 PF earned et une de 75, somme 95 ; aucun changement de classe |
| Rejeux quotidiens | Même état, lignes originales inchangées ; clés owner/reward/sujet/date Europe/Paris/politique préservées |
| Worker tué après INSERT mais avant COMMIT | Transaction annulée ; une relance crédite une fois |
| Worker tué après COMMIT, avant réponse | Crédit durable ; statut et relance retrouvent le claim sans doublon |
| COMMIT exécuté puis acquittement simulé en erreur au niveau wpdb | Unavailable avec crédit réellement persisté ; relance idempotente, jamais déduction d'absence de crédit |
| Deux compensations intégrales concurrentes de la même écriture | Une seule compensation, montant entier et même classe, original inchangé |
| Rejeu de compensation ; tentative de compenser une compensation | Même référence sans duplication ; seconde tentative structurellement invalide |
| Même clé de compensation, motif changé | Le code actuel retrouve l'original sans comparer le motif : **écart caractérisé**, pas garantie d'idempotence sémantique du futur protocole |
| Solde consommé par un débit de fixture explicitement préchargé | Compensation du crédit refusée pour insuffisance ; aucune ligne nouvelle, solde non négatif |
| Appels concurrents aux cinq capacités futures | `pf_feature_not_enabled`, aucun achat/soutien/débit cosmétique/ajustement ; ledger ALB indépendant |

Le seul débit synthétique est inséré comme **état initial de test** pour exercer
le refus de compensation faute de solde. Il ne passe pas par une API de soutien,
ne contourne aucune garde de production et ne démontre aucune consommation PF
achetée. Aucun fichier `src`, claim, politique, schéma ou ledger propriétaire
n'est modifié par ce lot. Fans n'acquiert aucun ledger parallèle.

## Limites de preuve et lots suivants

Le kill après COMMIT produit une vraie perte de réponse du processus appelant.
Le retour `false` de wpdb après COMMIT est une **injection d'acquittement perdu**,
pas une panne réseau entre PHP et MariaDB. Aucun restore, réplica, transport HTTP
Fans ↔ Hub, achat, lot, reçu signé ou remboursement partiel n'est certifié.
Les données sont fictives ; aucun environnement Hub cible/préproduction n'est
désigné. La recette de futures consommations concurrentes attend une capacité
propriétaire opérationnelle et explicitement autorisée.

Les lots proposés H1 (preuve/lots), H2 (réserve/confirm/lookup/outbox), H3
(transport/reçus), H4 (corrections/snapshots) et F1 (projection Fans) sont bornés
et ordonnés dans #147, avec les décisions nécessaires pour chacun. Ils ne sont
pas implémentés pendant l'attente de ratification. Aucun achat ni score HoF réel
ne doit être annoncé sur la seule base de ces tests.

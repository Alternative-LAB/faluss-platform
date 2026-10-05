# Hub PF acheté — modèle fermé H1

## Accord et limites

Le 5 octobre 2026, **ALB-Origine**, propriétaire habilité Hub / Token Engine, valide
**D1, D2 et D4 uniquement pour H1** dans [#147](https://github.com/Alternative-LAB/faluss-platform/issues/147).
L'accord concerne les validateurs, le schéma additif et la persistance sur
WordPress/MariaDB Hub jetable avec preuves fictives. La proposition R1 complète
`fans.hub-purchased-pf/0.2.0` et le contrat antérieur 0.1.0 ne deviennent pas des
protocoles économiques ratifiés par cet accord limité.

**D3, D5 et D6 restent proposés.** Deux effets produit sont soumis séparément :
[ordre de réduction des scores après remboursement, #149](https://github.com/Alternative-LAB/faluss-platform/issues/149),
[conservation des preuves et anti-rejeu, #150](https://github.com/Alternative-LAB/faluss-platform/issues/150).
La réduction des scores et une durée de 24 mois ne sont ni implémentées ni
appliquées par H1. Ces décisions précèdent H4 et toute ouverture économique.

Accord ultérieur : [H2 fermé](HUB-PF-H2-CLOSED.md) est autorisé séparément,
avec seulement les garanties D6 nécessaires de transaction/journal/lookup primaire
et clé stable. H1 garde son comportement non consommant ; D3 et les effets #149/#150
ne sont pas ratifiés par cet accord.

H1 n'enregistre aucun producteur d'achat réel, n'authentifie aucune preuve de
paiement et n'accorde aucun droit depuis un UUID. Il ne crédite/débite aucun PF,
ne réserve rien, ne génère aucun reçu signé, n'appelle aucun writer historique
et n'ouvre aucune API/UI Fans, route Connector, hook, cron ou consumer Events.
La façade Platform et les six classes historiques restent inchangées.

## Données validées

Le modèle est dans `TokenEngine/PurchasedPf`, propriétaire Hub uniquement.

| Objet | Validation et sens dans H1 |
| --- | --- |
| `PurchaseEvidence` | Forme fermée `hub.purchased-pf.h1-model/1.0.0` : source, référence d'achat, révision, preuve, titulaire, quantités acheté/bonus/annulé cumulatif, état, dates et politique. Validation de forme, **pas attestation d'achat**. |
| `AttributionIntent` | UUID d'attribution globale, autorité cliente, membre/créateur distincts, quantité positive et politique. Intention immuable, **pas instruction économique**. |
| `AllocationPlan` | Filiation exacte vers des lots de modèle confirmés appartenant au membre, quantité entière ou refus, jusqu'à 32 lots. Plan non consommant ; plusieurs intentions peuvent planifier les mêmes unités. |
| `ClosedModelStore` | Admissions, conflits, historique des preuves et rejeux atomiques dans la recette. Aucune balance ni disponibilité économique calculée. |

Quantités et révisions : chaînes décimales canoniques, entiers exacts de 0 à
`9007199254740991` (strictement positifs pour acheté/révision/intention), PHP
64 bits requis. UUID v4 minuscules canoniques ; références ASCII opaques de
128 caractères au plus, sources ASCII de 64 au plus, versions semver bornées.
Dates source UTC RFC3339 à la seconde, valides et ordonnées ; aucune URL, email,
montant monétaire ou secret dans la forme acceptée. Champs inconnus refusés.

États source modélisés : `pending`, `confirmed`, `disputed`,
`partially_cancelled`, `cancelled`. Les bornes et la monotonie de l'annulation
cumulative sont validées ; **aucune allocation d'un remboursement n'est calculée**.
Le plan H1 n'utilise que les lots actuellement `confirmed`, sans bonus. Un lot
partiellement annulé reste exclu du plan H1 : la consommation de son net attend
les opérations et corrections explicitement approuvées des lots ultérieurs.
Un solde historique `funded`/`earned`/`promotional` ne remplace jamais une provenance.

## Schéma additionnel et installation fermée

Le schéma H1 est versionné **1**, séparé du schéma historique **5**. Tables sous
le préfixe WordPress `token_engine_pf_h1_` :

| Table | Contraintes principales |
| --- | --- |
| `schema` | Marqueur exact version 1, portée `closed_h1_model`, une ligne. |
| `evidence` | Preuve globale unique ; unicité source/achat/révision ; JSON et empreintes immuables par révision. |
| `lots` | Un lot source/achat ; titulaire et quantités d'origine fixes ; preuve courante et première admission confirmée Hub. |
| `intents` | Attribution globale unique, contenu lié au client/membre/créateur/quantité/politique ; plan figé et empreinte. |
| `keys` | Source/opération/empreinte SHA-256 de clé 256 bits ; contenu et résultat d'origine liés. Portée majeure H1 v1 implicite dans ce schéma isolé. |

`installForRecipe` est appelé explicitement par le worker de test. Il n'est
référencé ni par le bootstrap du plugin, ni par une activation, mise à jour,
option, commande opérateur ou requête HTTP. Son garde exige simultanément :

- WP-CLI, PHP 64 bits, rôle Hub et Token Engine chargés dans la fixture ;
- environnement WordPress `local` et marqueur **de recette uniquement**
  `FALUSS_HUB_PF_H1_RECIPE_ONLY=true` ;
- racine privée neuve `/var/tmp/hub-pf-wp-*` en mode 0700, base `hub_pf_recipe`
  sur le socket exact de cette racine, instance `$wpdb` courante ;
- schéma historique prêt, version 5.

Le marqueur n'est **pas un flag de production** : ne pas l'ajouter à un site.
Toutes les méthodes de persistance et de lecture répètent le garde. Seules des
autorités `fixture.*`, explicitement admises dans le worker, sont acceptées ;
aucun registre de producteur ni adaptateur commercial n'est installé.

Installation neuve : tables temporaires à suffixe aléatoire, vérification stricte
des colonnes, types, nullabilité, collations, index complets et moteur InnoDB,
puis publication des cinq tables par un `RENAME TABLE` atomique sous mutex de
schéma. Réinstallation identique inerte. Groupe partiel/divergent refusé sans
réparation ni adoption ; seules les nouvelles tables temporaires sont nettoyées.
Aucun DDL, option, backfill ou index historique n'est modifié. La future
installation hors recette demande un nouveau lot autorisé et sa migration revue.

## Persistance, ordre et reprise du modèle

Un mutex consultatif propre au **modèle de recette** sérialise ses écritures,
avec transaction InnoDB et délai de 10 secondes. Il ne remplace ni ne modifie
les verrous économiques historiques sujet/classe ; les verrous et consommations
H2 restent à implémenter après accord. Transactions imbriquées refusées.

- Même clé et empreinte : résultat du modèle déjà enregistré ; autre contenu :
  conflit sans effet. Changer la clé ne permet pas de dupliquer une attribution.
- Même source/achat/révision et contenu : même preuve/lot ; contenu différent :
  conflit. Révision nouvelle : preuve ajoutée, original intact, lot mis à jour.
  Ancienne révision avec nouvelle clé : refus. Rejeu exact d'une ancienne clé :
  résultat historique, **jamais certificat d'admissibilité courante**.
- Titulaire, achat, acheté, bonus et politique d'origine immuables. Date de
  confirmation fixe dès qu'elle existe ; observation et révisions monotones ;
  annulation cumulative non décroissante, état annulé terminal.
- `accepted_at` est l'horloge UTC MariaDB, à la **première admission confirmée
  dans le modèle Hub**, et reste fixe. FIFO par date croissante puis `lot_id`
  ASCII binaire croissant. Une date d'achat ancienne ou un compte WordPress
  antérieur ne prennent pas priorité sur une admission Hub ultérieure.
- Preuve, lot/intention et clé sont commis ensemble. Erreur avant commit :
  rollback. Acquittement COMMIT incertain : `model_commit_unknown` ; ne pas
  déduire un rollback. `lookup` privé de modèle ou relance identique retrouve
  le résultat durable. Aucun retry réseau, journal Events ou protocole D6 livré.

Les empreintes SHA-256 utilisent un encodage à ordre de champs fixe pour
détecter les conflits de modèle. Ce n'est ni le format canonique signé D3, ni
une signature, ni une preuve indépendante d'un achat ou d'une identité.

## Recette et retour arrière

Exécution : [README de recette H0/H1](../../tests/TokenEngine/recipe/README.md),
option `--h1`. Le rapport n'exporte que noms de contrôles, versions et hash du
service ; aucune identité, preuve détaillée, clé ou configuration. La fixture
et ses processus sont détruits en fin de test, même en cas d'échec.

Scénarios utiles : validateurs positifs/négatifs ; sources/classes sans preuve
refusées ; premières admissions et FIFO ; conflits et huit connexions concurrentes ;
rollback avant COMMIT, arrêt après COMMIT et injection d'acquittement perdu ;
schéma partiel, index absent, moteur incorrect et corruption de liens détectés ;
ledgers PF/ALB comparés intégralement avant/après H1. Tous les contrôles H0 restent
exécutés. Les preuves sont fictives, le moteur et les transactions sont réels.

Retour arrière : retirer ce code par PR si nécessaire ; aucune donnée réelle
n'est à migrer ou purger et aucune option de production n'est à changer. Ne pas
exécuter de DROP ni modifier un ledger sur un site. L'environnement Hub cible,
les consommations opérationnelles concurrentes, paiements, reçus, corrections
partielles et reprises par restauration ne sont pas attestés par cette recette.

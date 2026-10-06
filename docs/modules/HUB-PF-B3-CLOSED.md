# B3 fermé — ordre et dimensions attestés

Accord Hub d'**ALB-Origine** sur le [contrat précis au commit approuvé](https://github.com/Alternative-LAB/faluss-platform/blob/2553e3769b478bf44deaf5e86152c915e145300a/docs/modules/HUB-PF-B3-RANKING-PROPOSAL.md).
Périmètre : instances Hub/Fans jetables, achats/identités/pairs/clés fictifs.
Aucune source d'achat réelle, admission réseau, migration de site, flag, score
public ou activation. [Conservation #150](https://github.com/Alternative-LAB/faluss-platform/issues/150)
et RustFS #161 restent distincts.

## B3a — formats additifs et scénarios avant intégration

`fans.hub-purchased-pf/0.3.0` est choisi explicitement. Ses domaines de signature
context/receipt/request/response sont `faluss.hub-purchased-pf.* /2` (sans espace).
Les formats `/0.2.0`, snapshots `/1.0.0`, domaines `/1`, claims, codec Federation
et opérations historiques sont conservés. Un ancien validateur refuse les
nouveaux champs ; aucune ancienne preuve ne reçoit une autorité rétroactive.

L'intention 0.3 contient les six champs historiques, `ranking_context` et son
`context_sha256`. Son empreinte canonique comprend **tout** le contexte ; changer
de catégorie/session/version n'est jamais un rejeu identique. Sa composition
`base` sert seulement à réutiliser les validations historiques et, dans le futur
B3b, le ledger officiel. Les anciennes fonctions ne reçoivent pas directement
la nouvelle intention élargie.

### Contexte canonique

| Champ | Validation et sens |
| --- | --- |
| `origin_id` | UUID de l'ouverture admissible préparée par Fans ; l'ouverture effective doit être admise par Hub en B3b |
| `policy_version` | Version sémantique, identique à l'intention |
| `creator_category` | `arts`, `music`, `games`, `learning` ou `lifestyle` ; catégorie éditoriale approuvée |
| `category_revision` | Révision positive exacte de cette décision |
| `country_policy_revision` | UUID immuable de la décision Faluss sur les pays de soutien autorisés, jamais une liste implicite |
| `sessions` | Zéro à dix choix explicites, tri croissant par UUID ; doublon/ordre ambigu refusé |

Pour chaque session : `session_id`, `rules_revision`, `rules_sha256`,
`admission_revision`, `barrier_version`, `starts_at`, `ends_at`, `admitted_at`,
`scope`, `territory_policy_revision`, `territory_admission_revision`, `country`,
`territory_ref`. Versions positives exactes ; instants UTC à six décimales sous
forme `YYYY-MM-DD HH:MM:SS.ffffff`. Durée réelle maximale 90 jours. Une
confirmation appartient à `[starts_at, ends_at[` et doit suivre l'admission.
Tout choix invalide refuse l'ensemble, sans retirer silencieusement une session.

Portée internationale : territoire/politique territoriale vides et révision
territoriale `0`. Nationale : pays déclaré et examiné, UUID de politique/révision
positives, localité vide. Locale : mêmes preuves et référence nommée non vide.
Ce sont des références techniques ; Hub ne reçoit ni bio, nom, règles éditoriales,
preuve d'identité ou texte de recours. Leur simple syntaxe ne prouve pas l'admission.

Le JCS restreint ASCII historique reste intact : quantités/révisions en chaînes
décimales canoniques, pas de nombre JSON, Unicode éditorial ou URL média.
`context_sha256` est le SHA-256 des octets canoniques du seul contexte.

### Reçu et délégation

Le reçu 0.3 conserve les allocations/identités et validations historiques ; il
atteste en plus `ordering_epoch`, `consumption_order`, le contexte et son digest.
`confirmed_at` conserve les **six décimales** de l'horloge primaire. Époque UUID,
ordre entier positif ≤ 9007199254740991, aucune date réseau ou du navigateur.
Un reçu demeure une preuve historique, pas le net courant après correction H4.

La délégation conserve les permissions opérationnelles PF existantes, contexte
signé, clé d'idempotence liée, audience/client exacts et expiration de 60 secondes.
Les permissions `pf.ranking.context.register` et `pf.ranking.context.close` sont
additives, explicites et distinctes de `pf.confirm`, `wallet.read` et claims.
Leurs opérations sont encore absentes de B3a. Pairs toujours `fixture.*` seulement.

Positifs B3a : canonisation/empreinte complète, zéro/dix sessions, références
locales/nationales explicites, précision de confirmation, allocations exactes,
signatures natives dans les nouveaux domaines et compatibilité des anciens.
Négatifs : champ inconnu, Unicode/nombre/UUID/version/date ambiguë, digest changé,
session dupliquée/désordonnée/expirée/non admise, mauvaise audience, auto-attribution,
clé inconnue/révoquée, signature altérée et confusion des versions/domaines.

## Ordre de livraison restant

1. **B3b1** : barrières propriétaires et lookup primaire (sous-lot présent).
   **B3b2** : contexte immuable de l'intention et consommation ordonnée ;
   acquittement/lookup primaire, compteur transactionnel atomique avec le débit,
   reçu et journal officiels. Aucun ledger Fans ni allocation supplémentaire.
2. **B3c** : snapshot `hub.purchased-pf.snapshot/2.0.0`, complet et rapproché,
   conservant l'ordre/contexte d'origine malgré corrections/litiges. HTTP privé
   sous barrières physiques de recette, faute/panne/fermeture concurrents.
3. B4/B5 : adaptateurs vers projections mensuelles, persistantes et sessions ;
   B6 : interfaces selon la DA validée, preuves navigateur et captures.

B3a ne constitue **pas** une consommation 0.3 opérationnelle : aucun stockage,
hook, route, crédit/débit ou classement n'est ajouté. Aucun test DTO ne remplace
les preuves transactionnelles/réseau à obtenir dans les sous-lots suivants.

### Vérifications B3a

Recette de code isolée du 6 octobre 2026, PHP 8.5.4 et dépendances du verrou
Composer exact, sans jonction `vendor` Windows : **44 tests / 57 assertions**
nouveaux, **157 tests / 254 assertions** de modèle/protocole PF avec les anciens.
PHP lint et PHPStan ciblé/complet sans erreur ; suite complète **534 tests /
6 417 assertions**, zéro échec/erreur et deux dépréciations préexistantes.
Scan ciblé de secrets, liens relatifs et `git diff --check` satisfaisants.
Aucune preuve WordPress/réseau nouveau revendiquée par ce sous-lot sans stockage.
La CI complète du head exact demeure le gate de revue et de fusion.

## B3b1 — scénarios préalables des barrières propriétaires

Barrières privées d'origine, politique de pays, catégorie du Créateur, session
et admission individuelle. Le pair Fans propriétaire enregistre un contenu
canonique et une version exacts avec bornes explicites. Une fermeture ne prend
aucun verrou membre/lot/ledger. Confirmation future : mêmes lignes verrouillées
en ordre canonique, version la plus récente, contenu/bornes/état exacts ; aucune
sélection silencieusement réduite. Réadmission seulement par version suivante,
après fermeture effective, jamais réactivation d'une version fermée.

Positifs : installation explicite physiquement isolée ; permission dédiée ;
registration/rejeu/lookup sur le primaire ; fermeture effective à l'horloge Hub ;
concurrence, erreur avant COMMIT et réponse perdue après COMMIT ; même clé/requête,
un événement durable ; nouvelles versions sans altérer l'ancienne. Une réponse
de registration historique ne cache jamais l'état désormais fermé/supersédé.

Négatifs : simple constante hors enclave, destinataire/propriétaire étranger,
absence de permission, contenu/version modifié avec la même clé, nouvelle clé
pour la même opération, réouverture d'une ancienne version, saut de version,
schéma partiel/divergent ou journal défaillant ; aucune écriture économique.
Le raccordement atomique à la consommation est B3b2, pas une preuve B3b1.

### Contrat de stockage et reprise B3b1

Quatre tables privées InnoDB `token_engine_pf_b3b_*`, installées uniquement par
`ClosedBarrierSchema::installForRecipe()` après validation physique H3 (chemin
jetable, bail privé 0600, base/socket primaire, rôle Hub et environnement local).
Aucun appel du bootstrap ni option de migration. Les aides SQL de schéma H3
sont réutilisées sans changer ses définitions ou formats historiques.

Clé de barrière : SHA-256 de l'origine, type, objet et sujet canoniques, hors
version. Contenu/version exacts, empreinte de descripteur et bornes UTC à six
décimales. Une version initiale peut correspondre à une révision déjà examinée ;
la suivante exige fermeture effective et numéro immédiatement suivant.

Le verrou court de métadonnées sérialise registration/fermeture/lookup. Ces
opérations ne prennent aucun verrou économique. La future consommation tient
les lignes sélectionnées en ordre canonique dans la transaction officielle H2.
La fermeture attend cette transaction : aucune inversion membre/lot/ledger.

La clé et la requête complète demeurent identiques après résultat incertain.
Un acquittement historique de registration ne permet jamais de réouvrir une
version : le lookup rapporte son état actuel `closed` ou `superseded`. Une
erreur primaire ne devient pas `not_found`. Le journal, l'état et la clé sont
dans le même COMMIT ; une perte de réponse exige lookup, pas une nouvelle clé.

Retour arrière : aucun schéma sur les sites ; dans la recette, suppression de
l'enclave entière après les tests. Ne jamais adopter ou réparer silencieusement
des tables divergentes ; aucune suppression de faits économiques historiques.

### Vérifications B3b1

[Preuve expurgée](../evidence/hub-pf-b3-barriers/README.md) : 37 scénarios nouveaux
sur WordPress 7.1.2 / MariaDB 11.8.6 / PHP 8.5.4, après 186 scénarios historiques
H0–H3. Suite PHP complète 545 tests / 6 466 assertions, zéro échec/erreur,
deux dépréciations préexistantes ; PHPStan complet sans erreur. CI additive
`--b3-barriers`, rapport vérifiable sous artefact GitHub, contrôles historiques
conservés. Aucune preuve économique 0.3 ou fermeture réseau revendiquée.

## B3b2 — scénarios préalables de consommation ordonnée

Intention 0.3 immuable liée à la réservation, aucune attache rétroactive d'une
intention 0.2. Les points d'entrée anciens refusent une attribution liée au
format nouveau ; les anciennes attributions et leurs clés restent inchangées.
Un compteur propriétaire verrouillé, les barrières exactes et l'horloge primaire
précèdent immédiatement le débit officiel. Ordre, contexte, reçu signé, journaux,
réservation et clé participent au même COMMIT. Rollback = aucun ordre consommé ;
acquittement incertain = lookup avec la même intention et clé.

Positifs : réservations/contextes exacts, plusieurs membres/lots/dimensions,
ordre global unique y compris à date identique, clôture concurrente, reçu/journal
et débit atomiques, expiration, réponse perdue, reprise historique après clôture.
Négatifs : contexte changé, tentative de downgrade, rattachement rétroactif,
barrière inconnue/fermée/expirée, compteur divergent/épuisé, panne avant COMMIT ou
signature/journal défaillant. Corrections H4 gardent le fait d'origine intact ;
le reçu n'est jamais présenté comme un score net courant.

### Stockage propriétaire B3b2

`ClosedRankingSchema::installForRecipe()` ajoute explicitement cinq tables
InnoDB `token_engine_pf_b3r_*` : version, compteur d'ordre, intentions liées,
reçus et journal. Garde physique H3 et schéma des barrières requis ; tables
temporaires vérifiées et renommage atomique. Aucun bootstrap, activation ou
visite ne l'appelle. Aucun solde, crédit ou ledger supplémentaire dans ce schéma.

`ClosedRankedStore` compose les propriétaires H2 existants. Réservation : liaison
canonique 0.3 et sélection entière admise dans la transaction ; une ancienne
réservation ne peut pas être convertie après coup. Confirmation : mêmes verrous
officiels membre/lot/ledger, puis compteur propriétaire et barrières canoniques.
Horloge primaire après acquisition des verrous ; expiration et régression
d'horloge refusées avant débit. Le compteur atteste un ordre total entre membres
et conserve la même époque. Nombre, domaine des ordres et époque des reçus doivent
concorder avec le compteur : restauration incomplète/contradictoire refusée,
aucune réparation ou nouvelle époque automatique.

Le débit `purchased` officiel, consommation, clé, état de réservation, reçu H3
historique, reçu signé 0.3, ordre et journaux partagent **un seul COMMIT**.
Le reçu historique reste nécessaire au propriétaire H4 et ne crée aucun deuxième
débit. Un échec de signature ou d'une écriture annule toute la transaction.
Le journal 0.3 est `pending` ; cela ne prouve pas encore une remise réseau.

Les points d'entrée H2 historiques restent identiques pour les attributions
anciennes. Si une intention 0.3 est déjà liée, ils refusent sa consommation ou
reprise sans contexte, y compris par clé nouvelle. La primitive de reçu exige
une liaison valide, le débit propriétaire et l'ordre staged de la transaction :
elle ne peut pas donner une autorité F1b rétroactive à un fait ancien.

Après résultat incertain, lookup **primaire** avec la même intention, opération
et clé. Confirmation/reçu retrouvés : mêmes ordre, contexte et bytes signés ;
absence confirmée : rejeu de la même opération, jamais une clé de contournement.
Clôture ou expiration n'effacent pas le fait déjà commité. Une correction H4
révise le net, pas la date/ordre/contexte d'origine ; un ancien reçu n'est pas
une projection courante et ne restaure pas les PF annulés.

### Vérifications et limites B3b2

La [recette expurgée](../evidence/hub-pf-b3-ranked/README.md) distingue les
scénarios historiques, les barrières B3b1 et les consommations ordonnées B3b2.
Deux membres au même instant primaire, huit confirmations concurrentes,
plusieurs lots/sessions, clôture concurrente, panne de journal/signature,
expiration, processus tué avant/après COMMIT, réponse perdue et corrections
partielles/totales/litiges/résolutions sont exercés sur WordPress/MariaDB jetables.

Le format snapshot 2.0, sa livraison Hub/Fans et la reprise locale « fermeture
en cours » restent B3c. Aucun serveur cible, vrai SSO ou producteur d'achat
réel n'est démontré. Un snapshot complet **par membre** ne prouve pas, à lui
seul, le corpus de tous les membres pour un classement général. Cette autorité
exhaustive et sa fraîcheur doivent être explicitement attestées, jamais déduites
du dernier reçu connu. Aucun score public ni règle de conservation #150 ajoutée.

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

1. **B3b** : schéma de métadonnées, contexte immuable de l'intention et barrières ;
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

# B3c2d — inbox durable privée Fans

Recette du 6 octobre 2026 sur copie LF immuable de l'arbre
f0e912ea42029666fa98ff48cf477443a40be801, avec les dépendances exactes
du verrou Composer. WordPress 7.1.2, PHP 8.5.4, MariaDB 11.8.6 ; deux
WordPress et bases distincts, primaire sur socket privé, racine POSIX
0700, bail 0600 et clés fictives différentes. HTTP Hub en loopback.
Aucune route globale de corpus n'est offerte à un membre ou navigateur.

```sh
python3 tests/TokenEngine/recipe/run.py --b3-corpus-inbox \
+  --source "$SOURCE_ISOLEE" --core "$CORE_JETABLE" --cli "$CLI_JETABLE" \
+  --output /var/tmp/fans-pf-b3-corpus-inbox-checks.json
```

**518/518 contrôles WordPress/MariaDB**, dont **48 nouveaux** pour
l'inbox, après les 61 contrôles HTTP du parent et les préfixes propriétaires
H0/H1/H2/H3, barrières, consommation 0.3, snapshots 2.0 et corpus.
Les anciennes recettes HTTP H3/H4 et F1a restent des contrôles CI séparés ;
cette invocation ne les compte pas. Les fixtures, bases et serveurs sont
détruits après la recette.

Suite PHP complète : **622 tests / 6 875 assertions**, aucune erreur ou
échec, deux dépréciations préexistantes ; HoF : 51 tests / 105 assertions.
PHPStan complet avec cible PHP 8.3 ; syntaxe de tous les PHP/Python
modifiés, scan ciblé de secrets, liens relatifs et contrôle du diff.

## Preuves obtenues

- Installation explicite de cinq tables InnoDB de métadonnées Fans ;
  schéma partiel/MyISAM refusé sans adoption, réparation ou migration.
  Les constantes seules sur WordPress ordinaire n'ouvrent aucune capacité.
- Quatre préparations concurrentes partagent un ID et une clé durables.
  Tout nouveau demandeur reprend le travail en attente avant d'en créer
  un autre. Origine et permission propriétaire exactes exigées.
- Requête signée, nonce, champs et digest persistés **avant HTTP**.
  Une réponse perdue après le COMMIT Hub se reprend par lookup primaire
  avec la même clé ; aucune matérialisation supplémentaire.
- Pages signées uniques, curseurs durables, rejets des réponses non liées,
  signatures altérées, requêtes locales altérées, page manquante et faits
  modifiés même avec une empreinte locale recalculée.
- Pertes d'acquittement des vrais COMMITs locaux, erreur de promotion et
  mort du worker avant/après COMMIT : reprise primaire sans nouvelle clé,
  sans deuxième page ni génération partiellement promue.
- Promotion atomique après réauthentification de toutes les pages et de
  la fence complète. Aucun résultat courant pendant le rapprochement.
- Consommation nouvelle pendant la collecte : toute la génération est
  refusée. Achat d'un lot seul : aucun fait et aucun score nouveau.
- Corrections H4 partielles, litige, résolution et annulation totale :
  remplacement exhaustif de 103 faits, zéros conservés. Un lot indépendant
  reste admissible ; aucun ancien succès ne restaure les points annulés,
  aucun ancien refus ne masque une génération plus récente.
- Témoins historiques vérifiables à leur instant signé sans accepter une
  clé désormais révoquée ; checkpoint mal formé refusé par erreur de domaine.
- Aucun ledger PF Fans, aucune altération des claims et lignes historiques.

## Limites et retour arrière

Le corpus est attesté **à l'instant de sa fence primaire**, pas pour une
durée de fraîcheur future. L'orchestration automatique bornée, B4/B5 et
les écrans B6 restent les lots suivants ; cette inbox ne publie ni score,
ni classement, ni route de membre, ni cron.

Les quotas historiques utilisent une horloge SQL accélérée uniquement
dans la recette ; les signatures utilisent l'horloge réelle. Les mesures
de verrous sont celles des petits corpus de recette, sans extrapolation
de dimensionnement. Aucun vrai SSO Me, achat, compte réel, TLS, panne
d'infrastructure, restauration/réplique ou politique de conservation
réelle n'est attesté. Aucun site, flag ou migration de production.

Retour arrière : aucune installation sur les sites ; destruction de
l'enclave de recette. Aucun effacement automatique ni durée de rétention
introduits ; #150 reste distincte.

Voir le [contrat](../../modules/HUB-PF-B3-RANKING-CORPUS.md) et le
[rapport expurgé](wordpress-checks.json). Seuls les intitulés des contrôles,
versions, mesures et totaux sont publiés : aucune clé, signature, requête,
identité ou fait détaillé.

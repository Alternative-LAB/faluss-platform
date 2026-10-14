# Vérification continue

## Durée et périmètre

La référence est le [workflow PHP quality 38531600985](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38531600985), réussi sur `ad602f7` avant la transition. Son job WordPress exécutait 28 recettes successives en **1 h 38 min 42 s**. Les recettes PF avancées réactivent leurs prérequis ; 7 195 assertions PF étaient donc exécutées pour 1 215 assertions distinctes.

La CI conserve les scripts applicatifs et de recette. Elle répartit les suites sur des runners isolés et conserve cinq parcours PF complets :

| Groupe parallèle | Options terminales | Assertions de référence |
| --- | --- | ---: |
| Projections | `--f1a` | 388 |
| Fin de session locale | `--b3-barriers --b3-barrier-completion` | 309 |
| Barrières HTTP et cycle de vie | `--b3-barrier-http --b3-barrier-completion-recovery --b3-barrier-acknowledgement --b1-origin-ack --b3-session-lifecycle` | 455 |
| Inbox classée | `--b3-ranked-inbox` | 419 |
| Corpus, projections et jonction | `--b3-corpus-reader --b5-sessions --b6-corpus-transaction --b6-primary-refresh --b6-joined-integration` | 727 |

Identity dispose d’un job dédié ; les six recettes d’administration Fans restent inchangées dans un autre job. Le parcours de fin de session locale conserve 37 assertions absentes des quatre autres parcours PF. La matrice ne mélange pas les transports qui exigent chacun un Fans jetable neuf.

L’objectif initial est un chemin critique de 15–20 minutes, proche de la recette terminale la plus longue, et non la somme des recettes. Les mesures de la PR font foi : capacité disponible des runners et téléchargements peuvent ajouter de l’attente.

## Couverture et garde-fous

[wordpress-assertions.json](../tests/Workflow/fixtures/wordpress-assertions.json) contient les **1 413 noms d’assertions uniques** des 25 rapports de la référence réussie, ainsi que les neuf rapports attendus. L’union des cinq rapports PF et des quatre rapports Fans contient déjà ces 1 413 assertions ; leurs exécutions passent de 7 393 à 2 496 dans les rapports conservés.

Le job `WordPress recipe coverage` télécharge les nouveaux rapports et appelle [validate-wordpress-coverage.py](../scripts/validate-wordpress-coverage.py). Un rapport manquant, invalide, incohérent, signalant un échec ou omettant une assertion de référence fait échouer le contrôle. Les doublons ne compensent pas une assertion absente. Les nouveaux tests peuvent ajouter des assertions sans modifier ce minimum.

Les noms décrivent les scénarios observables ; ils ne constituent pas une mesure de couverture de lignes. Toute suppression ou renommage intentionnel exige une justification et une référence validée pour actualiser ce contrat. Ne pas régénérer le fichier simplement pour faire passer une perte de couverture.

`WordPress integration tests` agrège Identity, l’administration Fans, la matrice PF et la couverture. Il est exécuté même si une dépendance échoue et refuse les résultats autres que `success`, y compris les jobs ignorés ou annulés. Les contrôles PHP, JavaScript, SQL, Sodium, archive et gouvernance sont conservés. Les modifications de gouvernance restent évaluées depuis leur base de confiance.

## Outils et exploitation

L’action locale [wordpress-runtime](../.github/actions/wordpress-runtime/action.yml) centralise PHP 8.3, Composer, MariaDB, WordPress 7.1.2 et WP-CLI 2.12.0, avec les empreintes de téléchargements existantes. Chaque recette conserve ses bases, clés de fixture et processus locaux ; aucun site de production n’est contacté. Un lanceur PHP local à la CI partage uniquement le bytecode des appels CLI dans le répertoire privé du job. Il vérifie les fichiers à chaque appel (`validate_timestamps=1`, `revalidate_freq=0`), exclut `wp-config.php` et respecte les options explicites désactivant OPcache dans les serveurs HTTP de fixture. Aucun état de requête ou cache n’est partagé entre jobs ni publié comme artefact.

Les cinq variantes PF peuvent tourner simultanément (`max-parallel: 5`). Leur budget est de 30 minutes ; Identity et l’administration Fans ont un budget de 10 minutes. Une expiration reste un échec, pas un résultat accepté. `fail-fast: false` permet aux autres variantes de produire leurs diagnostics.

Les rapports sont conservés sept jours dans des artefacts `wordpress-evidence-*`. Lorsqu’une même PR reçoit un nouveau commit, l’ancien workflow PHP est annulé par concurrence ; les exécutions de `main` restent sérialisées et ne sont pas annulées. Les protections distantes ne sont pas modifiées ; ajouter `WordPress integration tests` aux statuts requis lors d’une évolution autorisée de la protection.

Vérification locale rapide :

```bash
python3 -m unittest discover -s tests/Workflow -p 'test_*.py'
python3 scripts/validate-wordpress-coverage.py /chemin/rapports
```

En cas de régression, revenir à l’organisation précédente par une PR validée. Aucun code de module, contrat, schéma ou donnée persistante de production n’est changé.

## Mesure avant cache CLI

La [première exécution parallèle 38559059477](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38559059477) a réussi en **29 min 56 s**, contre 98 min 42 s pour la référence. Identity termine en 39 secondes et les 1 413 assertions sont conservées indépendamment. Le corpus reste le chemin critique (29 min 35 s). Le cache de bytecode CLI vise ce coût de démarrages répétés ; sa mesure complète est à confirmer avant livraison.

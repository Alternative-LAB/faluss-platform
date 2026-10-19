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

Dans le seul groupe `corpus-joined` sur runner GitHub hébergé, le workflow monte un `tmpfs` plafonné à **1 Gio** sur `/var/tmp`, avant la création des fixtures. Les mêmes WordPress, fichiers privés et bases MariaDB utilisent ainsi un stockage en mémoire ; les chemins d’enclave, répertoires privés `0700` et fichiers `0600` restent identiques. Le montage ne modifie pas les données sous-jacentes et disparaît avec le runner jetable. Il ne s’applique pas aux autres groupes ni aux runners auto-hébergés : les recettes d’administration Fans conservent plusieurs fixtures et nécessitent le stockage disque. Le temps réel et CPU de chaque recette PF apparaît dans ses logs grâce à `time`. Les outils téléchargés, le checkout et les rapports restent hors de ce montage.

Aucun réglage SQL de journalisation ou de synchronisation n’est assoupli. Les tests d’arrêt de workers, de perte de réponse, de rollback et de concurrence restent exécutés contre le serveur MariaDB réel. Ces recettes ne testent pas la survie à une panne du système ou du stockage ; le montage en mémoire n’apporte pas cette preuve. Une erreur de montage ou le dépassement du plafond fait échouer le job. Pour revenir au stockage disque, retirer l’étape `Keep disposable fixtures on bounded memory storage` par une PR validée.

Les rapports sont conservés sept jours dans des artefacts `wordpress-evidence-*`. Lorsqu’une même PR reçoit un nouveau commit, l’ancien workflow PHP est annulé par concurrence ; les exécutions de `main` restent sérialisées et ne sont pas annulées.

## Contrôles obligatoires et audit du 19 octobre 2026

La protection distante de `main` exige désormais sept statuts, tous liés à
GitHub Actions : `Check JavaScript syntax`, `Lint, static analysis and tests`,
`Federation without native Sodium`, `Faluss PR governance`,
`Fans editorial isolated SQL`, `WordPress integration tests` et
`Validate WordPress archive`. La branche doit rester à jour. Les protections
administrateur, l’interdiction de réécriture/suppression de `main` et le ruleset
sans acteur de contournement restent actifs. Aucun contrôle n’a été retiré.
Le statut WordPress agrège les recettes et leur couverture ; la gouvernance
provient toujours du workflow de la base de confiance. Un statut vert d’un
ancien commit ne suffit pas pour fusionner une branche actualisée.

Le lint PHP parcourt tous les fichiers PHP suivis par Git, avec des séparateurs
nuls pour préserver les chemins contenant des espaces. Il inclut les scripts et
exemples PHP de déploiement, tout en excluant les dépendances non suivies.
Le test [test_php_syntax.py](../tests/Workflow/test_php_syntax.py) injecte une
erreur dans cinq emplacements : l’ancien contrôle acceptait à tort trois de ces
emplacements, le nouveau les refuse tous. Les tests existants de rapports et
d’agrégation continuent de refuser les assertions absentes, compteurs incohérents,
rapports manquants et résultats échoués, ignorés ou annulés.

Les neuf rapports du [dernier main v0.14.0](https://github.com/Alternative-LAB/faluss-platform/actions/runs/39168550172)
ont été téléchargés et validés indépendamment : **1 874 assertions distinctes**,
**2 966 exécutions**, toutes les **1 413 assertions de référence** conservées.
Les douze jobs PHP sont réussis ; le corpus dure **26 min 49 s**, proche du
budget de 30 minutes et au-delà de l’objectif initial. Cette mesure n’est ni une
couverture de lignes, ni une preuve de fonctionnement de la production.

Limites restantes : PHPStan exclut explicitement une partie du code historique ;
aucun PHPCS/WPCS ni contrôle de couverture de lignes n’est configuré. Les tests
JavaScript comprennent le contrat Studio mais ne rejouent pas toute l’interface.
Les recettes utilisent WordPress 7.1.2 et PHP 8.3, sans matrice couvrant PHP 8.2
ou toutes les versions Elementor. La cause du refus intermittent corpus
[#217](https://github.com/Alternative-LAB/faluss-platform/issues/217) reste
inconnue malgré les passages verts. Les tests jetables n’attestent ni SMTP/SSO
réels, ni stockage privé RustFS, ni restauration après panne du système.

Si la préparation de la preuve d’absence retardée échoue, la recette journalise uniquement le statut HTTP, une raison technique `pf_*` autorisée et l’espace temporaire restant. Les requêtes, réponses signées, clés et identifiants privés ne sont jamais inclus. Le prérequis HTTP 200 reste obligatoire ; ce diagnostic ne constitue pas une reprise automatique ni la preuve qu’un refus intermittent est corrigé.

Vérification locale rapide :

```bash
python3 -m unittest discover -s tests/Workflow -p 'test_*.py'
python3 scripts/validate-wordpress-coverage.py /chemin/rapports
```

En cas de régression, revenir à l’organisation précédente par une PR validée. Aucun code de module, contrat, schéma ou donnée persistante de production n’est changé.

## Mesure avant cache CLI

La [première exécution parallèle 38559059477](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38559059477) a réussi en **29 min 56 s**, contre 98 min 42 s pour la référence. Identity termine en 39 secondes et les 1 413 assertions sont conservées indépendamment. Le corpus reste le chemin critique (29 min 35 s). Le cache de bytecode CLI vise ce coût de démarrages répétés.

La [seconde exécution 38566759801](https://github.com/Alternative-LAB/faluss-platform/actions/runs/38566759801), avec cache CLI, a réussi en **17 min 56 s**, dont **17 min 34 s** pour le corpus. Les 1 413 assertions sont encore présentes dans les neuf nouveaux rapports ; le nombre d’exécutions est toujours 2 496. Cette mesure respecte l’objectif initial sans modifier les scripts applicatifs ni les scénarios.

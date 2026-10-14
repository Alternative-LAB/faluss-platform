# Contribuer à Faluss Platform

Ce dépôt utilise exclusivement GitHub : `Alternative-LAB/faluss-platform`.
Les politiques de contribution sont communes aux personnes et aux agents ;
leur source unique est constituée des [règles GitHub](../.agents/rules/github.md)
et des garde-fous permanents d’[AGENTS.md](../AGENTS.md).

Pour démarrer ou reprendre une contribution, suivre la
[procédure issue, branche et PR](../.agents/skills/faluss-github-workflow/SKILL.md).
La [procédure de vérification](../.agents/skills/faluss-verify-change/SKILL.md)
indique les contrôles adaptés au périmètre. Les templates
[d’issue](ISSUE_TEMPLATE.md) et de [PR](../.github/pull_request_template.md)
restent les seules sources des sections à remplir.

## Préparation des releases

Le workflow `prepare-release.yml` recherche ou crée une issue de maintenance
avant de générer les fichiers, l'identifie par un titre stable contenant le SHA
complet de `main` et lie sa PR à cette issue. Une reprise réutilise l'issue ouverte ; les doublons ou une issue
fermée bloquent la reprise pour éviter une seconde publication. Une branche déjà
poussée est réutilisée seulement si ses fichiers de version et changelog
correspondent à ceux générés ; une PR ouverte existante est réutilisée.
Le workflow ne s'exécute que sur `main`. Les titres stables sont le contrat de
recherche des releases automatiques ; les issues manuelles restent recherchées
par l'agent avant de lancer le workflow.

[WORKFLOW_LABELS.json](WORKFLOW_LABELS.json) contient les correspondances approuvées
pour cette automatisation : `null` signifie qu'aucun label n'est configuré pour
cet axe, dont la valeur reste dans l'issue. Les labels distants sont relus à chaque
exécution ; un label configuré mais absent bloque le workflow. Mettre ce mapping
à jour si les conventions changent, sans inventer de label. Le jeton
`RELEASE_PR_TOKEN` doit permettre la lecture/création d'issues en plus des PR.

## Protection GitHub de `main`

Le workflow « PR governance » lit les règles depuis la branche de base avec
`pull_request_target` et traite le code proposé uniquement comme des données.
Une PR qui modifie le workflow ne peut donc pas affaiblir son propre contrôle.
La version proposée vérifie le préfixe et le numéro d’issue de la branche, la
référence à une issue ouverte dans la PR et la forme de la contribution ; ce
contrôle ne peut pas empêcher à lui seul un push direct. La protection GitHub est le verrou effectif.

Vérification distante du 14 octobre 2026 : la protection classique exige
`Check JavaScript syntax`, `Lint, static analysis and tests` et
`Federation without native Sodium`, avec branche à jour et application aux
administrateurs. Le ruleset impose une PR et interdit suppression/push forcé.
Les autres contrôles listés ci-dessous ne sont pas encore tous imposés par la
configuration distante. Les règles du dépôt exigent leur succès ; un
administrateur doit compléter la protection pour en faire un verrou effectif.
Cette modification documentaire ne change pas les paramètres distants.

Configurer une règle de protection ou un ruleset ciblant exactement `main` :

1. Exiger une pull request avant toute fusion, avec zéro approbation obligatoire.
   Exiger la résolution des conversations lorsqu'il y en a.
2. Exiger le statut de commit `Faluss PR governance` publié sur le dernier
   commit de la PR par GitHub Actions, ainsi que tous les contrôles applicables sur le commit à fusionner,
   notamment `Lint, static analysis and tests`, `Federation without native Sodium`,
   `Check JavaScript syntax`, `Fans editorial isolated SQL`,
   `Identity admin-post real WordPress` et `Validate WordPress archive`. Sélectionner GitHub
   Actions comme source attendue de chaque statut, jamais « n'importe quelle
   source ». Exiger une branche à jour avant fusion si la règle GitHub le permet.
3. Interdire les poussées forcées, la suppression de `main` et tout acteur de
   contournement, y compris les administrateurs, applications et jetons
   d'automatisation. Ne pas autoriser de poussée directe sur `main`.
4. Vérifier la protection après sa création avec une PR valide et un essai de
   push direct rejeté, sans modifier le contenu de `main`.

Le nom exact des contrôles doit être choisi parmi les statuts publiés par les
workflows du dépôt. Après modification de la protection, vérifier dans GitHub
qu'aucun rôle ni application ne figure dans la liste de contournement.

## Transition des conventions

Les commits acceptent les emojis Unicode (`📝`) et les codes `:gitmoji:`
(`:memo:`), suivis d’un message anglais. Le générateur de releases interprète
automatiquement les deux formats. Les titres de PR sont en anglais et les
descriptions restent en français.
Les branches déjà publiées ne sont pas réécrites sans autorisation explicite.

Le contrôle actuellement présent dans `main` exige encore un emoji Unicode.
Une PR proposant des codes courts peut donc rester bloquée par ce contrôle de
la base de confiance, même si ses tests locaux passent. La modification proposée
du workflow accepte les deux formats, mais ne constitue pas une validation de
cette même PR. La transition du contrôle doit être livrée séparément selon les
règles déjà actives, avant que les PR utilisant des codes courts puissent être
fusionnées. Ne pas désactiver le
contrôle ni publier artificiellement un statut favorable.

## Retour arrière

Revenir aux instructions ou à l’automatisation précédentes par une nouvelle PR
validée. Ces changements ne modifient pas les données, les modules ni la
configuration de production. Après toute mise à jour du mapping de labels,
vérifier à nouveau les permissions et les labels distants avant une release.

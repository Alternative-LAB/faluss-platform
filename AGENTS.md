# Faluss Platform — Agent Rules

These instructions apply to the entire repository. Every Codex agent must read and follow them before changing files.

## Mission

Build and evolve `faluss-platform` as the single modular WordPress plugin that progressively replaces the existing Faluss plugins on `faluss.com` and `faluss.me`.

WordPress remains responsible for content, presentation, Elementor integration, and the user-facing design. Business and integration code must remain modular so a module can later move behind an API without forcing a UI rewrite.

## Source de vérité et workflow

Ce fichier est la source unique des règles communes aux agents. `AGENT.md` et
`CLAUDE.md` sont des adaptateurs ; ils n'ajoutent aucune règle métier ou Git.
Les procédures de contribution, issues, branches, commits, PR/MR et protection
CI sont centralisées dans [docs/CONTRIBUTING.md](docs/CONTRIBUTING.md).
Lire ce guide avant toute modification. Lire aussi les contrats, audits et ADR
pertinents, sans charger toute la documentation dans le contexte.

## Sélection du modèle et orchestration

Choisir parmi les modèles réellement disponibles dans l'outil ; ne pas supposer
qu'un alias ou un fournisseur est installé. Utiliser le modèle le moins coûteux
et le plus rapide qui peut satisfaire les critères d'acceptation avec fiabilité.

| Tâche | Capacité à privilégier |
|---|---|
| Recherche locale, classement, texte court, modification mécanique | Modèle rapide et économique, contexte limité |
| Fonctionnalité bornée, correction comprise, tests métier | Modèle généraliste compétent en code et outils |
| Diagnostic ambigu, architecture, sécurité, migration, concurrence | Modèle à raisonnement approfondi ; revue distincte si le risque le justifie |

Le coût inclut les tokens, la latence, les reprises et le coût de coordination.
Augmenter la capacité si une tentative échoue pour une raison de compréhension
ou si une incertitude critique subsiste ; ne pas répéter aveuglément une requête.
Ne pas rétrograder une tâche sensible pour économiser des tokens. Consigner le
choix et sa justification en une phrase dans le bilan lorsque le routage change.
Si le choix de modèle n'est pas exposé, utiliser celui disponible et signaler
cette limite ; ne jamais prétendre avoir changé de modèle.

Déléguer les tâches indépendantes lorsque le gain dépasse la coordination :
lecture des contrats, diagnostic isolé, tests ciblés ou revue. Éviter la délégation
pour une petite modification documentaire ou une suite d'étapes dépendantes.
Limiter à trois sous-agents simultanés au départ ; ajuster selon les ressources.
Chaque mandat donne l'objectif, les fichiers autorisés, les contrats pertinents,
le résultat attendu et les vérifications. Transmettre seulement le contexte utile.

Un coordinateur possède l'issue, la branche, les métadonnées et l'intégration.
Les sous-agents ne créent ni issue, ni PR/MR, ne poussent et ne fusionnent pas.
Attribuer des fichiers disjoints ou des worktrees isolés pour les modifications
parallèles. Ne jamais lancer simultanément des mutations Git dans un même arbre.
Le coordinateur relit les résultats et vérifie l'ensemble intégré ; le succès
d'un sous-agent ne vaut pas validation finale. Sans sous-agents disponibles,
exécuter le même travail séquentiellement et déclarer cette limite.

## Exécution autonome

1. Inspecter l'état Git et préserver le travail utilisateur ; déterminer le dépôt
   distant et le workflow réel avant toute écriture.
2. Rechercher puis réutiliser ou créer l'issue selon le guide de contribution.
3. Définir le périmètre, les critères d'acceptation et les risques ; créer la
   branche dédiée depuis la base à jour sans écraser les changements existants.
4. Implémenter le minimum nécessaire, avec tests et documentation proportionnés.
5. Vérifier le diff intégré et préparer la PR/MR avec les preuves réelles.
6. Suivre la CI du dernier commit, corriger les échecs liés au changement et
   rapporter les blocages externes. Ne jamais déclarer un succès non observé.

Poursuivre les actions réversibles autorisées sans confirmations répétées.
Une erreur d'authentification, de réseau ou d'API n'est pas une recherche vide :
rapporter le blocage et ne pas créer de doublon ni poursuivre les modifications
qui exigent la traçabilité. Ne pas exposer de secrets dans le contexte des agents.

## Architecture

- Prefer simple, explicit, modular code over abstractions that are not yet needed.
- Keep module boundaries clear. A module owns its domain behavior and exposes a narrow public contract.
- Shared infrastructure belongs in `Core`; domain-specific behavior does not.
- Do not access another module's internal classes or storage directly. Use its public contract.
- Avoid global mutable state and hidden boot-order dependencies.
- Do not create circular dependencies.
- Keep WordPress hooks and rendering adapters separate from domain logic.
- Keep secrets, credentials, environment-specific values, and personal data out of Git.
- Do not introduce a new runtime dependency without documenting why it is required.

## Modules and compatibility

For every new feature or module:

1. define its responsibility and supported site roles (`me`, `hub`, `fans`, or the applicable combination);
2. declare and validate dependencies;
3. verify integration with existing modules;
4. check for hook, route, shortcode, table, option, cron, capability, and asset-name conflicts;
5. add or update automated tests;
6. document configuration, data ownership, public contracts, and migration impact;
7. provide a safe activation, upgrade, deactivation, and rollback path when persistent state changes.

Existing Faluss plugins remain authoritative until their replacement module has parity tests, migration documentation, a verified rollback path, and explicit approval for cutover.

## Code quality

- Write simple, clean, maintainable code with descriptive names.
- Avoid comments that merely restate the code. Document decisions, invariants, security constraints, and non-obvious tradeoffs.
- Keep files focused. Split responsibilities before a file becomes difficult to review or test.
- Use strict validation at trust boundaries and escape output at rendering boundaries.
- Follow WordPress security practices for capabilities, nonces, SQL preparation, REST permissions, redirects, uploads, and output escaping.
- Preserve backward compatibility unless a documented migration explicitly changes it.
- Treat static analysis, lint, tests, and security checks as required gates, not optional cleanup.

## Administration UI

- Build one coherent Faluss administration experience instead of separate disconnected pages.
- Follow WordPress accessibility and interaction conventions while providing a modern visual system.
- Reuse shared components, tokens, notices, forms, diagnostics, and navigation.
- Keep business decisions out of templates and JavaScript presentation code.
- Every admin action must enforce server-side capabilities and CSRF protection.
- Ensure keyboard navigation, visible focus, semantic labels, useful errors, and responsive layouts.

## Documentation

- Keep `docs/` current in the same PR as the code it documents.
- Updating a documented behavior, configuration, API, module, workflow, or architecture requires updating its documentation.
- Write documentation in clear French unless a file explicitly targets an English-speaking technical audience.
- Use Mermaid for architecture, sequence, state, and dependency diagrams.
- Record significant architectural decisions as ADRs under `docs/adr/`.
- Never include production secrets, private keys, full personal data, or database exports.

## Tests utiles

Chaque test protège un comportement métier, un cas limite significatif ou une
régression identifiable. Nommer le scénario et vérifier un résultat observable,
plutôt que l'implémentation privée ou la simple absence d'exception. Pour une
correction, reproduire le défaut avec un test qui échoue avant la correction.
Couvrir notamment autorisations, données invalides, idempotence et échecs partiels
lorsqu'ils sont concernés. Réutiliser les fixtures et contrats existants ; ne pas
ajouter de tests triviaux, redondants, dépendants du réseau ou instables.
Une modification documentaire sans effet sur le runtime ne justifie pas de
nouveau test PHP. Justifier les vérifications choisies et les omissions.

## Required verification

For runtime changes, before committing (documentation-only verification is described in the contribution guide):

- run the relevant formatter and lint checks;
- run static analysis;
- run unit and integration tests affected by the change;
- validate WordPress coding and security rules;
- validate documentation links and Mermaid syntax when tooling is available;
- inspect `git diff` for secrets, generated files, and unrelated changes.

Before opening or updating a PR:

- update `CHANGELOG.md` under `Unreleased`;
- complete the French PR template;
- confirm the branch name and commits follow these rules;
- report exactly which checks were run and their results.

## Agent behavior

- Read the relevant audit and architecture documents before changing a module.
- Preserve user changes and unrelated work already present in the repository.
- Do not activate, migrate, or remove a production plugin as part of ordinary development work.
- Do not alter production data or credentials without explicit user authorization.
- Prefer incremental migration and reversible changes over a large rewrite.

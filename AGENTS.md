# Faluss Platform — Agent Rules

These instructions apply to the entire repository. Every Codex agent must read and follow them before changing files.

## Mission

Build and evolve `faluss-platform` as the single modular WordPress plugin that progressively replaces the existing Faluss plugins on `faluss.com` and `faluss.me`.

WordPress remains responsible for content, presentation, Elementor integration, and the user-facing design. Business and integration code must remain modular so a module can later move behind an API without forcing a UI rewrite.

## Git workflow

- Never commit or push directly to `main`.
- Create a dedicated branch from an up-to-date `main`. For a sequence of small dependent PRs, branch from the preceding PR branch and explicitly target that branch; retarget to `main` as earlier PRs merge.
- Use explicit branch prefixes: `feat/`, `fix/`, `refactor/`, `docs/`, `chore/`, `test/`, `ci/`, `build/`, `perf/`, or `hotfix/`.
- Keep pull requests small, coherent, independently testable, and easy to review.
- Do not mix unrelated refactors, features, and formatting changes.
- Rebase or update a branch before requesting final review when `main` has moved.
- Never force-push a shared branch unless the user explicitly requests it.

## Commits

- Use the format: `<gitmoji> <English imperative message>`.
- An optional commit body is allowed, but it must also be written in English.
- Keep the subject concise and describe one logical change.
- Examples:
  - `📝 Add repository contribution guidelines`
  - `✨ Add module discovery registry`
  - `🐛 Prevent duplicate module registration`
  - `♻️ Extract API authentication service`

## Pull requests

- Every PR must use `.github/pull_request_template.md` without removing required sections.
- PR titles and descriptions must be written in French.
- Every PR must contain a clear changelog describing user-visible, technical, migration, and documentation changes as applicable.
- Use `Sans objet` for a required section that genuinely does not apply; never leave required sections empty.
- State the verification performed and any known limitations.
- Explicitly identify compatibility risks with `faluss.com`, `faluss.me`, WordPress, Elementor, existing Faluss modules, stored data, APIs, cron jobs, and deployment.

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

1. define its responsibility and supported site roles (`me`, `hub`, or both);
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

## Required verification

Before committing:

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

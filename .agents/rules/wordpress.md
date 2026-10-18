# WordPress and module rules

Read only the architecture, audit, module contract and ADR documents affected by
the change. Start with [ARCHITECTURE.md](../../docs/ARCHITECTURE.md) when changing
module boundaries. Permanent safety constraints are in [AGENTS.md](../../AGENTS.md).

## Architecture and quality

- Prefer explicit modular code and descriptive names over speculative abstractions.
  Split responsibilities before files become difficult to review or test.
- A module owns its domain and exposes a narrow public contract. Shared
  infrastructure belongs in `Core`; domain-specific behavior stays in its module.
- Access other modules through public contracts, never their internals or storage.
  Avoid circular dependencies, mutable globals and hidden boot-order assumptions.
- Separate WordPress hooks and rendering adapters from business logic.
- Preserve backward compatibility unless a documented migration changes it.
  Document the need for any new runtime dependency.
- Validate trust boundaries strictly and escape rendering output. Enforce
  capabilities, nonces, prepared SQL, REST permissions and safe handling of
  redirects, uploads and credentials.
- Comments explain decisions, invariants and security constraints, not obvious code.

## New features and modules

1. Define responsibility and supported roles (`me`, `hub`, `fans` or a combination).
2. Declare and validate dependencies and integration with existing modules.
3. Check conflicts in hooks, routes, shortcodes, tables, options, cron,
   capabilities and asset names.
4. Add or update meaningful automated tests and configuration/contract docs.
5. Document data ownership and migration impact. For persistent changes, provide
   safe activation, upgrade, deactivation and rollback paths.

Existing plugins remain authoritative until parity tests, migration docs,
verified rollback and explicit cutover approval are available.

## Administration UI

Build one coherent Faluss admin experience using shared tokens, components,
notices, forms, diagnostics and navigation. Follow WordPress accessibility and
interaction conventions: keyboard access, visible focus, semantic labels,
useful errors and responsive layouts. Every action enforces capabilities and
CSRF protection on the server. Keep business decisions out of templates and
presentation JavaScript.

## Tests and documentation

Protect observable business behavior, significant edge cases and identifiable
regressions. For fixes, reproduce the failure before changing the implementation.
Cover authorization, invalid input, idempotence and partial failures when relevant.
Reuse existing fixtures and contracts; avoid trivial, redundant or unstable tests.
Documentation-only changes do not require new runtime tests.

Update affected `docs/` in the same PR. Document changed behavior, configuration,
APIs, workflows and architecture. Use Mermaid for diagrams and record significant
decisions under `docs/adr/`. Follow the language policy in the root entrypoint.
Run the applicable gates from [faluss-verify-change](../skills/faluss-verify-change/SKILL.md).

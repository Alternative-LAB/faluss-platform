# GitHub contribution rules

GitHub is the only project platform: `Alternative-LAB/faluss-platform`.
Apply the permanent safeguards in [AGENTS.md](../../AGENTS.md).

## Traceability and branches

- Associate each change with an open GitHub issue, including documentation and
  automated releases. Resume the existing issue, branch and PR for the same scope.
- Use English branch names: `feat/<issue>-<name>`, `fix/<issue>-<name>` or
  `chore/<issue>-<name>` for maintenance, documentation, tests, refactoring and CI.
  Historical branch names are not rewritten automatically.
- Create new branches from up-to-date `origin/main` in a clean tree or isolated
  worktree. Keep PRs small, coherent and independently testable.
- For dependent PRs, branch from and target the preceding PR branch. Retarget
  to `main` after its merge. Update the branch before final review when the base
  moves; preserve published history unless rewriting is explicitly authorized.

## Commits

Use a gitmoji **Unicode emoji or shortcode**, followed by a concise English
imperative message. Both forms are interpreted by release tooling:

```text
:sparkles: Add module registry
:bug: Prevent duplicate route registration
:memo: Document the module lifecycle
📝 Document the module lifecycle
```

Optional commit bodies are also in English. Each commit represents one logical
change. Unicode emojis and shortcodes are equally valid for new and existing
commits. Do not rewrite shared history just to change its style.

## Pull requests

- Use an English title and a French description. Fill every required section of
  [.github/pull_request_template.md](../../.github/pull_request_template.md);
  write `Sans objet` only where genuinely inapplicable.
- Reference the branch's issue in the body. Use `Closes #N` only when delivery to
  the target actually completes it; dependent PRs must not close it prematurely.
- Update `CHANGELOG.md` under `Unreleased`, covering user-visible, technical,
  migration and documentation changes as applicable.
- Record actual checks, limitations and compatibility risks for `faluss.com`,
  `faluss.me`, WordPress, Elementor, Faluss modules, stored data, APIs, cron and
  deployment. Include screenshots for visual changes and a rollback procedure.
- Observe checks on the latest SHA. Cancelled, skipped or pending applicable
  checks do not establish success. Close the issue only after effective delivery.

Use [faluss-github-workflow](../skills/faluss-github-workflow/SKILL.md) for the
procedure and [faluss-verify-change](../skills/faluss-verify-change/SKILL.md) for
verification. [CONTRIBUTING.md](../../docs/CONTRIBUTING.md) documents release
automation and trusted remote protection, including policy-transition limits.

# Faluss Platform — Agent Instructions

Applies to the entire repository. Read this entrypoint once; load the relevant
rules and skills below only when their scope applies. Adapters contain no policy.

## Project

Build one modular WordPress plugin that progressively replaces the Faluss
plugins on `faluss.com` and `faluss.me`. WordPress owns content, presentation
and Elementor integration; isolate domain logic so it can later move behind an API.

## Always apply

- Preserve user changes and keep each change within the authorized scope.
- Never commit, push or merge locally into `main`. Merge through a GitHub PR
  only after all applicable checks pass; human review is optional.
- Never bypass checks, use an administrator override or weaken protection.
  Governance changes must be checked by the trusted base, never by themselves.
- Never force-push a shared branch without the user's explicit request.
- Keep secrets and personal data out of Git, tool output and agent context.
- Do not activate, migrate or remove production plugins, or change production
  data or credentials, without explicit authorization.
- Continue authorized reversible work. Report observed results and blockers;
  never claim checks passed without evidence.
- Write AI instructions, rules, skills and their supporting documentation in
  English. Product and other project documentation remains in French.

## Load by task

| Task | Read |
| --- | --- |
| Plan work, choose tools/models or consider delegation | [Orchestration rules](.agents/rules/orchestration.md) |
| Change files, commits, branches or PR metadata | [GitHub rules](.agents/rules/github.md) |
| Change plugin code, modules, contracts or admin UI | [WordPress rules](.agents/rules/wordpress.md), then only the affected contracts, audits and ADRs |
| Manage an issue, branch or PR | [faluss-github-workflow](.agents/skills/faluss-github-workflow/SKILL.md) |
| Verify a change before committing or publishing | [faluss-verify-change](.agents/skills/faluss-verify-change/SKILL.md) |

Canonical rules and skills live in `.agents/`. Claude skill entries under
`.claude/skills/` are symlinks to the same files. If skill discovery or imports
are unavailable, read the linked file directly. Avoid loading the entire tree.

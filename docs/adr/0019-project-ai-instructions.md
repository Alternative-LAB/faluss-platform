# 0019 — Load project AI guidance on demand

- **Status**: accepted
- **Date**: 2026-10-14
- **Scope**: issue #204 and PR #205

## Context

The previous entrypoint mixed languages, included an unused platform workflow
and loaded detailed procedures for every task. Maintaining copies for different
agents would increase both drift and initial context usage.

## Decision

Keep permanent safeguards and a task router in `AGENTS.md`. Store domain policies
in `.agents/rules/` and reusable procedures in two `.agents/skills/` packages.
Use English for all AI guidance. Compatibility entrypoints reference the root;
Claude skill directories are symlinks to the canonical packages. Load each body
only when its task applies. Keep human templates and release operations in `docs/`.

GitHub is the sole platform. Unicode gitmojis and shortcodes are equally valid;
release tooling interprets both automatically. English PR titles align the
instructions and automation with the requested conventions. Model and tool
selection uses available capabilities and task risk; delegation requires a
concrete benefit, with one coordinator responsible for integration.

## Consequences

Initial context is smaller without removing WordPress or production safeguards.
Rules remain discoverable by links even when automatic skill loading is absent.
Symlinks require a checkout that preserves them; otherwise read the canonical
skill paths from the root entrypoint. No runtime dependency or production
migration is introduced. Revert through a checked PR if necessary.

The trusted base still validates governance changes. Existing Unicode history
remains readable by release tooling; changing the base's commit validator needs
a separate compliant transition before shortcode PRs can merge.

## Alternatives

- Copying rules into each agent adapter duplicates policy and allows drift.
- Importing every rule at startup defeats selective loading.
- Provider-specific model aliases and a routing service add maintenance without
  improving this repository's current workflows.

## Discovery references

- [Codex local skills](https://learn.chatgpt.com/docs/build-skills): metadata is
  loaded first; repository skills are discovered under `.agents/skills/`.
- [Claude Code skills](https://code.claude.com/docs/en/skills): project skill
  folders support symlinks under `.claude/skills/`.

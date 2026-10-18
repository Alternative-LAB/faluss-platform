# Model, tool and context selection

Choose only models and tools actually exposed by the environment. Do not pin
provider aliases, prices or a model catalog in repository rules.

| Task | Capability |
| --- | --- |
| Local lookup, short text, mechanical edit | Fast, economical model with a small context |
| Bounded feature, understood bug, business tests | Coding model with reliable tool use |
| Ambiguous diagnosis, security, architecture, migration or concurrency | Strong reasoning; independent review when risk warrants it |

Use the least costly capable option, considering latency, retries and
coordination as well as tokens. Increase capability when misunderstanding or
critical uncertainty persists; do not lower it for sensitive work solely to
save tokens. If selection is unavailable, use the current model and do not
claim to have switched. Explain a routing change briefly in the result.

## Tools and context

- Prefer `rg` and bounded local reads for repository evidence, the authenticated
  GitHub CLI/API for remote state, and existing project commands for validation.
- Batch independent reads; keep dependent operations and Git mutations sequential.
- Browse official sources when external behavior is uncertain or time-sensitive.
  Do not add a dependency when an available tool meets the need.
- Load skill metadata for discovery, then only the relevant skill body and
  references. Do not preload all rules, docs, modules, logs or skills.
- Reuse findings, request targeted outputs and summarize evidence by path and
  result. Avoid repeated full diffs and unlimited command output.

## Delegation

Use subagents only when independent work saves more time or improves confidence
than its coordination costs. Handle small edits and dependent steps sequentially.
Bound concurrency to the available slots and independent tasks.

Give each agent an objective, file ownership, relevant contracts, expected result
and checks with only the necessary context. Use disjoint files or isolated
worktrees; agents must preserve each other's work. One coordinator owns issue/PR
metadata, Git mutations, integration and publication. Review delegated results
and verify the integrated change; a subagent's success is not final evidence.

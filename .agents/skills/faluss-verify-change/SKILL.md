---
name: faluss-verify-change
description: Verify Faluss changes before committing, pushing or reporting completion; select checks from the affected behavior.
---

# Verify a change

Read [GitHub rules](../../rules/github.md). Inspect the integrated diff against
the PR base, including existing PR changes, and select checks from the repository's
actual CI configuration. Do not add a formatter just to fill a checklist.

| Change | Checks |
| --- | --- |
| All changes | `git diff --check`; review scope, secrets, generated files and commit/branch/PR conventions |
| Documentation or instructions | Local links, imports/symlinks, rule consistency, skill frontmatter and triggers; Mermaid syntax when tooling is available |
| GitHub workflows | YAML parsing, `bash -n` on shell steps, `actionlint` if available, affected workflow tests |
| PHP or release scripts | `composer validate --strict`, affected `php -l`, `composer analyse`, relevant `composer test` tests, `composer audit --locked` |
| JavaScript | CI's `node --check` and affected JS tests |
| Packaging | `python3 -m unittest discover -s tests/Release -p 'test_*.py'`; build with production dependencies using `bash scripts/build-release.sh <temporary-archive>` |
| WordPress, SQL or historical contracts | Affected recipes/contracts from `.github/workflows/php-quality.yml` in isolated environments |

Workflow tests run with:

```bash
python3 -m unittest discover -s tests/Workflow -p 'test_*.py'
```

Use tests that exercise meaningful behavior. Record expected failures before a
fix and rerun afterward. Do not claim a local candidate-workflow run establishes
trusted governance: the PR's base supplies that control.

Before committing, inspect the complete diff for unauthorized changes, secrets,
debugging residue and generated files. Confirm required local gates passed;
explain omissions and unavailable tools. Verify WordPress security boundaries
for affected runtime code; documentation-only work needs no new PHP tests.

Before publishing, fetch the base, resolve relevant divergence, confirm the
changelog/template and preserve shared history. After pushing, observe applicable
CI checks on the latest SHA. Report commands, outcomes, limitations and the real
PR state. A previously green SHA or an unobserved run is not evidence of success.

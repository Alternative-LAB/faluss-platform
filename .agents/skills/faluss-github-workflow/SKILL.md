---
name: faluss-github-workflow
description: Manage Faluss GitHub issues, branches and PRs when starting, resuming or publishing repository work.
---

# Manage repository work

Read [GitHub rules](../../rules/github.md) for policy. Use existing authenticated
GitHub tools; inspect installed command help when flags are uncertain.

1. Inspect `git status --short --branch`, the remote and the requested issue/PR.
   For a resumed PR, read its full diff, linked issue, reviews and check results;
   reuse its branch. Do not reopen the issue-creation workflow unnecessarily.
2. For new work, search open and closed issues by symptom, module and objective.
   Read candidates and inspect related branches/PRs. Paginate results when needed;
   API/authentication errors are not empty results and must not cause creation.
3. If no open issue fits, repeat the search before creating an issue in French
   from [ISSUE_TEMPLATE.md](../../../docs/ISSUE_TEMPLATE.md). Preserve its required
   sections and remove guidance comments. Read current repository labels, apply
   existing type/priority/status conventions and record absent axes in the body.
   Never invent labels or change priority without justification.
4. Prepare the scoped change on the issue branch. Keep Git operations sequential.
   Continue independent read-only work when remote access is blocked; report
   which mutations cannot proceed without traceability.
5. Load [faluss-verify-change](../faluss-verify-change/SKILL.md) before committing
   or pushing. Prepare the exact French PR body in a temporary file, then create
   or update the English-titled PR with the repository template and evidence.
6. Follow checks for the latest SHA, fix change-related failures and report
   external blockers. Do not merge unless the trusted checks permit it.

Useful reads (set the explicit repository for remote commands):

```bash
git status --short --branch
git remote -v
gh issue list --repo Alternative-LAB/faluss-platform --state all --search '<keywords>'
gh issue view <number> --repo Alternative-LAB/faluss-platform
gh api --paginate 'repos/Alternative-LAB/faluss-platform/labels?per_page=100'
gh pr view <number> --repo Alternative-LAB/faluss-platform
gh pr checks <number> --repo Alternative-LAB/faluss-platform
```

For release preparation, read only the automation section in
[CONTRIBUTING.md](../../../docs/CONTRIBUTING.md); reuse its issue template and
label mapping rather than creating another policy or template.

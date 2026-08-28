---
name: pr-review-fix
description: Take an open PR (or a feature branch), review its diff against develop for problems, fix and commit the issues, then detect and resolve merge conflicts with develop and push the result. Use when the user says "check this PR", "review my PR and fix issues", "is there a problem with this PR", "fix the conflicts on this PR", "resolve merge conflicts", or "get this PR ready to merge". Base branch is develop.
---

# pr-review-fix — review → fix → resolve conflicts → push

End-to-end "make this PR mergeable" flow. Base branch = `develop`.
Two confirmation gates: before pushing fixes, and before resolving conflicts.

## 1. Identify the PR / branch

User gives a branch name, PR id, or PR URL. Determine the **source** branch and
confirm **dest = develop**.

```bash
git fetch origin --prune
git checkout <source-branch>
git pull --ff-only origin <source-branch>   # get latest of the PR branch
```

Never do this work on `develop` / `master` directly.

## 2. Review the diff for problems

Diff the PR against its base:

```bash
git diff origin/develop...HEAD --stat
git diff origin/develop...HEAD
```

Run a real review — delegate, don't eyeball:
- Prefer the **strativ-plugins:strativ-code-review** skill (or the
  `strativ-plugins:code-reviewer` agent) on this diff.
- Check against `.claude/rules/magento2.md` (DI, no ObjectManager in business
  logic, data patches not Install/Upgrade, escape output, no `around` where
  `before/after` fits, ACL on admin controllers, etc.).
- Magento-specific smells: new class/plugin/constructor without di:compile,
  db_schema without whitelist, N+1 in loops, unescaped template output,
  missing null-safety.

Produce a findings list: `file:line — problem — fix`, each marked
blocking / non-blocking.

## 3. Fix + commit

Apply fixes for blocking findings (and easy non-blocking ones the user approves).
Group into logical commits, Conventional Commits, each ending:

```
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
```

Stage explicit paths only — never `git add .` (repo carries unrelated dirty files).
After fixes that touch classes/plugins/schema, run the matching apply command from
[[magento-dev-flow]] §2 and **verify** per §3 (bootstrap script) — a fix that
isn't verified isn't done.

**Gate:** show the fixes, get confirmation before pushing.

## 4. Detect + resolve conflicts with develop

Check whether the branch merges cleanly:

```bash
git fetch origin develop
git merge --no-commit --no-ff origin/develop   # dry contact; conflicts surface
```

- Clean → `git merge --abort` (already tested) or keep the merge if the user wants
  develop merged in. Report "no conflicts".
- Conflicts → list conflicted files (`git diff --name-only --diff-filter=U`).
  For each: open it, understand BOTH sides (ours = PR, theirs = develop), resolve
  by **keeping both intents** — do not blindly take one side. Re-run any codegen
  (di:compile) if resolved files are classes/di/schema. Then:

```bash
git add <resolved-paths>
git commit    # completes the merge; keep the default merge message or a clear one
```

Prefer **merge** over rebase here (branch is already pushed / shared — rebase
rewrites history and needs force-push). Only rebase if the user explicitly asks and
the branch is solely theirs.

**Gate:** show each resolution before committing the merge.

## 5. Verify still green + push

- Re-run the relevant verification (bootstrap script, or render the PDF/email, or
  the negative case) after conflict resolution — merges can silently break logic.
- Push:

```bash
git push origin <source-branch>
```

## 6. Report

Summarise: problems found + fixed, conflicts resolved (file → how), verification
result, and the PR link:

```
https://bitbucket.org/carpet-avenue/project_magento-carpetavenue/pull-requests/new?source=<source-branch>&dest=develop
```

(or the existing PR if it already exists).

## Guardrails

- Base is always `develop`. Never commit on protected branches.
- Explicit staging only; never blanket-add.
- Confirm before push (step 3) and before committing conflict resolutions (step 4).
- A fix or a conflict resolution is not done until re-verified.
- Remove any debug probes / throwaway `pub/*.php` before pushing.

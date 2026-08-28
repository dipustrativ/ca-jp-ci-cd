---
name: ship-pr
description: Guided commit + push + pull-request flow for this repo. Creates a new feature branch first, shows every changed file for the user to review and exclude any, commits only the confirmed files with a proper Conventional Commit message, pushes, and produces the Bitbucket PR link targeting develop. Use when the user says "ship this", "commit and open a PR", "create a PR", "push and raise a PR", "commit my changes", or "open a pull request to develop".
---

# ship-pr — branch → review → commit → push → PR

Interactive shipping flow. NEVER commit straight onto `develop` or `master`.
Do the steps in order; stop at each confirmation gate.

## 1. Inspect

```bash
git rev-parse --abbrev-ref HEAD      # current branch
git status --short                   # all changes (M / ?? )
```

Group the changes into **related** (part of this task) vs **unrelated / pre-existing**
dirty files (e.g. `../.gitignore`, `developer/*`). Only related files ship.

## 2. Branch first

If already on a task feature branch, ask whether to reuse it or cut a new one.
If on `develop` / `master`, you MUST create a new branch.

- Derive a name from the work: `feature/<short-kebab>` (fix → `fix/…`, chore → `chore/…`).
- Confirm the name with the user, then:

```bash
git checkout -b feature/<name>
```

Create the branch BEFORE staging/committing.

## 3. Show files + let user exclude

Present the proposed file list (relative paths), split New vs Modified, and the
excluded/unrelated ones separately. Then ask — via AskUserQuestion or plainly:

> Commit these N files? Anything to exclude?

Wait for the answer. Remove any file the user names from the list. Do not proceed
until confirmed.

## 4. Stage only confirmed files

Explicit paths only — NEVER `git add .` / `git add -A` (repo carries unrelated
dirty files).

```bash
git add <path1> <path2> ...
git diff --cached --name-only          # echo back exactly what is staged
git status --short | grep -vE '^[MA] '  # prove the excluded ones stayed out
```

## 5. Commit (Conventional Commits, SEP-011)

`type(scope): summary` — subject imperative, ≤ ~72 chars. Body = what + why.
End every commit message with:

```
Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
```

```bash
git commit -m "$(cat <<'EOF'
feat(module): short summary

- what changed and why
...

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

## 6. Push

```bash
git push -u origin feature/<name>
```

## 7. Open PR against develop

Remote is **Bitbucket** — no `gh` CLI. Give the ready link (base = `develop`):

```
https://bitbucket.org/carpet-avenue/project_magento-carpetavenue/pull-requests/new?source=feature/<name>&dest=develop
```

Also output a ready-to-paste **title** and **description**:
- Title = the commit subject.
- Description sections: **What**, **Changes**, **Notes**, **Post-Deploy**
  (for Magento: `setup:upgrade`, `setup:di:compile`, `cache:flush`, any data steps),
  **Test**. End with:
  `🤖 Generated with [Claude Code](https://claude.com/claude-code)`

If a Bitbucket app-password / API token is available, offer to create the PR via
the REST API (`POST /2.0/repositories/carpet-avenue/project_magento-carpetavenue/pullrequests`,
`source.branch.name`, `destination.branch.name=develop`); otherwise hand over the link.

## Guardrails

- Confirmation gates at step 3 (file list) and the branch name — do not skip.
- Never blanket-add. Never commit on protected branches.
- Remove debug probes / throwaway scripts before committing (see [[magento-dev-flow]] §Cleanup).

---
description: Regenerate Docsify documentation for sections affected by changes since the last release tag. Creates a docs/release-<tag> branch with the updates for review.
argument-hint: "[--from <tag>] [--tag <new-tag>] [--dry-run]"
---

# /release-docs — Pre-release documentation sync

You are preparing this repository for an internal release. Your job: regenerate **only the documentation sections affected by code changes** since the previous release tag, on a separate branch, so the releaser can review the diff before merging.

## Inputs

Parse `$ARGUMENTS` for these optional flags:

- `--from <tag>` — explicit previous tag to diff from. If omitted, auto-detect (see Step 2).
- `--tag <new-tag>` — the tag being prepared (used for branch name + commit message). If omitted, ask the user.
- `--dry-run` — do everything *except* create the branch and commit. Just show what would change.

## Workflow

Follow these steps in order. Stop immediately and surface any failure — do not silently work around it.

### Step 1 — Verify clean working tree

```bash
git status --porcelain
```

If output is non-empty, abort and tell the user: *"Working tree has uncommitted changes. Stash or commit them before running /release-docs."* Never proceed with a dirty tree — doc-regeneration must be the only thing in the resulting commit.

Also confirm we're on `trunk` (or a release branch). If on a feature branch, ask the user to confirm before continuing.

### Step 2 — Determine the diff base (previous release tag)

If `--from <tag>` was provided, use it. Otherwise run:

```bash
PREV_TAG=$(git describe --tags --abbrev=0 2>/dev/null)
if [ -n "$PREV_TAG" ] && [ "$(git rev-parse "$PREV_TAG")" = "$(git rev-parse HEAD)" ]; then
  PREV_TAG=$(git describe --tags --abbrev=0 HEAD^ 2>/dev/null)
fi
echo "$PREV_TAG"
```

Rationale: tags in this repo may be deleted and recreated at the new HEAD during the pre-public-release window. If the most recent tag points at HEAD, we want the one *before* it. If no tag exists, abort and ask the user to specify `--from`.

Print the detected `PREV_TAG` and ask the user to confirm: *"Diff base: `<tag>` (commit `<sha>`, dated `<date>`). Generate docs from changes since this point?"* — wait for "yes".

### Step 3 — Determine the new tag

If `--tag` was provided, use it. Otherwise ask: *"What tag are you preparing? (e.g. v4.7.3)"* — keep it as a plain string; do not create the git tag.

### Step 4 — Load config

Read `.claude/release-docs.json`. Validate it parses; abort with a clear error if not. Hold onto:

- `docs_root` (expected: `docs/`)
- `sections` map (section name → array of glob patterns)
- `ignore` array
- `branch_template`, `commit_message_template`

### Step 5 — Compute changed files

```bash
git diff --name-only "$PREV_TAG"..HEAD
```

Filter out paths matching any glob in `ignore`. Print the filtered list to the user.

If the filtered list is empty, tell the user *"No code changes since `<prev_tag>` affect documented surfaces. Nothing to regenerate."* and exit.

### Step 6 — Map changed files to affected sections

For each section in `sections`, check whether any changed file matches any of its globs. Build a set of **affected sections**.

Print a summary table:

```
Affected docs sections:
  - rest-api      (3 files changed: Models/REST_API/Foo.php, …)
  - features      (1 file changed: Models/Edit_Coupon.php)
```

If no sections are affected (changed files all fall outside the mapping), tell the user *"Changes detected but none map to a documented section. Consider expanding `.claude/release-docs.json`."* and list the changed files. Do not proceed.

Ask the user to confirm: *"Regenerate docs for these <N> sections?"* — wait for "yes".

### Step 7 — Regenerate each affected section

For each affected section, in order:

1. Announce: *"Regenerating section: `<section>`"*
2. Invoke the **wpdev:create-docs** skill, scoped to the changed files in this section. Pass the changed file list as the documentation target — let the skill figure out the doc type (REST endpoint, hook, feature, etc.) from each file.
3. The skill writes into `docs/<section>/` (or wherever its discovery determines). Do not let it create new top-level doc folders; if it tries to write outside `docs/<section>/`, intercept and re-route, or ask the user.
4. After the skill returns, run Prettier on every modified `.md` file it touched, per the project's global CLAUDE.md rule:

   ```bash
   npx prettier --write --config .prettierrc <files>
   ```

If the create-docs skill fails for a section, capture the error, continue with the remaining sections, and surface a summary of failures at the end. Do not abort the whole run on a single section failure.

### Step 8 — Show the diff

```bash
git diff --stat docs/
git diff docs/
```

Present this to the user. Be ready to discuss specific edits. **Do not commit yet.**

Ask: *"Doc changes look right? Commit to a release branch?"* — wait for explicit "yes".

If the user wants tweaks, make them, re-run Prettier, show diff again.

### Step 9 — Create the release branch and commit

Skip this step entirely if `--dry-run` was passed; instead print *"Dry run complete. Doc changes left unstaged in working tree."* and exit.

Otherwise:

```bash
BRANCH=$(echo "$BRANCH_TEMPLATE" | sed "s/{tag}/$NEW_TAG/")
git checkout -b "$BRANCH"
git add docs/
```

Build the commit message from `commit_message_template`, substituting `{tag}`, `{prev_tag}`, and `{sections}` (a `- section-name` list). Use a HEREDOC so the multi-line message lands intact:

```bash
git commit -m "$(cat <<'EOF'
Docs: Update documentation for <tag>

Regenerated docs for sections affected by changes since <prev_tag>:
- section-a
- section-b
EOF
)"
```

### Step 10 — Hand off

Do **not** push. Do **not** open a PR. Print:

```
Doc updates committed to: <branch>
Diff base: <prev_tag>
Sections regenerated: <list>

Next steps:
  git push -u origin <branch>
  Then open a PR against trunk targeting this release.
```

That's the end of the command.

## Guardrails

- **Never** push, force-push, or open a PR autonomously.
- **Never** commit anything outside `docs/`. If the create-docs skill modifies code, abort and report.
- **Never** delete or recreate git tags. This command only reads tags; tagging is the releaser's job.
- **Never** run with a dirty working tree (Step 1 enforces this).
- **Always** show the diff and get explicit approval before committing (Step 8).
- **Always** run Prettier on touched markdown (Step 7), per the project's global rule in `.claude/CLAUDE.md`.
- **Per-section failures are non-fatal** — keep going and report at the end.

## When to extend

If you find changed files that don't map to any section, that's a signal `.claude/release-docs.json` is out of date. Tell the user, list the unmapped files, and suggest which section to add them to — but don't edit the config yourself unless asked.

#!/usr/bin/env bash
# review-score.sh — Step 0 of /review.
# Scores everything changed since the current branch diverged from `main` — commits on
# the branch plus staged, unstaged, and untracked work in the working tree — so /review
# can pick "normal" vs "enhanced" depth without manual judgment, and prepare-merge can
# pick the pre-merge tier (docs/development/git-workflow.md §6).
# No state file, no AI calls: pure local git commands.
#
# Output ends with two machine-read lines, in this order (/review parses the last one):
#   MERGE_CHECK=light|recommended|required
#   RECOMMENDATION=normal|enhanced
#
# Environment:
#   REVIEW_SCORE_BASE_BRANCH      base branch (default: main; falls back to origin/<base>)
#   REVIEW_SCORE_THRESHOLD        score at or above which the enhanced level / required tier applies (default: 30)
#   REVIEW_SCORE_LIGHT_THRESHOLD  score below which the light tier applies (default: 10)
set -euo pipefail

BASE_BRANCH="${REVIEW_SCORE_BASE_BRANCH:-main}"
THRESHOLD="${REVIEW_SCORE_THRESHOLD:-30}"
LIGHT_THRESHOLD="${REVIEW_SCORE_LIGHT_THRESHOLD:-10}"

FILE_WEIGHT=1
LINE_WEIGHT=0.05
SENSITIVE_WEIGHT=15

# Adjust to the project's own sensitive areas (migrations, authz, payments, do-not-touch, etc.)
SENSITIVE_PATTERNS=(
  'database/migrations/'
  'app/Policies/'
  'app/Http/Middleware/'
  'config/auth\.php'
  'app/Http/Controllers/Auth/'
  'docs/ai-context/do-not-touch\.md'
)

# Generated / third-party files that would inflate the score without being reviewed.
# public/js/ and resources/views/vendor/ are deliberately not excluded: both are hand-edited
# in Laravel projects (plain-Blade scripts, published package views).
EXCLUDE_PATHSPECS=(
  ':(exclude,glob)**/composer.lock'
  ':(exclude,glob)**/package-lock.json'
  ':(exclude,glob)**/yarn.lock'
  ':(exclude,glob)**/pnpm-lock.yaml'
  ':(exclude,glob)public/build/**'
  ':(exclude,glob)vendor/**'
  ':(exclude,glob)public/vendor/**'
  ':(exclude,glob)**/node_modules/**'
  ':(exclude,glob)**/*.min.js'
  ':(exclude,glob)**/*.min.css'
  ':(exclude,glob)**/*.map'
  ':(exclude,glob)**/*.woff'
  ':(exclude,glob)**/*.woff2'
  ':(exclude,glob)**/*.ttf'
  ':(exclude,glob)**/*.otf'
  ':(exclude,glob)**/*.eot'
)

skip() {
  echo "[review-score] $1; skipping score calculation."
  # Fail safe: an unmeasured branch may contain migrations or authz changes.
  echo "MERGE_CHECK=required"
  echo "RECOMMENDATION=normal"
  exit 0
}

TOPLEVEL=$(git rev-parse --show-toplevel 2>/dev/null) || skip "not a git repository"
# Paths below are repo-relative; running from a subdirectory would otherwise miss them.
cd "$TOPLEVEL"

# CI and fresh clones often have only the remote-tracking branch.
if ! git rev-parse --verify --quiet "$BASE_BRANCH" >/dev/null; then
  if git rev-parse --verify --quiet "origin/$BASE_BRANCH" >/dev/null; then
    BASE_BRANCH="origin/$BASE_BRANCH"
  else
    skip "base branch '$BASE_BRANCH' not found"
  fi
fi

# Unrelated histories and some shallow clones have no merge base.
MERGE_BASE=$(git merge-base "$BASE_BRANCH" HEAD 2>/dev/null || true)
[ -n "$MERGE_BASE" ] || skip "no merge base with '$BASE_BRANCH'"

# Diff the merge base against the working tree (not HEAD): /tdd leaves its changes
# uncommitted, so a commits-only range would score the usual flow as zero.
# Binary files (numstat reports "-") are left out of both counts.
TRACKED_BINARY=$(git -c core.quotePath=false diff --numstat --no-renames "$MERGE_BASE" -- . "${EXCLUDE_PATHSPECS[@]}" | awk -F'\t' '$1 == "-" {print $3}')
TRACKED_FILES=$(git -c core.quotePath=false diff --name-only "$MERGE_BASE" -- . "${EXCLUDE_PATHSPECS[@]}")
if [ -n "$TRACKED_BINARY" ]; then
  TRACKED_FILES=$(printf '%s\n' "$TRACKED_FILES" | grep -vxF -- "$TRACKED_BINARY" || true)
fi

read -r ADDED DELETED <<<"$(git diff --numstat "$MERGE_BASE" -- . "${EXCLUDE_PATHSPECS[@]}" | awk '{a+=$1; d+=$2} END {print a+0, d+0}')"

# Untracked files count as fully added. Only non-empty text files are counted: `grep -I`
# skips binaries, and directories (nested repos, worktrees) fail grep and drop out.
# NUL-separated throughout, so no filename is ever quoted or split.
list_untracked_text() {
  git ls-files -z --others --exclude-standard -- . "${EXCLUDE_PATHSPECS[@]}" \
    | xargs -0 -r grep -IlZ '' -- 2>/dev/null || true
}
UNTRACKED_FILES=$(list_untracked_text | tr '\0' '\n')
UNTRACKED_LINES=$(list_untracked_text | xargs -0 -r cat -- 2>/dev/null | wc -l | tr -d ' ')
ADDED=$((ADDED + UNTRACKED_LINES))
LINE_COUNT=$((ADDED + DELETED))

CHANGED_FILES=$(printf '%s\n%s\n' "$TRACKED_FILES" "$UNTRACKED_FILES" | sed '/^$/d')
FILE_COUNT=$(printf '%s' "$CHANGED_FILES" | awk 'END {print NR}')

# One grep over the whole list (a per-file loop took minutes for a few hundred files on Git Bash).
SENSITIVE_MATCHES=()
if [ -n "$CHANGED_FILES" ]; then
  SENSITIVE_REGEX=$(IFS='|'; echo "${SENSITIVE_PATTERNS[*]}")
  while IFS= read -r file; do
    [ -n "$file" ] && SENSITIVE_MATCHES+=("$file")
  done <<<"$(printf '%s\n' "$CHANGED_FILES" | grep -E -- "$SENSITIVE_REGEX" || true)"
fi
SENSITIVE_COUNT=${#SENSITIVE_MATCHES[@]}

SCORE=$(awk -v f="$FILE_COUNT" -v l="$LINE_COUNT" -v s="$SENSITIVE_COUNT" \
  -v fw="$FILE_WEIGHT" -v lw="$LINE_WEIGHT" -v sw="$SENSITIVE_WEIGHT" \
  'BEGIN { printf "%.0f", f*fw + l*lw + s*sw }')

echo "[review-score] base: $BASE_BRANCH (merge-base: ${MERGE_BASE:0:7})"
echo "  Files changed   : $FILE_COUNT"
echo "  Lines changed   : $LINE_COUNT (+$ADDED / -$DELETED)"
if [ "$SENSITIVE_COUNT" -gt 0 ]; then
  echo "  Sensitive paths matched:"
  for f in "${SENSITIVE_MATCHES[@]}"; do
    echo "    - $f"
  done
else
  echo "  Sensitive paths matched: none"
fi
echo "  Score           : $SCORE (light below: $LIGHT_THRESHOLD, enhanced/required from: $THRESHOLD)"
echo

# Pre-merge tier (docs/development/git-workflow.md §6): any sensitive path forces "required".
if [ "$SENSITIVE_COUNT" -gt 0 ] || [ "$SCORE" -ge "$THRESHOLD" ]; then
  MERGE_CHECK=required
elif [ "$SCORE" -ge "$LIGHT_THRESHOLD" ]; then
  MERGE_CHECK=recommended
else
  MERGE_CHECK=light
fi
echo "  >> Pre-merge check: $MERGE_CHECK"
echo "MERGE_CHECK=$MERGE_CHECK"

if [ "$SCORE" -ge "$THRESHOLD" ]; then
  echo "  >> Large/risky change detected. Run the enhanced review level (broader coverage + adversarial re-check)."
  echo "RECOMMENDATION=enhanced"
else
  echo "  >> Normal review level is sufficient."
  echo "RECOMMENDATION=normal"
fi

#!/usr/bin/env bash
# review-score.sh — Step 0 of /review.
# Scores the diff between the current branch's merge-base with `main` and HEAD,
# so /review can pick "normal" vs "enhanced" depth without manual judgment.
# No state file, no AI calls: pure local git commands.
set -euo pipefail

BASE_BRANCH="${REVIEW_SCORE_BASE_BRANCH:-main}"
THRESHOLD="${REVIEW_SCORE_THRESHOLD:-30}"

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

if ! git rev-parse --verify "$BASE_BRANCH" >/dev/null 2>&1; then
  echo "[review-score] base branch '$BASE_BRANCH' not found; skipping score calculation."
  echo "RECOMMENDATION=normal"
  exit 0
fi

MERGE_BASE=$(git merge-base "$BASE_BRANCH" HEAD)
RANGE="${MERGE_BASE}...HEAD"

CHANGED_FILES=$(git diff --name-only "$RANGE")
FILE_COUNT=$(git diff --name-only "$RANGE" | wc -l | tr -d ' ')

read -r ADDED DELETED <<<"$(git diff --numstat "$RANGE" | awk '{a+=$1; d+=$2} END {print a+0, d+0}')"
LINE_COUNT=$((ADDED + DELETED))

SENSITIVE_MATCHES=()
if [ -n "$CHANGED_FILES" ]; then
  while IFS= read -r file; do
    for pattern in "${SENSITIVE_PATTERNS[@]}"; do
      if echo "$file" | grep -qE "$pattern"; then
        SENSITIVE_MATCHES+=("$file")
        break
      fi
    done
  done <<<"$CHANGED_FILES"
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
echo "  Score           : $SCORE (threshold: $THRESHOLD)"
echo

if [ "$SCORE" -ge "$THRESHOLD" ]; then
  echo "  >> Large/risky change detected. Run the enhanced review level (broader coverage + adversarial re-check)."
  echo "RECOMMENDATION=enhanced"
else
  echo "  >> Normal review level is sufficient."
  echo "RECOMMENDATION=normal"
fi

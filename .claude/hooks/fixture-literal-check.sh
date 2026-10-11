#!/usr/bin/env bash
# fixture-literal-check.sh — did the implementer hard-code the tests' data in application code?
# Design: meta/design/fixture-literal-check.md (ADR-0019 outcome, prerequisite 1).
#
# After Green, /tdd runs this next to tdd-snapshot.sh. It prints every place where a line
# ADDED to application code (app/, routes/, config/, database/, resources/) compares against a
# quoted string that
#   - appears in the approved test files (the Gate 4 snapshot), and
#   - appears nowhere in the approved spec (docs/product/*.md in the snapshot), and
#   - did not exist in the application code before the cycle (git HEAD).
# A comparison or branch is ===, ==, !==, !=, case, match, in_array( or a where(/firstWhere( call.
# That is how "make the test pass" is faked: `if ($product->sku === 'SKU-A')`. The agent's own
# report is never read. Numbers are out of scope (too common in legitimate code).
#
# Added lines = tracked changes against HEAD plus every line of untracked new files. HEAD is
# taken as the cycle start: /tdd does not commit between Gate 4 and this check.
#
# Run by the main session after Green. Deterministic: bash + git + awk + grep, no AI.
# Exit code (meta/design/gate-contract.md): 0 = no hit, 2 = hit (printed), 3 = cannot run.
set -u

gitdir_path=$(git rev-parse --git-path claude-tdd 2>/dev/null) || {
  echo "fixture-literal-check: not inside a git repository" >&2; exit 3; }
SNAP="$gitdir_path/approved"
[ -f "$SNAP/.recorded-at" ] && [ -d "$SNAP/tests" ] || {
  echo "fixture-literal-check: no approved snapshot with tests/ — run tdd-snapshot.sh record at Gate 4 approval." >&2; exit 3; }
git rev-parse --verify -q HEAD >/dev/null || {
  echo "fixture-literal-check: the repository has no commit to compare against" >&2; exit 3; }

APP='app routes config database resources'
T=$(mktemp -d) || exit 3
trap 'rm -rf "$T"' EXIT

# Quoted strings of 3-60 characters, one per line, quotes removed.
lits() { tr -d '\r' | grep -oE "'[^']{3,60}'|\"[^\"]{3,60}\"" | sed -e "s/^.//" -e "s/.\$//" | sort -u; }

find "$SNAP/tests" -type f -name '*.php' -exec cat {} + 2>/dev/null | lits > "$T/test.lits"
[ -s "$T/test.lits" ] || exit 0
if [ -d "$SNAP/docs/product" ]; then find "$SNAP/docs/product" -type f -name '*.md' -exec cat {} + 2>/dev/null | tr -d '\r' > "$T/spec.txt"; else : > "$T/spec.txt"; fi
# shellcheck disable=SC2086
git grep -h -I -e . HEAD -- $APP 2>/dev/null | lits > "$T/base.lits"

# Candidate lines: file TAB line TAB literal, from added lines that compare against a quoted string.
CMP='(===|==|!==|!=)|(^|[^A-Za-z_])(case|match)[ (]|in_array\(|(->|::)(where|firstWhere)\('
awk_extract='
function scan(file, n, text,   s, q) {
  if (text !~ ENVIRON["CMP_RE"]) return
  s = text
  while (match(s, /\047[^\047]{3,60}\047|"[^"]{3,60}"/)) {
    q = substr(s, RSTART + 1, RLENGTH - 2); print file "\t" n "\t" q
    s = substr(s, RSTART + RLENGTH)
  }
}'
{
  # shellcheck disable=SC2086
  git diff HEAD -U0 --no-color -- $APP 2>/dev/null | tr -d '\r' | CMP_RE="$CMP" awk "$awk_extract"'
    /^\+\+\+ b\// { f = substr($0, 7); next }
    /^@@ / { match($0, /\+[0-9]+/); n = substr($0, RSTART + 1, RLENGTH - 1) + 0; next }
    /^\+/ && f != "" { scan(f, n, substr($0, 2)); n++ }'
  # shellcheck disable=SC2086
  git ls-files -o --exclude-standard -- $APP 2>/dev/null | while IFS= read -r f; do
    [ -f "$f" ] && tr -d '\r' < "$f" | CMP_RE="$CMP" awk -v F="$f" "$awk_extract"'{ scan(F, NR, $0) }'
  done
} > "$T/cand.tsv"

hits=0
while IFS=$'\t' read -r file line lit; do
  [ -n "$lit" ] || continue
  grep -qxF -- "$lit" "$T/test.lits" || continue
  grep -qF -- "$lit" "$T/spec.txt" && continue
  grep -qxF -- "$lit" "$T/base.lits" && continue
  tf=$(grep -rlF --include='*.php' -- "$lit" "$SNAP/tests" 2>/dev/null | head -1)
  tf=${tf#"$SNAP"/}
  [ "$hits" -eq 0 ] && echo "Application code compares against a value that appears in the approved tests, not in the approved spec and not in the earlier code:"
  hits=$((hits + 1))
  echo "FIXTURE_LITERAL $file:$line '$lit' also appears in ${tf:-the approved tests}"
done < "$T/cand.tsv"

[ "$hits" -eq 0 ] && exit 0
exit 2

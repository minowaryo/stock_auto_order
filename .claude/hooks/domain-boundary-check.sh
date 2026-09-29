#!/usr/bin/env bash
# domain-boundary-check.sh — runs in Step 0 of /review, next to review-score.sh.
#
# Flags Controller code that crosses the Domain Boundary defined in
# `.claude/rules/10-laravel.md` (Controllers must not persist, authorize, or decide).
# Deterministic: pure git + awk, no AI calls. A single awk pass over the Controllers
# in scope, so cost stays flat as a project grows.
#
# Reports three things:
#   - violations  : db-access / eloquent-write / role-check, per line
#   - priority    : files that write data, guard it with hand-written role checks, and
#                   never call a Policy — read these first; a *missing* object-level
#                   check cannot be pattern-matched, but this says where to look
#   - heuristic   : methods carrying many branches, i.e. possibly deciding in the Controller
#
# Usage:
#   bash .claude/hooks/domain-boundary-check.sh             # Controllers changed on this branch (incl. uncommitted)
#   bash .claude/hooks/domain-boundary-check.sh --audit-all # every Controller (tracked + untracked)
#   bash .claude/hooks/domain-boundary-check.sh --stats     # counts only, for trend tracking
#
# Exit code: 1 when violations are found (so CI or a pre-commit hook can gate on it),
# 0 otherwise. Run it as its own command — chaining it with `&&` under `set -e` would
# treat "findings exist" as a fatal error. /review deliberately tolerates exit 1.
set -euo pipefail

BASE_BRANCH="${DOMAIN_BOUNDARY_BASE_BRANCH:-main}"
# Max branches (if/foreach/while/switch/match/&&/||) inside a single method before it is
# flagged. Measured per method, not per file: a resource Controller with one guard clause
# per action stays near 1, while a method making business decisions climbs fast.
DENSITY_THRESHOLD="${DOMAIN_BOUNDARY_DENSITY_THRESHOLD:-5}"
# Output caps, applied per file *per category* so one noisy category cannot hide another.
MAX_PER_FILE="${DOMAIN_BOUNDARY_MAX_PER_FILE:-5}"
MAX_TOTAL="${DOMAIN_BOUNDARY_MAX_TOTAL:-30}"

# Where this project keeps Controllers (POSIX ERE, matched against repo-relative paths).
# Modular / DDD layouts MUST add their own entry — otherwise this script silently scans
# nothing and reports "clean", which is worse than not running it. For example:
#   'modules/[^/]+/Http/Controllers/'
#   'src/[^/]+/Infrastructure/Http/'
CONTROLLER_PATHS=(
  'app/Http/Controllers/'
)

# Rule groups — set to 0 to disable one for this project.
# A small project that deliberately has no Service layer may want PERSISTENCE=0.
CHECK_PERSISTENCE="${DOMAIN_BOUNDARY_PERSISTENCE:-1}"
CHECK_ROLE="${DOMAIN_BOUNDARY_ROLE:-1}"
CHECK_DENSITY="${DOMAIN_BOUNDARY_DENSITY:-1}"

AUDIT_ALL=0
STATS_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --audit-all) AUDIT_ALL=1 ;;
    --stats)     STATS_ONLY=1 ;;
    -h|--help)   sed -n '2,16p' "$0"; exit 0 ;;
    *) echo "[domain-boundary] unknown option: $arg" >&2; exit 2 ;;
  esac
done

if ! TOPLEVEL=$(git rev-parse --show-toplevel 2>/dev/null); then
  echo "[domain-boundary] not a git repository; skipping."
  exit 0
fi
# Paths below are repo-relative; running from a subdirectory would otherwise scan nothing.
cd "$TOPLEVEL"

PATH_RE=$(IFS='|'; echo "${CONTROLLER_PATHS[*]}")

# core.quotePath=false keeps non-ASCII paths (e.g. Japanese file names) as raw UTF-8
# instead of C-quoted escapes, which would otherwise fail the -f test and vanish
# from the scan while the file count still looked plausible.
if [ "$AUDIT_ALL" -eq 1 ]; then
  SCOPE="all Controllers (tracked + untracked)"
  CANDIDATES=$(git -c core.quotePath=false ls-files --cached --others --exclude-standard -- '*.php' || true)
else
  # CI and fresh clones often have only the remote-tracking branch.
  if ! git rev-parse --verify --quiet "$BASE_BRANCH" >/dev/null; then
    if git rev-parse --verify --quiet "origin/$BASE_BRANCH" >/dev/null; then
      BASE_BRANCH="origin/$BASE_BRANCH"
    else
      echo "[domain-boundary] base branch '$BASE_BRANCH' not found; skipping."
      exit 0
    fi
  fi
  # Unrelated histories and some shallow clones have no merge base; that must be a
  # graceful skip, not an exit-1 that reads like "violations found".
  MERGE_BASE=$(git merge-base "$BASE_BRANCH" HEAD 2>/dev/null || true)
  if [ -z "$MERGE_BASE" ]; then
    echo "[domain-boundary] no merge base with '$BASE_BRANCH'; skipping."
    exit 0
  fi
  SCOPE="changed since ${BASE_BRANCH} (merge-base: ${MERGE_BASE:0:7}, incl. uncommitted + untracked)"
  # Diff the merge base against the working tree (not HEAD): /tdd leaves its changes
  # uncommitted, so a commits-only range would miss exactly the code under review.
  CANDIDATES=$( {
    git -c core.quotePath=false diff --name-only "$MERGE_BASE" -- '*.php'
    git -c core.quotePath=false ls-files --others --exclude-standard -- '*.php'
  } || true)
fi

# Filter the whole candidate list with ONE grep. Doing this per file spawns a process
# per candidate, which costs ~15s for 300 files on Windows/Git Bash.
FILES=()
if [ -n "$CANDIDATES" ]; then
  MATCHED=$(printf '%s\n' "$CANDIDATES" | grep -E "$PATH_RE" || true)
  if [ -n "$MATCHED" ]; then
    while IFS= read -r f; do
      [ -n "$f" ] || continue
      [ -f "$f" ] || continue   # builtin test, no subprocess; skips files deleted in this diff
      FILES+=("$f")
    done <<<"$MATCHED"
  fi
fi

echo "[domain-boundary] scope: $SCOPE"
echo "  Controllers scanned: ${#FILES[@]}"

if [ ${#FILES[@]} -eq 0 ]; then
  echo "  No Controller files in scope — nothing to check."
  echo "  (If this project keeps Controllers outside '${CONTROLLER_PATHS[*]}', add the path to CONTROLLER_PATHS in this script.)"
  echo "VIOLATIONS=0"
  echo "HEURISTICS=0"
  echo "PRIORITY=0"
  exit 0
fi

REPORT=$(printf '%s\n' "${FILES[@]}" | awk \
  -v WRITES='(save|fill|update|updateOrCreate|firstOrCreate|create|insert|upsert|delete|forceDelete|restore|increment|decrement|attach|detach|sync|associate)' \
  -v density_threshold="$DENSITY_THRESHOLD" \
  -v max_per_file="$MAX_PER_FILE" \
  -v max_total="$MAX_TOTAL" \
  -v check_persistence="$CHECK_PERSISTENCE" \
  -v check_role="$CHECK_ROLE" \
  -v check_density="$CHECK_DENSITY" '
  function flush_method() {
    if (cur_method != "" && cur_branches > peak_branches) {
      peak_branches = cur_branches
      peak_method   = cur_method
    }
  }
  function report(cat, snippet,    key) {
    key = filename SUBSEP cat
    shown[key]++
    violations++
    if (shown[key] > max_per_file || violations > max_total) { suppressed++; return }
    if (!header_printed) { printf "\n  %s\n", filename; header_printed = 1 }
    sub(/^[[:space:]]+/, "", snippet)
    if (length(snippet) > 84) snippet = substr(snippet, 1, 81) "..."
    printf "    L%-5d %-15s %s\n", lineno, cat, snippet
  }

  # ---- driver: the file list arrives on stdin; each file is read with getline ----
  {
    filename = $0
    header_printed = 0
    lineno = 0
    cur_method = ""; cur_branches = 0; peak_branches = 0; peak_method = ""
    n_authz = 0; n_role = 0; n_write = 0

    while ((rc = (getline line < filename)) > 0) {
      lineno++
      sub(/\r$/, "", line)

      # Comments and imports are not executable code.
      if (line ~ /^[[:space:]]*(\/\/|#|\*|\/\*)/) continue
      if (line ~ /^[[:space:]]*use[[:space:]]/)   continue

      if (check_density == 1) {
        # Named methods reset the counter; closures (function without a name) do not.
        if (line ~ /(^|[^[:alnum:]_])function[[:space:]]+[A-Za-z_]/) {
          flush_method()
          cur_method = line
          sub(/^[[:space:]]+/, "", cur_method)
          cur_branches = 0
        }
        t = line
        cur_branches += gsub(/(^|[^[:alnum:]_])(if|elseif|foreach|for|while|switch|match)[[:space:]]*\(/, "", t)
        t = line
        cur_branches += gsub(/&&|\|\|/, "", t)
      }

      # Blank out the two sanctioned shapes before testing, so that a write call
      # merely *sharing a line* with them is still caught. (A line-wide "contains
      # $this->" exclusion would let `$order->update([... $this->foo])` through.)
      probe = line
      gsub(/\$this->[A-Za-z_][A-Za-z0-9_]*(\([^)]*\))?/, "@SELF@", probe)
      gsub(/(Storage|Cache|Session|Cookie|Log|Config|Redis|Mail|Queue|Bus|Event|Http|File|Artisan)::[A-Za-z_][A-Za-z0-9_]*(\([^)]*\))?(->[A-Za-z_][A-Za-z0-9_]*(\([^)]*\))?)*/, "@FACADE@", probe)

      is_db    = (probe ~ /(^|[^[:alnum:]_>$])DB::/ || probe ~ /app\([[:space:]]*['"'"'"]db['"'"'"]/)
      is_write = (probe ~ ("\\$[A-Za-z_][A-Za-z0-9_]*(\\([^)]*\\))?(->[A-Za-z_][A-Za-z0-9_]*(\\([^)]*\\))?)*->" WRITES "[[:space:]]*\\(") || \
                  probe ~ ("[A-Z][A-Za-z0-9_]*::" WRITES "[[:space:]]*\\("))
      is_role  = (line ~ /->role[[:space:]]*(===|!==|==|!=)/ || \
                  line ~ /->(is_admin|isAdmin|hasRole|hasAnyRole|hasPermission)/ || \
                  line ~ /in_array\([[:space:]]*\$[A-Za-z_][A-Za-z0-9_]*->role/)

      # Per-file tallies for the priority signal below. Counted independently of the
      # rule-group toggles and of which category wins for this line.
      if (line ~ /\$this->authorize\(|Gate::|authorizeResource\(/) n_authz++
      if (is_db || is_write) n_write++
      if (is_role) n_role++

      hit = ""
      if (check_persistence == 1) {
        if (is_db)         hit = "db-access"
        else if (is_write) hit = "eloquent-write"
      }
      if (hit == "" && check_role == 1 && is_role) hit = "role-check"
      if (hit != "") report(hit, line)
    }
    close(filename)

    # getline returns -1 when the file cannot be read; report it instead of counting
    # an unreadable file as clean.
    if (rc < 0) printf "  ! could not read %s — not checked\n", filename

    # Priority signal: this file mutates data, guards it with hand-written role
    # checks, and never involves a Policy. That combination is where a missing
    # object-level check hides — the script cannot see an *absent* check, but it can
    # say which file to read first. Found a real IDOR on the one production codebase
    # this was tested against (see ADR-0011).
    if (n_write > 0 && n_role > 0 && n_authz == 0) {
      priority++
      priority_report = priority_report sprintf("    %s\n      %d write(s) + %d inline role check(s), and no authorize()/Gate call anywhere in the file\n", \
                        filename, n_write, n_role)
    }

    flush_method()
    if (check_density == 1 && peak_branches > density_threshold) {
      dense++
      dense_report = dense_report sprintf("    %s\n      branches=%d in %s (threshold %d)\n", \
                     filename, peak_branches, peak_method, density_threshold)
    }
  }

  END {
    if (suppressed > 0)
      printf "\n  ... %d further violation(s) not listed (cap: %d per category per file, %d total)\n", \
             suppressed, max_per_file, max_total
    if (priority > 0) {
      printf "\n  READ THESE FIRST — writes guarded only by hand-written role checks:\n"
      printf "%s", priority_report
    }
    if (dense > 0) {
      printf "\n  Heuristic — a single method carries many branches (business decisions may live in the Controller):\n"
      printf "%s", dense_report
    }
    printf "VIOLATIONS=%d\n", violations + 0
    printf "HEURISTICS=%d\n", dense + 0
    printf "PRIORITY=%d\n", priority + 0
  }
')

VIOLATIONS=$(printf '%s\n' "$REPORT" | sed -n 's/^VIOLATIONS=//p')
HEURISTICS=$(printf '%s\n' "$REPORT" | sed -n 's/^HEURISTICS=//p')
PRIORITY=$(printf '%s\n' "$REPORT" | sed -n 's/^PRIORITY=//p')
VIOLATIONS=${VIOLATIONS:-0}
HEURISTICS=${HEURISTICS:-0}
PRIORITY=${PRIORITY:-0}
BODY=$(printf '%s\n' "$REPORT" | grep -vE '^(VIOLATIONS|HEURISTICS|PRIORITY)=' || true)

if [ "$STATS_ONLY" -eq 1 ]; then
  echo "  Violations         : $VIOLATIONS"
  echo "  Heuristic warnings : $HEURISTICS"
  echo "  Priority files     : $PRIORITY"
else
  [ -n "$BODY" ] && printf '%s\n' "$BODY"
  echo
  if [ "$VIOLATIONS" -eq 0 ] && [ "$HEURISTICS" -eq 0 ] && [ "$PRIORITY" -eq 0 ]; then
    echo "  >> No Domain Boundary violations detected in scope."
  else
    echo "  >> $VIOLATIONS violation(s), $HEURISTICS heuristic warning(s), $PRIORITY priority file(s)."
    echo "  >> These are pattern matches, not verdicts — confirm each against .claude/rules/10-laravel.md."
    echo "  >> This check cannot see business decisions written in plain PHP (see ADR-0011 limitations)."
  fi
fi

echo "VIOLATIONS=$VIOLATIONS"
[ "$VIOLATIONS" -eq 0 ]

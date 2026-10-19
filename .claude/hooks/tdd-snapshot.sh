#!/usr/bin/env bash
# tdd-snapshot.sh — the approved snapshot of tests/ and docs/product/ for /tdd.
# ADR-0017 Stage 1, item 5 (meta/adr/ADR-0017-loop-engineering-stage1.md).
#
#   record : at Gate 4 approval — copy tests/ and docs/product/ into
#            $(git rev-parse --git-path claude-tdd)/approved/ (inside .git/, never committed).
#            A new approval replaces the previous copy.
#   verify : after Green — compare the current files with the copy (diff -r) and print any
#            difference. The comparison never goes through git, so neither `git add` nor git
#            filters (.gitattributes clean filters) can hide a change.
#
# The denial log (logs/audit.jsonl, written by agent-guard.sh) is read too: record notes its
# size; verify prints every agent_guard_denial line written since approval, verbatim, so the
# person sees blocked attempts from the log rather than from the implementer's own report.
# A log shorter than at approval counts as a change (ADR-0017 item 5, amended 2026-10-07).
#
# After Green (S7, meta/design/s7-user-facing-stop.md):
#   mark-green   : when Step 4's checks pass — copy the application files into
#                  $(git rev-parse --git-path claude-tdd)/green/.
#   verify-green : after the fix round and Refactor — compare with that copy. Changes that
#                  touch permissions, destructive operations, security, secrets, outside effects,
#                  dependencies or integrity print a STOP line (exit 2); other user-facing files
#                  are LISTED for the final review and the rest ALSO (exit 0). Files git ignores
#                  are skipped. Pattern-based: it narrows what a person must look at, it does
#                  not prove a change safe.
#
# Run by the main session (not by tdd-implementer). Deterministic: bash + cp + diff, no AI.
#
# Exit code (same meaning as the gate contract, meta/design/gate-contract.md):
#   0 = unchanged since approval (denials, if any, are printed but do not change it),
#   2 = changed (diff printed) or the log shrank, 3 = no snapshot / usage error.
#   verify-green: 0 = nothing needs a stop, 2 = at least one STOP line, 3 = no Green mark.
set -u

LOCKED='tests docs/product'
WATCH_DIRS='app bootstrap config database lang resources routes'
WATCH_FILES='composer.json composer.lock package.json package-lock.json'
# Role predicates that decide permissions: the common names (as in domain-boundary-check.sh)
# plus the project's own, found automatically — any is*/has* method under app/ whose next few
# lines mention "role" (e.g. Employee::isSectionHead()). Nothing to configure.
role_predicates() {
  { echo 'is_admin isAdmin hasRole hasAnyRole hasPermission'
    find app -name '*.php' -print0 2>/dev/null | xargs -0 -r awk 'FNR == 1 { look = 0 }
      match($0, /function[[:space:]]+(is|has)[A-Z][A-Za-z0-9_]*[[:space:]]*\(/) {
        n = substr($0, RSTART, RLENGTH); sub(/^function[[:space:]]+/, "", n); sub(/[[:space:]]*\($/, "", n); look = 8 }
      look > 0 { if (tolower($0) ~ /role/) print n; look-- }'
    } | tr ' ' '\n' | sort -u | grep -v '^$' | paste -sd '|' -
}
env_sums() { for f in .env .env.*; do [ -f "$f" ] && [ "$f" != .env.example ] && echo "$(cksum < "$f" | tr -d ' ') $f"; done; return 0; }
hidden_of() { [ -f "$1" ] && awk '/\$hidden[[:space:]]*=|#\[Hidden/{f=1} f{print} f&&/\]/{exit}' "$1" | grep -oE "'[^']+'|\"[^\"]+\""; return 0; }
LOG="${CLAUDE_PROJECT_DIR:-$PWD}/logs/audit.jsonl"
log_size() { if [ -f "$LOG" ]; then wc -c < "$LOG" | tr -d ' '; else echo 0; fi; }

gitdir_path=$(git rev-parse --git-path claude-tdd 2>/dev/null) || {
  echo "tdd-snapshot: not inside a git repository" >&2; exit 3; }
SNAP="$gitdir_path/approved"

case "${1:-}" in
  record)
    rm -rf "$SNAP" && mkdir -p "$SNAP" || { echo "tdd-snapshot: cannot write $SNAP" >&2; exit 3; }
    n=0
    for d in $LOCKED; do
      [ -d "$d" ] || continue
      mkdir -p "$SNAP/$(dirname "$d")"
      cp -R "$d" "$SNAP/$d"
      n=$((n + $(find "$d" -type f | wc -l)))
    done
    date '+%Y-%m-%dT%H:%M:%S%z' > "$SNAP/.recorded-at"
    log_size > "$SNAP/.log-offset"
    echo "Saved the approved snapshot of tests/ and docs/product/ ($n files) — tdd-implementer cannot change them; verify runs after Green."
    exit 0
    ;;
  verify)
    [ -f "$SNAP/.recorded-at" ] || {
      echo "tdd-snapshot: no approved snapshot — run 'bash .claude/hooks/tdd-snapshot.sh record' at Gate 4 approval." >&2; exit 3; }
    changed=0
    for d in $LOCKED; do
      if [ -d "$d" ] && [ -d "$SNAP/$d" ]; then
        diff -ru "$SNAP/$d" "$d" > "$SNAP/.diff" 2>&1 || { changed=1; sed "s#$SNAP/##" "$SNAP/.diff"; }
      elif [ -d "$d" ] || [ -d "$SNAP/$d" ]; then
        changed=1; echo "Only in one side: $d (present at approval: $([ -d "$SNAP/$d" ] && echo yes || echo no), present now: $([ -d "$d" ] && echo yes || echo no))"
      fi
    done
    rm -f "$SNAP/.diff"

    log_changed=0
    if [ -f "$SNAP/.log-offset" ]; then
      off=$(cat "$SNAP/.log-offset"); now=$(log_size)
      if [ "$now" -lt "$off" ]; then
        log_changed=1
        echo "logs/audit.jsonl is shorter than at Gate 4 approval ($now < $off bytes): the record of this cycle was rewritten."
      else
        denials=$(tail -c +$((off + 1)) "$LOG" 2>/dev/null | grep -F '"event":"agent_guard_denial"')
        if [ -n "$denials" ]; then
          echo "tdd-implementer was blocked $(printf '%s\n' "$denials" | wc -l | tr -d ' ') time(s) since Gate 4 approval (logs/audit.jsonl, verbatim):"
          printf '%s\n' "$denials"
        else
          echo "No blocked attempts by tdd-implementer since Gate 4 approval (logs/audit.jsonl)."
        fi
      fi
    else
      echo "Blocked-attempt check skipped: this snapshot predates it (record again at the next Gate 4 approval)."
    fi
    # agent-guard.sh counts every implementer write / command (S9). None after Green means the
    # implementer did not run, or the hook no longer sees its calls (it would then fail open).
    calls=0; [ -f "$SNAP/.implementer-calls" ] && read -r calls < "$SNAP/.implementer-calls"
    if [ "${calls:-0}" -eq 0 ] 2>/dev/null; then
      echo "WARNING: agent-guard.sh counted no tdd-implementer writes or commands since Gate 4 approval — either the implementer did not run, or the hook no longer sees its calls (e.g. after a Claude Code update; docs/development/tdd-guard.md)."
    fi

    if [ "$changed" -eq 0 ] && [ "$log_changed" -eq 0 ]; then
      echo "tests/ and docs/product/ are unchanged since Gate 4 approval ($(cat "$SNAP/.recorded-at"))."
      exit 0
    fi
    [ "$changed" -eq 1 ] && echo "tdd-snapshot: tests/ or docs/product/ changed after Gate 4 approval (diff above)."
    echo "tdd-snapshot: a person decides: discard the change, or restart from Red and approve again."
    exit 2
    ;;
  mark-green)
    G="$gitdir_path/green"
    rm -rf "$G" && mkdir -p "$G/.empty" || { echo "tdd-snapshot: cannot write $G" >&2; exit 3; }
    for p in $WATCH_DIRS $WATCH_FILES; do
      [ -e "$p" ] || continue
      mkdir -p "$G/$(dirname "$p")"
      cp -R "$p" "$G/$p"
    done
    env_sums > "$G/.env-sums"
    date '+%Y-%m-%dT%H:%M:%S%z' > "$G/.recorded-at"
    echo "Saved the application files after Green — verify-green reports what changes from here."
    exit 0
    ;;
  verify-green)
    G="$gitdir_path/green"
    [ -f "$G/.recorded-at" ] || {
      echo "tdd-snapshot: no Green mark — run 'bash .claude/hooks/tdd-snapshot.sh mark-green' when Step 4's checks pass." >&2; exit 3; }
    raw=$(
      for p in $WATCH_DIRS; do
        a="$G/$p"; b="$p"; [ -d "$a" ] || a="$G/.empty"; [ -d "$b" ] || b="$G/.empty"
        [ "$a" = "$b" ] || diff -ruN "$a" "$b"
      done
      for p in $WATCH_FILES; do
        a="$G/$p"; b="$p"; [ -f "$a" ] || a=/dev/null; [ -f "$b" ] || b=/dev/null
        [ "$a" = "$b" ] || diff -uN "$a" "$b"
      done | cat
    )
    ROLE_PREDICATES=$(role_predicates)
    export G AUTHZ='authorize\(|Gate::|->can\(|abort\(403|->('"$ROLE_PREDICATES"')([^A-Za-z0-9_]|$)|->role(->value)?[[:space:]]*(===|!==|==|!=)' \
      DESTR='delete\(|forceDelete\(|truncate\(|DB::statement|DB::unprepared' \
      SEC='v-html|\{!!|whereRaw|DB::raw|selectRaw|orderByRaw|havingRaw|\$guarded[[:space:]]*=[[:space:]]*\[[[:space:]]*\]|#\[Unguarded\]|makeVisible\(' \
      SECRET="(api_?key|secret|token|password)[[:space:]]*[=:][[:space:]]*['\"][^'\"]{8,}" \
      OUTSIDE='Http::|Mail::|Notification::|->notify\(|Schedule::' \
      INTEGRITY='DB::transaction|lockForUpdate\(|sharedLock\(' \
      LISTED_RE='^(resources/js/|resources/views/|lang/|resources/lang/|app/Http/Requests/|app/Http/Controllers/)'
    found=$(printf '%s\n' "$raw" | awk '
      function rel(p) { sub(/\t.*/, "", p); if (index(p, ENVIRON["G"] "/") == 1) p = substr(p, length(ENVIRON["G"]) + 2); return p }
      function pclass(f) {
        if (f ~ /^routes\/console\.php$/ || f ~ /^app\/(Console|Jobs)\//) return "outside-effects"
        if (f ~ /^(app\/Policies\/|app\/Http\/Middleware\/|routes\/)/) return "permissions"
        if (f ~ /^database\/migrations\//) return "destructive"
        if (f ~ /^(config\/auth\.php|config\/session\.php|bootstrap\/app\.php)$/) return "security"
        if (f ~ /^(composer\.json|composer\.lock|package\.json|package-lock\.json)$/) return "dependencies"
        return ""
      }
      function file(f) { if (!(f in seen)) { seen[f] = 1; order[++n] = f; c = pclass(f); if (c != "") { stop[f] = 1; print "STOP\t" c "\t" f "\tfile changed after Green" } } cur = f }
      function hit(cls, where, t) { stop[cur] = 1; print "STOP\t" cls "\t" where "\t" substr(t, 1, 160) }
      /^--- /  { old = rel(substr($0, 5)); next }
      /^\+\+\+ / { f = rel(substr($0, 5)); if (f ~ /^\.empty\// || f == "/dev/null") f = old; sub(/^\.empty\//, "", f); file(f); next }
      /^Binary files / { f = $0; sub(/^Binary files .* and /, "", f); sub(/ differ$/, "", f); f = rel(f); file(f); next }
      /^@@ / { split($3, h, ","); ln = substr(h[1], 2) + 0; next }
      /^\+/ { t = substr($0, 2); w = cur ":" ln; ln++
              if (pclass(cur) != "") next
              if (t ~ ENVIRON["AUTHZ"]) hit("permissions", w, t)
              else if (t ~ ENVIRON["DESTR"]) hit("destructive", w, t)
              else if (t ~ ENVIRON["SEC"]) hit("security", w, t)
              else if (tolower(t) ~ ENVIRON["SECRET"]) hit("secrets", w, "(a key or password assignment; value not shown)")
              else if (t ~ ENVIRON["OUTSIDE"]) hit("outside-effects", w, t)
              next }
      /^-/ { t = substr($0, 2); if (pclass(cur) == "" && t ~ ENVIRON["INTEGRITY"]) hit("integrity", cur, "removed: " t); next }
      /^ / { ln++; next }
      END { for (i = 1; i <= n; i++) { f = order[i]; if (f in stop) continue
              print ((f ~ ENVIRON["LISTED_RE"]) ? "LISTED" : "ALSO") "\t-\t" f "\t" } }')
    # skip files git ignores (generated helpers, caches); then the $hidden and .env checks
    report=$(printf '%s\n' "$found" | while IFS=$'\t' read -r kind cls where note; do
        [ -n "$kind" ] || continue
        git check-ignore -q "${where%%:*}" 2>/dev/null && continue
        printf '%s\t%s\t%s\t%s\n' "$kind" "$cls" "$where" "$note"
      done
      printf '%s\n' "$found" | cut -f3 | sed 's/:.*//' | sort -u | grep '^app/Models/' | while read -r m; do
        for e in $(hidden_of "$G/$m"); do
          hidden_of "$m" | grep -qxF -- "$e" || printf 'STOP\tsecurity\t%s\tremoved from the hidden attributes: %s\n' "$m" "$e"
        done
      done
      env_sums | diff "$G/.env-sums" - | sed -n 's/^[<>] [^ ]* //p' | sort -u | while read -r e; do
        printf 'STOP\tsecrets\t%s\tchanged after Green (content not shown)\n' "$e"
      done)
    stops=$(printf '%s\n' "$report" | grep -c '^STOP' || true)
    listed=$(printf '%s\n' "$report" | grep -c '^LISTED' || true)
    also=$(printf '%s\n' "$report" | grep -c '^ALSO' || true)
    printf '%s\n' "$report" | grep '^STOP' | awk -F'\t' '{ print "STOP " $2 " " $3 " — " $4 }'
    printf '%s\n' "$report" | grep '^LISTED' | awk -F'\t' '{ print "LISTED " $3 " — user-facing, changed after Green" }'
    printf '%s\n' "$report" | grep '^ALSO' | awk -F'\t' '{ print "ALSO " $3 }'
    if [ "$stops" -gt 0 ]; then
      echo "tdd-snapshot: $stops change(s) after Green need a person (permissions, destructive, security, secrets, outside effects, dependencies, integrity) — approve them, or undo them and restart from Red with a test for the new behaviour."
      exit 2
    fi
    if [ $((listed + also)) -eq 0 ]; then echo "No application file changed after Green ($(cat "$G/.recorded-at"))."
    else echo "Changed after Green: $listed user-facing file(s) listed for the final review, $also other file(s); nothing needs a stop."; fi
    exit 0
    ;;
  *)
    echo "usage: tdd-snapshot.sh record|verify|mark-green|verify-green" >&2
    exit 3
    ;;
esac

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
# Run by the main session (not by tdd-implementer). Deterministic: bash + cp + diff, no AI.
#
# Exit code (same meaning as the gate contract, meta/design/gate-contract.md):
#   0 = unchanged since approval (denials, if any, are printed but do not change it),
#   2 = changed (diff printed) or the log shrank, 3 = no snapshot / usage error.
set -u

LOCKED='tests docs/product'
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

    if [ "$changed" -eq 0 ] && [ "$log_changed" -eq 0 ]; then
      echo "tests/ and docs/product/ are unchanged since Gate 4 approval ($(cat "$SNAP/.recorded-at"))."
      exit 0
    fi
    [ "$changed" -eq 1 ] && echo "tdd-snapshot: tests/ or docs/product/ changed after Gate 4 approval (diff above)."
    echo "tdd-snapshot: a person decides: discard the change, or restart from Red and approve again."
    exit 2
    ;;
  *)
    echo "usage: tdd-snapshot.sh record|verify" >&2
    exit 3
    ;;
esac

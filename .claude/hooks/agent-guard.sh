#!/usr/bin/env bash
# agent-guard.sh — PreToolUse hook: tdd-implementer cannot move the goal it is asked to reach.
# ADR-0017 Stage 1, item 3 (meta/adr/ADR-0017-loop-engineering-stage1.md).
#
# Registered in .claude/settings.json for Write|Edit|MultiEdit|NotebookEdit|Bash. Only calls
# made by the tdd-implementer subagent (payload field agent_type) are checked; the main
# session, test-writer and every other agent pass through untouched. For the implementer it
# denies:
#   - writing under tests/ or docs/product/ (the tests and spec approved at Gate 4), through
#     the file tools or through Bash commands that write (redirects, cp/mv/rm, sed -i, tee,
#     php file_put_contents, PowerShell Set-Content, ...);
#   - git commands that change the index, the working tree or the config
#     (add, commit, stash, checkout, restore, reset, rm, mv, apply, update-index, config);
#   - writing the record of the cycle: logs/ (this denial log) and the approved snapshot
#     (any path with a claude-tdd segment) — the checked party must not edit the evidence
#     about itself (ADR-0017 item 3, amended 2026-10-07).
# Each denial is appended to logs/audit.jsonl (event "agent_guard_denial"; never file contents).
#
# Bash builtins only on the allow path: the hook runs on every tool call, and a hook that
# times out fails open (ADR-0017 item 3), so it must stay fast and leave no child process.
# It is a guardrail, not a wall — a subprocess can still write; the approved snapshot
# (.claude/hooks/tdd-snapshot.sh, item 5) catches what gets past it.
#
# Exit code: 0 = allow, 2 = deny (reason on stderr, shown to the agent).

IFS= read -r -d '' input || true
[ -z "$input" ] && exit 0
# Fast path: a call from the implementer always names it.
[[ $input == *tdd-implementer* ]] || exit 0

ts() { printf -v REPLY '%(%Y-%m-%dT%H:%M:%S%z)T' -1; }
json_esc() { local s=$1 bs='\'; s=${s//"$bs"/"$bs$bs"}; s=${s//\"/\\\"}; REPLY=$s; }

deny() { # deny RULE TARGET MESSAGE
  local base=${CLAUDE_PROJECT_DIR:-$PWD} sid=${session_id:-} tool=${tool_name:-}
  ts; local now=$REPLY
  json_esc "$2"; local target=$REPLY
  [ -d "$base/logs" ] || mkdir -p "$base/logs" 2>/dev/null
  printf '{"ts":"%s","event":"agent_guard_denial","session_id":"%s","agent_type":"tdd-implementer","tool":"%s","rule":"%s","target":"%s"}\n' \
    "$now" "$sid" "$tool" "$1" "$target" >> "$base/logs/audit.jsonl" 2>/dev/null
  printf 'agent-guard: %s\n' "$3" >&2
  exit 2
}

GOAL_MSG='tests/ and docs/product/ hold the tests and the spec approved at Gate 4 (ADR-0017) and are locked for tdd-implementer. Change the implementation instead. If a test and the spec cannot both be satisfied, stop and report SPEC_CONFLICT with the test id and a verbatim quote from the use case.'
EVIDENCE_MSG='logs/ (the AI activity log) and the approved snapshot (.git/claude-tdd/) are the record of this TDD cycle and are locked for tdd-implementer (ADR-0017). Leave them as they are and work on the implementation.'

# Fields before "tool_input" belong to the hook payload itself; anything after it is the
# tool's input (which may legitimately contain the text "tdd-implementer").
head=${input%%\"tool_input\"*}
if [ "$head" = "$input" ]; then
  # Names the implementer but has no tool_input: unparsable. Fail closed.
  deny unparsable "" "could not parse the hook input; refusing by default."
fi
re_agent='"agent_type"[[:space:]]*:[[:space:]]*"([^"]*)"'
[[ $head =~ $re_agent ]] || exit 0
[ "${BASH_REMATCH[1]}" = "tdd-implementer" ] || exit 0

re_str='"%s"[[:space:]]*:[[:space:]]*"([^"]*)"'
field() { local re; printf -v re "$re_str" "$1"; [[ $2 =~ $re ]] && REPLY=${BASH_REMATCH[1]} || REPLY=''; }
field tool_name "$head"; tool_name=$REPLY
field session_id "$head"; session_id=$REPLY
field cwd "$head"; cwd=$REPLY
tin=${input#*\"tool_input\"}

# norm PATH -> REPLY: JSON-unescaped, "/" separators, lower case, c:/ -> /c/, . and .. resolved.
norm() {
  local p=$1 bs='\' seg lead='' IFS=/
  local -a parts out=()
  p=${p//"$bs$bs"/\/}; p=${p//"$bs"/\/}; p=${p,,}
  if [[ $p =~ ^([a-z]):/(.*)$ ]]; then p="/${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"; fi
  [[ $p == /* ]] && lead=/
  read -r -a parts <<< "$p"
  for seg in "${parts[@]}"; do
    case $seg in
      ''|.) ;;
      ..) [ ${#out[@]} -gt 0 ] && unset 'out[${#out[@]}-1]' ;;
      *) out+=("$seg") ;;
    esac
  done
  REPLY="$lead${out[*]}"
}

# locked PATH -> 0 when the path is under tests/ or docs/product/ of the project.
locked() {
  local p rel
  norm "$1"; p=$REPLY
  norm "$cwd"; local c=$REPLY
  if [[ $p == /* ]]; then
    if [ -n "$c" ] && [[ $p == "$c"/* ]]; then rel=${p#"$c"/}
    else [[ $p == */tests/* || $p == */docs/product/* ]]; return; fi
  else rel=$p; fi
  [[ $rel == tests || $rel == tests/* || $rel == docs/product || $rel == docs/product/* ]]
}

# evidence PATH -> 0 when the path is the cycle's record: logs/ of the project, or the
# approved snapshot (any claude-tdd segment — a worktree's git dir lies outside cwd).
evidence() {
  local p rel
  norm "$1"; p=$REPLY
  [[ $p == claude-tdd || $p == claude-tdd/* || $p == */claude-tdd || $p == */claude-tdd/* ]] && return 0
  norm "$cwd"; local c=$REPLY
  if [[ $p == /* ]]; then
    if [ -n "$c" ] && [[ $p == "$c"/* ]]; then rel=${p#"$c"/}
    else [[ $p == */logs/audit.jsonl ]]; return; fi
  else rel=$p; fi
  [[ $rel == logs || $rel == logs/* ]]
}

case $tool_name in
  Write|Edit|MultiEdit|NotebookEdit)
    re_path='"(file_path|notebook_path)"[[:space:]]*:[[:space:]]*"([^"]*)"'
    if [[ $tin =~ $re_path ]]; then
      target=${BASH_REMATCH[2]}
      locked "$target" && deny locked_path "$target" "$GOAL_MSG"
      evidence "$target" && deny locked_evidence "$target" "$EVIDENCE_MSG"
    else
      deny unparsable "" "could not find the file path in the tool input; refusing by default."
    fi
    ;;
  Bash|PowerShell)
    re_cmd='"command"[[:space:]]*:[[:space:]]*"((\\.|[^"\\])*)"'
    [[ $tin =~ $re_cmd ]] || deny unparsable "" "could not find the command in the tool input; refusing by default."
    cmd=${BASH_REMATCH[1]}
    bs='\'
    cmd=${cmd//"$bs\""/\"}; cmd=${cmd//"$bs$bs"/"$bs"}
    lc=${cmd,,}

    re_git='(^|[;&|(`[:space:]])git([[:space:]]+-[c][[:space:]]+[^[:space:]]+|[[:space:]]+--?[a-z-]+(=[^[:space:]]*)?)*[[:space:]]+(add|commit|stash|checkout|restore|reset|rm|mv|apply|update-index|config)([[:space:];&|)]|$)'
    if [[ $lc =~ $re_git ]]; then
      deny git_command "git ${BASH_REMATCH[3]}" "tdd-implementer may not run 'git ${BASH_REMATCH[3]}' — staging, committing, switching or configuring git is the main session's job (ADR-0017). Leave your changes in the working tree."
    fi

    re_locked='(^|[^a-z0-9_.-])(tests|docs/product)(/|\\|$|[[:space:]"'"'"'])'
    # The record: audit.jsonl anywhere, a claude-tdd segment, or a top-level logs/ (not
    # storage/logs/, the application's own log directory).
    re_evidence='audit\.jsonl|(^|[^a-z0-9_.-])claude-tdd(/|\\|$|[[:space:]"'"'"'])|(^|[[:space:];&|(>"'"'"'=]|\./)logs(/|\\|$|[[:space:]"'"'"';&|)])'
    if [[ $lc =~ $re_locked || $lc =~ $re_evidence ]]; then
      s=$lc
      for pat in '2>&1' '1>&2' '>&2' '2>/dev/null' '2> /dev/null' '>/dev/null' '> /dev/null' '>nul' '> nul'; do
        s=${s//"$pat"/}
      done
      re_write='>|(^|[^a-z_])(tee|cp|mv|rm|rmdir|touch|mkdir|ln|truncate|dd|install|rsync|unlink|chmod)([[:space:]]|$)|sed[[:space:]]+(-[a-z]*i|--in-place)|perl[[:space:]]+-[a-z]*i|file_put_contents|fwrite|fopen|copy\(|rename\(|unlink\(|set-content|add-content|out-file|new-item|remove-item|move-item|copy-item|clear-content'
      if [[ $s =~ $re_write ]]; then
        [[ $lc =~ $re_locked ]] && deny locked_path "$cmd" "$GOAL_MSG"
        deny locked_evidence "$cmd" "$EVIDENCE_MSG"
      fi
    fi
    ;;
esac
exit 0

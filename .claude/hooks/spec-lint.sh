#!/usr/bin/env bash
# spec-lint.sh — structural checks on the spec documents, run before a Gate 1 / Gate 2 review.
#
# Reads docs/product/requirements.md, docs/product/use-cases.md and docs/product/mockups/.
# Deterministic: bash + awk, no jq, no AI calls, writes nothing. It checks structure so the
# human reviewer can spend the review on content; it never approves or rejects anything,
# and it cannot see a requirement that is missing altogether — that stays with the reviewer.
#
# Reports, one line per finding ("<category>  <file>:<line>  <message>"):
#   id          : malformed or duplicate UC-NNN / F-NNN ids
#   section     : a use case without Actor / a required section, or with an empty one
#   link        : a Related requirement that does not exist; an F-id no use case refers to
#   placeholder : template text left in place ([...], TBD, TODO, YYYY-MM-DD, ...)
#   mockup      : mockup files vs use-case ids vs the Screen List in mockups/README.md
#   wording     : vague words (warning only — does not change the exit code)
#
# Usage:
#   bash .claude/hooks/spec-lint.sh                 # requirements + use cases + mockups (before Gate 2)
#   bash .claude/hooks/spec-lint.sh --requirements  # requirements.md only (before Gate 1)
#
# Exit code: 1 when findings exist, 0 otherwise (warnings alone exit 0), 2 on a usage error.
# The last line is always "SPEC_LINT findings=<n> warnings=<n>".
set -euo pipefail

SPEC_DIR="${SPEC_LINT_DIR:-docs/product}"

# Headings and labels of the spec templates. Each accepts the English (this repository) and
# the Japanese (JP sibling repository) wording, so both repositories ship this same file.
# Use-case sections that must exist with content ('|' between sections, ',' between
# spellings of one section). They map to the happy-path / error / authorization coverage
# that test-writer reports at Gate 4.
REQUIRED_SECTIONS='Basic Flow,基本フロー|Error Cases,エラーケース|Permissions,権限'
ACTOR_LABEL='Actor'
RELATED_LABEL='Related requirement,関連要件'
# Sections skipped by the placeholder / wording scans (POSIX ERE on the "## " heading line).
SKIP_SECTIONS='Approval Record|Review Criteria|承認記録|レビュー基準'
# The mockups/README.md section that lists the screens (POSIX ERE on the "## " heading line).
SCREEN_LIST='Screen List|画面一覧'

# Vague-word lists, '|'-separated, matched case-insensitively on word boundaries.
# Limited to the categories with high precision in requirements-smell research (Femmer et
# al., "Rapid quality assurance with Requirements Smells": 0.70-0.96); pronoun and negation
# checks were left out as mostly noise. Edit these for the project's language and domain.
WORDS_SUBJECTIVE='user-friendly|easy to use|intuitive|intuitively|seamless|seamlessly|flexible|robust|使いやすい|直感的|柔軟に'
WORDS_VAGUE='appropriate|appropriately|adequate|adequately|sufficient|sufficiently|reasonable|reasonably|properly|promptly|quickly|timely|適切に|適切な|十分な|速やかに'
WORDS_LOOPHOLE='if possible|as appropriate|as needed|as necessary|if necessary|where applicable|可能な限り|なるべく|必要に応じて|適宜'
WORDS_OPEN_ENDED='etc.|and so on|and/or|including but not limited to'

REQUIREMENTS_ONLY=0
for arg in "$@"; do
  case "$arg" in
    --requirements) REQUIREMENTS_ONLY=1 ;;
    -h|--help)      sed -n '2,23p' "$0"; exit 0 ;;
    *) echo "[spec-lint] unknown option: $arg" >&2; exit 2 ;;
  esac
done

# Paths are repo-relative; fall back to the current directory outside a git repository.
if TOPLEVEL=$(git rev-parse --show-toplevel 2>/dev/null); then
  cd "$TOPLEVEL"
fi

REQ="$SPEC_DIR/requirements.md"
UC="$SPEC_DIR/use-cases.md"
MOCK_DIR="$SPEC_DIR/mockups"

# Shared by every document: placeholder and wording scans, skipping fenced code, HTML
# comments, inline code, and the Approval Record / Review Criteria sections.
# Output records: F<TAB>category<TAB>line<TAB>message (finding), W<TAB>... (warning),
# D<TAB>kind<TAB>... (data for the bash side).
AWK_COMMON='
function trim(s) { sub(/^[ \t]+/, "", s); sub(/[ \t]+$/, "", s); return s }
function finding(cat, ln, msg) { printf "F\t%s\t%d\t%s\n", cat, ln, msg }
function warning(cat, ln, msg) { printf "W\t%s\t%d\t%s\n", cat, ln, msg }
function is_word_char(c) { return c ~ /[A-Za-z0-9_]/ }
# Byte-wise search on the lowercased line; ASCII terms must sit on word boundaries.
function has_term(s, t,    pos, start, before, after, ascii) {
  ascii = (t ~ /^[\001-\177]+$/)
  start = 1
  while ((pos = index(substr(s, start), t)) > 0) {
    pos += start - 1
    if (!ascii) return 1
    before = (pos > 1) ? substr(s, pos - 1, 1) : ""
    after = substr(s, pos + length(t), 1)
    if (!is_word_char(before) && !(is_word_char(after) && is_word_char(substr(t, length(t), 1)))) return 1
    start = pos + 1
  }
  return 0
}
function scan_words(s, ln, list, label,    n, terms, i) {
  n = split(list, terms, "|")
  for (i = 1; i <= n; i++)
    if (terms[i] != "" && has_term(s, tolower(terms[i])))
      warning("wording", ln, "\"" terms[i] "\" — " label " (warning)")
}
# Returns 1 when the line is outside regions that must not be scanned.
function scannable(line) {
  if (line ~ /^[ \t]*(```|~~~)/) { in_fence = !in_fence; return 0 }
  if (in_fence) return 0
  if (line ~ /^## /) skip_section = (line ~ skip_re)
  return !skip_section
}
function scan_text(line, ln,    s, rest, inner, after, tag) {
  s = line
  gsub(/`[^`]*`/, "", s)
  gsub(/<!--.*-->/, "", s)
  rest = s
  while (match(rest, /\[[^]]*\]/)) {
    inner = substr(rest, RSTART + 1, RLENGTH - 2)
    after = substr(rest, RSTART + RLENGTH, 1)
    if (after != "(" && inner !~ /^[ xX]?$/ && inner !~ /^!/) {
      if (tolower(inner) == "inferred")
        finding("placeholder", ln, "unresolved [inferred] marker — settle it with the human before sign-off")
      else
        finding("placeholder", ln, "template placeholder left: [" inner "]")
      break
    }
    rest = substr(rest, RSTART + RLENGTH)
  }
  if (match(s, /(^|[^A-Za-z])(TBD|TODO|TKTK)([^A-Za-z]|$)|YYYY-MM-DD|\?\?\?|NEEDS CLARIFICATION/)) {
    tag = substr(s, RSTART, RLENGTH); gsub(/^[^A-Z?]+|[^A-Z?]+$/, "", tag)
    finding("placeholder", ln, "placeholder left: " tag)
  }
  s = tolower(s)
  scan_words(s, ln, ws, "subjective")
  scan_words(s, ln, wv, "ambiguous adverb/adjective")
  scan_words(s, ln, wl, "loophole")
  scan_words(s, ln, wo, "open-ended")
}
'

AWK_REQ='
{ sub(/\r$/, "") }
{ ok = scannable($0) }
ok { scan_text($0, FNR) }
ok && /^\|[ \t]*F-/ {
  split($0, cells, "|"); id = trim(cells[2])
  if (id !~ /^F-[0-9][0-9][0-9]+$/) { finding("id", FNR, "malformed feature id \"" id "\" (expected F-NNN)"); next }
  if (id in seen) finding("id", FNR, id " is a duplicate (first at line " seen[id] ")")
  else { seen[id] = FNR; printf "D\tFID\t%s\t%d\n", id, FNR }
}
'

AWK_UC='
function norm(raw,    d) { d = raw; gsub(/[^0-9]/, "", d); return sprintf("UC-%03d", d + 0) }
function shown(spellings,    s) { s = spellings; gsub(/,/, " / ", s); return s }
# 1 when the line starts with "**<one of the spellings>**:" (ASCII or full-width colon).
function has_label(line, spellings,    n, alts, k) {
  n = split(spellings, alts, ",")
  for (k = 1; k <= n; k++)
    if (index(line, "**" alts[k] "**:") == 1 || index(line, "**" alts[k] "**\357\274\232") == 1) return 1
  return 0
}
function close_uc(    i) {
  if (cur == "") return
  if (!has_actor) finding("section", cur_ln, cur ": \"" shown(actor) "\" is missing")
  for (i = 1; i <= nreq; i++) {
    if (!(i in sec_seen)) finding("section", cur_ln, cur ": section \"" shown(req[i]) "\" is missing")
    else if (sec_content[i] == 0) finding("section", sec_seen[i], cur ": section \"" shown(req[i]) "\" is empty")
  }
  if (have_fids) {
    if (!has_related) finding("link", cur_ln, cur ": no \"" shown(related) "\" line")
  }
  delete sec_seen; delete sec_content
  cur = ""; cur_sec = 0
}
BEGIN {
  nreq = split(required, req, "|")
  for (i = 1; i <= nreq; i++) { nalt = split(req[i], alts, ","); for (k = 1; k <= nalt; k++) section_of[tolower(alts[k])] = i }
  nf = split(fids, fl, " "); for (i = 1; i <= nf; i++) if (fl[i] != "") { known[fl[i]] = 1; have_fids = 1 }
}
{ sub(/\r$/, "") }
{ ok = scannable($0) }
ok { scan_text($0, FNR) }
/^###[ \t]+[Uu][Cc]/ && !in_fence {
  close_uc()
  if (match($0, /[Uu][Cc]-?[0-9]+/)) id = norm(substr($0, RSTART, RLENGTH)); else id = "UC-???"
  if ($0 !~ /^### UC-[0-9][0-9][0-9]+: [^ ]/) finding("id", FNR, "malformed use-case heading (expected \"### UC-NNN: title\"): " $0)
  if (id in uc_seen) finding("id", FNR, id " is a duplicate (first at line " uc_seen[id] ")")
  else uc_seen[id] = FNR
  printf "D\tUC\t%s\n", id
  cur = id; cur_ln = FNR; has_actor = 0; has_related = 0
  next
}
/^##[^#]/ || /^### / { if (!in_fence) close_uc(); next }
cur == "" || in_fence { next }
has_label($0, actor) { has_actor = 1 }
has_label($0, related) {
  has_related = 1; rest = $0
  while (match(rest, /F-[0-9]+/)) {
    f = substr(rest, RSTART, RLENGTH); used[f] = 1
    if (have_fids && !(f in known)) finding("link", FNR, cur " refers to " f ", which is not in the requirements Feature List")
    rest = substr(rest, RSTART + RLENGTH)
  }
}
/^#### / {
  name = tolower(trim(substr($0, 6)))
  cur_sec = (name in section_of) ? section_of[name] : 0
  if (cur_sec) { sec_seen[cur_sec] = FNR; sec_content[cur_sec] = 0 }
  prev_row = 0
  next
}
cur_sec {
  line = trim($0)
  if (line == "" || line ~ /^-{3,}$/) { prev_row = 0; next }
  if (line ~ /^\|[-:| \t]+\|?$/) { if (prev_row) sec_content[cur_sec]--; prev_row = 0; next }
  sec_content[cur_sec]++
  prev_row = (line ~ /^\|/)
}
END {
  close_uc()
  for (f in used) printf "D\tUSED\t%s\n", f
}
'

AWK_README='
{ sub(/\r$/, "") }
/^## / { in_list = ($0 ~ screen_re); past_sep = 0; next }
in_list && /^\|[-:| \t]+\|?$/ { past_sep = 1; next }
in_list && past_sep && /^\|/ {
  split($0, cells, "|"); name = cells[2]
  gsub(/[` \t]/, "", name)
  if (name == "" || name ~ /^\*\(/) next
  printf "D\tROW\t%s\t%d\n", name, FNR
}
'

run_awk() { # run_awk PROGRAM FILE [VAR=VALUE...]
  local prog=$1 file=$2; shift 2
  LC_ALL=C awk -v ws="$WORDS_SUBJECTIVE" -v wv="$WORDS_VAGUE" -v wl="$WORDS_LOOPHOLE" \
    -v wo="$WORDS_OPEN_ENDED" -v skip_re="$SKIP_SECTIONS" "$@" "$AWK_COMMON $prog" "$file"
}

FINDINGS=0
WARNINGS=0
OUT_LINES=()
NOTES=()

emit() { # emit RECORDS FILE — turn F/W records into report lines; return data records on stdout
  local file=$1 kind cat ln msg
  while IFS=$'\t' read -r kind cat ln msg; do
    case "$kind" in
      F) OUT_LINES+=("$(printf '%-11s  %s:%s  %s' "$cat" "$file" "$ln" "$msg")"); FINDINGS=$((FINDINGS + 1)) ;;
      W) OUT_LINES+=("$(printf '%-11s  %s:%s  %s' "$cat" "$file" "$ln" "$msg")"); WARNINGS=$((WARNINGS + 1)) ;;
      D) DATA+=("$cat"$'\t'"$ln"$'\t'"$msg") ;;
    esac
  done
}

DATA=()
FIDS=" "
FID_LINES=()
if [ -f "$REQ" ]; then
  emit "$REQ" < <(run_awk "$AWK_REQ" "$REQ")
  for rec in "${DATA[@]+"${DATA[@]}"}"; do
    IFS=$'\t' read -r kind id ln <<<"$rec"
    [ "$kind" = FID ] && { FIDS+="$id "; FID_LINES+=("$id:$ln"); }
  done
  [ "$FIDS" = " " ] && NOTES+=("note         $REQ has no Feature List rows (F-NNN); requirement links not checked")
else
  NOTES+=("note         $REQ not found; requirement links not checked")
fi

UC_COUNT=0
if [ "$REQUIREMENTS_ONLY" -eq 0 ]; then
  if [ -f "$UC" ]; then
    DATA=()
    emit "$UC" < <(run_awk "$AWK_UC" "$UC" -v required="$REQUIRED_SECTIONS" -v actor="$ACTOR_LABEL" \
      -v related="$RELATED_LABEL" -v fids="$FIDS")
    UCS=" "
    USED=" "
    for rec in "${DATA[@]+"${DATA[@]}"}"; do
      IFS=$'\t' read -r kind id _ <<<"$rec"
      case "$kind" in
        UC)   UCS+="$id "; UC_COUNT=$((UC_COUNT + 1)) ;;
        USED) USED+="$id " ;;
      esac
    done
    if [ "$FIDS" != " " ] && [ "$UC_COUNT" -gt 0 ]; then
      for entry in "${FID_LINES[@]}"; do
        id=${entry%%:*}
        case "$USED" in *" $id "*) ;; *)
          OUT_LINES+=("$(printf '%-11s  %s:%s  %s' link "$REQ" "${entry##*:}" "$id is not referenced by any use case")")
          FINDINGS=$((FINDINGS + 1)) ;;
        esac
      done
    fi

    if [ -d "$MOCK_DIR" ]; then
      LISTED=" "
      if [ -f "$MOCK_DIR/README.md" ]; then
        while IFS=$'\t' read -r _ _ name ln; do
          LISTED+="$name "
          if [ ! -e "$MOCK_DIR/$name" ]; then
            OUT_LINES+=("$(printf '%-11s  %s:%s  %s' mockup "$MOCK_DIR/README.md" "$ln" "listed file $name does not exist")")
            FINDINGS=$((FINDINGS + 1))
          fi
        done < <(LC_ALL=C awk -v screen_re="$SCREEN_LIST" "$AWK_README" "$MOCK_DIR/README.md")
      fi
      for path in "$MOCK_DIR"/*; do
        [ -f "$path" ] || continue
        name=${path##*/}
        [ "$name" = README.md ] && continue
        if [[ ! $name =~ ^screen-UC([0-9]{3,})-[a-z0-9]+(-[a-z0-9]+)*\.(html|png|md)$ ]]; then
          OUT_LINES+=("$(printf '%-11s  %s  %s' mockup "$path" "$name does not follow the naming rule screen-UCNNN-name.(html|png|md)")")
          FINDINGS=$((FINDINGS + 1)); continue
        fi
        uc_id="UC-${BASH_REMATCH[1]}"
        ext=${BASH_REMATCH[3]}
        case "$UCS" in *" $uc_id "*) ;; *)
          OUT_LINES+=("$(printf '%-11s  %s  %s' mockup "$path" "$name refers to $uc_id, which is not in use-cases.md")")
          FINDINGS=$((FINDINGS + 1)) ;;
        esac
        [ "$ext" = md ] && continue
        case "$LISTED" in *" $name "*) ;; *)
          OUT_LINES+=("$(printf '%-11s  %s  %s' mockup "$path" "$name is not in the Screen List ($MOCK_DIR/README.md)")")
          FINDINGS=$((FINDINGS + 1)) ;;
        esac
        if [ "$ext" = png ] && [ ! -f "${path%.png}.md" ]; then
          OUT_LINES+=("$(printf '%-11s  %s  %s' mockup "$path" "$name has no companion ${name%.png}.md")")
          FINDINGS=$((FINDINGS + 1))
        fi
      done
    fi
  else
    NOTES+=("note         $UC not found; use cases not checked")
  fi
fi

SCOPE="requirements"
[ "$REQUIREMENTS_ONLY" -eq 0 ] && SCOPE="requirements, use cases ($UC_COUNT), mockups"
echo "[spec-lint] checked: $SCOPE"
for line in "${NOTES[@]+"${NOTES[@]}"}" "${OUT_LINES[@]+"${OUT_LINES[@]}"}"; do
  printf '%s\n' "$line"
done
echo "SPEC_LINT findings=$FINDINGS warnings=$WARNINGS"
[ "$FINDINGS" -eq 0 ]

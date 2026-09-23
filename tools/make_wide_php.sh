#!/usr/bin/env bash
#
# make_wide_php.sh — PHP route. One row per baby, de-identified, with metadata.
#
# Uses the engine's WideDump, so the header is the full data-dictionary column
# set and is stable between extracts. The export is written to work/ first,
# de-identified into bundle/, and the identifiable copy is then deleted.
#
# Usage (from the repo root):
#   bash tools/make_wide_php.sh                      full bundle
#   bash tools/make_wide_php.sh --mem=6G             more memory for the transformer
#   bash tools/make_wide_php.sh --out-dir=/tmp/emol_0925
#   bash tools/make_wide_php.sh --list-forms                 show the forms you can choose
#   bash tools/make_wide_php.sh --forms=a,b                  only these forms
#   bash tools/make_wide_php.sh --vars=x,y                   only these variables
#   bash tools/make_wide_php.sh --forms=a --exclude-vars=x   form a, without variable x
#   bash tools/make_wide_php.sh --exclude-forms=a            everything except form a
#   bash tools/make_wide_php.sh --fields=list.txt            the same rules from a file
#   bash tools/make_wide_php.sh --no-stata           skip the Stata files
#   bash tools/make_wide_php.sh --keep-work          keep the identifiable export
#
# Output: <out-dir>/bundle/ — send that folder and nothing else.
#
set -euo pipefail

PROJECT=Emollient; REPORT=WideDump; OUTDIR=/tmp/wide_export_php; PREFIX=emol; ROUTE=PHP
MEM=4G; STATA=1; KEEP_WORK=0; ALLOW_NO_REPEATS=0; SAMPLE=""
FIELDS=""; ALLOW_MISSING=0
INC_FORMS=""; INC_VARS=""; EXC_FORMS=""; EXC_VARS=""
LIST_FORMS=0; VARS_OF=""; SEL_ON=0; SEL_DESC=""; HELP=0

for a in "$@"; do
  case "$a" in
    --project=*)        PROJECT="${a#*=}" ;;
    --report=*)         REPORT="${a#*=}" ;;
    --out-dir=*)        OUTDIR="${a#*=}" ;;
    --prefix=*)         PREFIX="${a#*=}" ;;
    --mem=*)            MEM="${a#*=}" ;;
    --fields=*)         FIELDS="${a#*=}" ;;
    --forms=*)          INC_FORMS="${a#*=}" ;;
    --vars=*)           INC_VARS="${a#*=}" ;;
    --exclude-forms=*)  EXC_FORMS="${a#*=}" ;;
    --exclude-vars=*)   EXC_VARS="${a#*=}" ;;
    --list-forms)       LIST_FORMS=1 ;;
    --vars-of=*)        VARS_OF="${a#*=}"; LIST_FORMS=1 ;;
    --allow-missing-fields) ALLOW_MISSING=1 ;;
    --no-stata)         STATA=0 ;;
    --keep-work)        KEEP_WORK=1 ;;
    --allow-no-repeats) ALLOW_NO_REPEATS=1 ;;
    -h|--help)          HELP=1 ;;
    *) echo "Unknown option: $a  (try --help)" >&2; exit 2 ;;
  esac
done

SEL_BITS=""
[ -n "$INC_FORMS" ] && SEL_BITS="$SEL_BITS forms:$INC_FORMS"
[ -n "$INC_VARS"  ] && SEL_BITS="$SEL_BITS vars:$INC_VARS"
[ -n "$EXC_FORMS" ] && SEL_BITS="$SEL_BITS minus-forms:$EXC_FORMS"
[ -n "$EXC_VARS"  ] && SEL_BITS="$SEL_BITS minus-vars:$EXC_VARS"
[ -n "$FIELDS"    ] && SEL_BITS="$SEL_BITS file:$(basename "$FIELDS")"
SEL_DESC="${SEL_BITS# }"
[ -n "$SEL_DESC" ] && SEL_ON=1

source "$(dirname "$0")/bundle_lib.sh"
[ "$HELP" -eq 1 ] && lib_help
lib_init

FULL="$WORK/wide_full.csv"; FMAP="$WORK/fields.csv"

[ "$LIST_FORMS" -eq 1 ] && { lib_preflight; lib_list_forms; }

lib_preflight
note "report  : $REPORT   memory: $MEM"

# ── 1. wide export ─────────────────────────────────────────────────────────
step "1. Wide export ($REPORT) — this takes several minutes"
php -d memory_limit="$MEM" tools/dump.php --project="$PROJECT" --report="$REPORT" \
    --output="$FULL" 2>&1 | tee -a "$LOG" || fail "dump.php failed"
[ -s "$FULL" ] || fail "$FULL is empty"
note "$(du -h "$FULL" | cut -f1)  (IDENTIFIABLE — in work/, not bundle/)"

# The silent failure: a clean-looking file that has lost its repeat instances.
# The engine reports the repeat map as it runs — check it said something.
EV=$(grep -oE 'repeat map built: [0-9]+' "$LOG" | tail -1 | grep -oE '[0-9]+$' || echo "")
if [ -n "$EV" ]; then
  note "repeat map: $EV event(s) with repeats"
  if [ "$EV" -eq 0 ] && [ "$ALLOW_NO_REPEATS" -eq 0 ]; then
    fail "repeat map is EMPTY — this export has no repeat instances. (--allow-no-repeats to override)"
  fi
else
  warn "no 'repeat map built' line in the log — cannot confirm repeats were detected"
fi

# ── 2. optional subset, then de-identify into the bundle ───────────────────
SRC="$FULL"
if [ "$SEL_ON" -eq 1 ]; then
  lib_fieldmap
  lib_select "$FULL" "$WORK/wide_sel.csv"
  SRC="$WORK/wide_sel.csv"
fi
step "2. De-identify"
php tools/deidentify.php "$SRC" "$BUNDLE/wide.csv" 2>&1 | grep -E "columns|written|rows" | tee -a "$LOG" \
  || fail "deidentify.php failed"
[ -s "$BUNDLE/wide.csv" ] || fail "de-identified wide.csv is empty"

# ── 3 onwards: shared ──────────────────────────────────────────────────────
lib_gate     "$BUNDLE/wide.csv"
lib_shape
lib_metadata
lib_stata
lib_readme
lib_cleanup
lib_summary

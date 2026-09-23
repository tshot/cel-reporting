#!/usr/bin/env bash
#
# make_wide.sh — R route. One row per baby, de-identified, with metadata.
#
# RawDump streams (never buffers), the field map is one metadata call, and R
# does the pivot. Identifying fields are dropped BEFORE the pivot, so they
# never reach the wide file.
#
# Usage (from the repo root):
#   bash tools/make_wide.sh                          full bundle
#   bash tools/make_wide.sh --sample=200             quick trial
#   bash tools/make_wide.sh --out-dir=/tmp/emol_0925
#   bash tools/make_wide.sh --template=/tmp/cols.txt force the full engine column set
#   bash tools/make_wide.sh --list-forms                 show the forms you can choose
#   bash tools/make_wide.sh --forms=a,b                  only these forms
#   bash tools/make_wide.sh --vars=x,y                   only these variables
#   bash tools/make_wide.sh --forms=a --exclude-vars=x   form a, without variable x
#   bash tools/make_wide.sh --exclude-forms=a            everything except form a
#   bash tools/make_wide.sh --fields=list.txt            the same rules from a file
#   bash tools/make_wide.sh --drop-empty-rows            drop babies with no data
#   bash tools/make_wide.sh --min-fields=3               ... keep only rows with 3+ values
#   bash tools/make_wide.sh --no-stata               skip the Stata files
#   bash tools/make_wide.sh --keep-work              keep raw.csv etc. (IDENTIFIABLE)
#   bash tools/make_wide.sh --reuse-raw --keep-work  re-pivot without re-pulling
#
# Output: <out-dir>/bundle/ — send that folder and nothing else.
#
set -euo pipefail

PROJECT=Emollient; OUTDIR=/tmp/wide_export; PREFIX=emol; ROUTE=R
SAMPLE=""; TEMPLATE=""; REUSE=0; STATA=1; KEEP_WORK=0; ALLOW_NO_REPEATS=0
FIELDS=""; ALLOW_MISSING=0; DROP_EMPTY=0; MIN_FIELDS=1
INC_FORMS=""; INC_VARS=""; EXC_FORMS=""; EXC_VARS=""
LIST_FORMS=0; VARS_OF=""; SEL_ON=0; SEL_DESC=""; HELP=0

for a in "$@"; do
  case "$a" in
    --project=*)        PROJECT="${a#*=}" ;;
    --out-dir=*)        OUTDIR="${a#*=}" ;;
    --prefix=*)         PREFIX="${a#*=}" ;;
    --sample=*)         SAMPLE="--sample=${a#*=}" ;;
    --template=*)       TEMPLATE="--template=${a#*=}" ;;
    --reuse-raw)        REUSE=1 ;;
    --fields=*)         FIELDS="${a#*=}" ;;
    --forms=*)          INC_FORMS="${a#*=}" ;;
    --vars=*)           INC_VARS="${a#*=}" ;;
    --exclude-forms=*)  EXC_FORMS="${a#*=}" ;;
    --exclude-vars=*)   EXC_VARS="${a#*=}" ;;
    --list-forms)       LIST_FORMS=1 ;;
    --vars-of=*)        VARS_OF="${a#*=}"; LIST_FORMS=1 ;;
    --allow-missing-fields) ALLOW_MISSING=1 ;;
    --drop-empty-rows)  DROP_EMPTY=1 ;;
    --min-fields=*)     MIN_FIELDS="${a#*=}"; DROP_EMPTY=1 ;;
    --no-stata)         STATA=0 ;;
    --keep-work)        KEEP_WORK=1 ;;
    --allow-no-repeats) ALLOW_NO_REPEATS=1 ;;
    --deidentify|--codebook) ;;          # always on now; accepted for old habits
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

RAW="$WORK/raw.csv"; FMAP="$WORK/fields.csv"; EVMAP="$WORK/events.csv"

[ "$LIST_FORMS" -eq 1 ] && { lib_preflight; lib_list_forms; }

lib_preflight
command -v Rscript >/dev/null || fail "Rscript not found — apt-get install r-base-core"
Rscript -e 'if(!requireNamespace("data.table",quietly=TRUE)) quit(status=1)' \
  || fail "R package data.table missing — apt-get install r-cran-data.table"

# ── 1. long export ─────────────────────────────────────────────────────────
if [ "$REUSE" -eq 1 ] && [ -s "$RAW" ]; then
  step "1. Long export — reusing $RAW"
else
  [ "$REUSE" -eq 1 ] && warn "--reuse-raw: no raw.csv in work/ (was the last run --keep-work?). Pulling fresh."
  step "1. Long export (RawDump) — streams, does not buffer"
  php tools/dump.php --project="$PROJECT" --report=RawDump --output="$RAW" 2>&1 | tee -a "$LOG" \
    || fail "dump.php failed"
  [ -s "$RAW" ] || fail "$RAW is empty"
fi
note "$(du -h "$RAW" | cut -f1)  (IDENTIFIABLE — in work/, not bundle/)"

# ── 2. field map ───────────────────────────────────────────────────────────
lib_fieldmap

# ── 3. pivot, de-identifying as it goes ────────────────────────────────────
step "3. Pivot in R, dropping identifying fields first"
PIVOT_OUT="$BUNDLE/wide.csv"; [ "$SEL_ON" -eq 1 ] && PIVOT_OUT="$WORK/wide_all.csv"
Rscript tools/wide_pivot.R "$RAW" "$FMAP" "$PIVOT_OUT" \
    --exclude=tools/phi_fields.txt --events="$EVMAP" $SAMPLE $TEMPLATE 2>&1 | tee -a "$LOG" \
  || fail "wide_pivot.R failed"
[ -s "$PIVOT_OUT" ] || fail "pivot output is empty"
[ "$SEL_ON" -eq 1 ] && lib_select "$PIVOT_OUT" "$BUNDLE/wide.csv"

# ── 4 onwards: shared ──────────────────────────────────────────────────────
lib_gate       "$BUNDLE/wide.csv"
lib_drop_empty "$BUNDLE/wide.csv"
lib_shape
lib_metadata
lib_stata
lib_readme
lib_cleanup
lib_summary

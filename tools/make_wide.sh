#!/usr/bin/env bash
#
# make_wide.sh — build the wide (one row per baby) export via the R pivot.
#
# The PHP wide transformer buffers the whole project and exhausts memory on
# the full Emollient dataset. This route never buffers: RawDump streams, the
# field map is a single metadata call, and R does the pivot.
#
# Usage (from the repo root):
#   bash tools/make_wide.sh
#   bash tools/make_wide.sh --project=Emollient --out-dir=/tmp/emol
#   bash tools/make_wide.sh --sample=200            # quick check first
#   bash tools/make_wide.sh --reuse-raw             # skip the REDCap pull
#   bash tools/make_wide.sh --verify                # cell-by-cell check (slow)
#   bash tools/make_wide.sh --codebook              # also build the analyst metadata
#   bash tools/make_wide.sh --template=/tmp/cols.txt # force the engine's full column set
#
set -euo pipefail

PROJECT=Emollient
REPORT=RawDump
OUTDIR=/tmp/wide_export
SAMPLE=""
REUSE=0
VERIFY=0
CODEBOOK=0
TEMPLATE=""

for a in "$@"; do
  case "$a" in
    --project=*)      PROJECT="${a#*=}" ;;
    --report=*)       REPORT="${a#*=}" ;;
    --out-dir=*)      OUTDIR="${a#*=}" ;;
    --sample=*)       SAMPLE="--sample=${a#*=}" ;;
    --reuse-raw)      REUSE=1 ;;
    --verify)         VERIFY=1 ;;
    --codebook)       CODEBOOK=1 ;;
    --template=*)     TEMPLATE="--template=${a#*=}" ;;
    -h|--help)        sed -n '2,20p' "$0"; exit 0 ;;
    *) echo "Unknown option: $a" >&2; exit 2 ;;
  esac
done

# always run from the repo root, whatever directory the user is in
cd "$(dirname "$0")/.."
ROOT="$(pwd)"

RAW="$OUTDIR/raw.csv"
FIELDS="$OUTDIR/fields.csv"
WIDE="$OUTDIR/wide.csv"
LOG="$OUTDIR/make_wide.log"

mkdir -p "$OUTDIR"
: > "$LOG"

step()  { printf '\n\033[1m==> %s\033[0m\n' "$1" | tee -a "$LOG"; }
note()  { printf '    %s\n' "$1" | tee -a "$LOG"; }
fail()  { printf '\n\033[31mFAILED: %s\033[0m\n' "$1" | tee -a "$LOG"; exit 1; }
secs()  { printf '%s' "$(date +%s)"; }

T0=$(secs)

# ── preflight ──────────────────────────────────────────────────────────────
step "Preflight"
command -v php     >/dev/null || fail "php not found"
command -v Rscript >/dev/null || fail "Rscript not found — apt-get install r-base-core"
Rscript -e 'if(!requireNamespace("data.table", quietly=TRUE)) quit(status=1)' \
  || fail "R package data.table missing — apt-get install r-cran-data.table"
[ -f "$ROOT/vendor/autoload.php" ] || fail "no vendor/autoload.php in $ROOT"
[ -f "$ROOT/projects/$PROJECT/config.php" ] || fail "unknown project: $PROJECT"
note "repo     : $ROOT"
note "project  : $PROJECT"
note "output   : $OUTDIR"

# ── 1. long export ─────────────────────────────────────────────────────────
if [ "$REUSE" -eq 1 ] && [ -s "$RAW" ]; then
  step "1/4 Long export — reusing $RAW"
  note "$(du -h "$RAW" | cut -f1)"
else
  step "1/4 Long export ($REPORT) — streams, does not buffer"
  t=$(secs)
  php tools/dump.php --project="$PROJECT" --report="$REPORT" --output="$RAW" 2>&1 | tee -a "$LOG" \
    || fail "dump.php failed"
  [ -s "$RAW" ] || fail "$RAW is empty"
  note "took $(( $(secs) - t ))s, $(du -h "$RAW" | cut -f1)"
fi

# ── 2. field map ───────────────────────────────────────────────────────────
step "2/4 Field map — one metadata call"
php tools/export_field_map.php --project="$PROJECT" --out="$FIELDS" 2>&1 | tee -a "$LOG" \
  || fail "export_field_map.php failed"
[ -s "$FIELDS" ] || fail "$FIELDS is empty"

# ── 3. pivot ───────────────────────────────────────────────────────────────
step "3/4 Pivot in R"
t=$(secs)
Rscript tools/wide_pivot.R "$RAW" "$FIELDS" "$WIDE" $SAMPLE $TEMPLATE 2>&1 | tee -a "$LOG" \
  || fail "wide_pivot.R failed"
[ -s "$WIDE" ] || fail "$WIDE is empty"
note "took $(( $(secs) - t ))s"

# ── 4. checks ──────────────────────────────────────────────────────────────
step "4/4 Row and column count check"
php tools/csvcheck.php "$WIDE" 2>&1 | tee -a "$LOG" || fail "csvcheck failed"

if [ "$VERIFY" -eq 1 ]; then
  step "Cell-by-cell verification against the long export"
  note "this walks every non-empty source value — slow on the full dataset"
  php tools/verify_wide.php "$WIDE" "$RAW" ${SAMPLE:+--sample=${SAMPLE#--sample=}} 2>&1 \
    | tee -a "$LOG" || fail "verify_wide failed"
fi

if [ "$CODEBOOK" -eq 1 ]; then
  step "Codebook — built from the delivered header, so it matches exactly"
  php tools/make_codebook.php --project="$PROJECT" \
      --from-header="$WIDE" --out="$OUTDIR/${PROJECT}" 2>&1 | tee -a "$LOG" \
    || fail "make_codebook.php failed"
fi

step "Done in $(( $(secs) - T0 ))s"
note "wide   : $WIDE  ($(du -h "$WIDE" | cut -f1))"
note "long   : $RAW"
note "fields : $FIELDS"
[ "$CODEBOOK" -eq 1 ] && note "codebook: $OUTDIR/${PROJECT}_codebook.csv"
note "log    : $LOG"
[ -n "$SAMPLE" ] && note "NOTE: built from a SAMPLE — rerun without --sample for the full export"
exit 0

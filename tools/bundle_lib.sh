# bundle_lib.sh — shared steps for make_wide.sh (R route) and make_wide_php.sh
# (PHP route). Sourced, not run.
#
# Layout produced under $OUTDIR:
#   bundle/   what you send — de-identified data plus metadata, nothing else
#   work/     identifiable intermediates; deleted at the end unless --keep-work
#   build.log full log of the run


# Full help. Called before lib_init, so it must not depend on any paths.
lib_help() {
  local me; me="$(basename "$0")"
  cat <<EOF

$me — $ROUTE route. One row per baby, de-identified, with metadata.

USAGE
  bash tools/$me [options]

  With no options it exports every field. The output is <out-dir>/bundle/ —
  send that folder and nothing else. Identifiable intermediates are written to
  <out-dir>/work/ and deleted when the run finishes.

CHOOSING FORMS AND VARIABLES
  Naming a form includes all of its variables, at every event and every repeat
  instance. All four options take comma-separated lists and can be combined in
  one command.

  --forms=a,b            include every variable of these forms
  --vars=x,y             include these variables
  --exclude-forms=a,b    drop every variable of these forms
  --exclude-vars=x,y     drop these variables
  --fields=FILE          the same rules from a file (merged with the above):
                           x  include var      -x  exclude var
                           form:a  include     -form:a  exclude

  PRECEDENCE, applied to each column in turn:
    1. record_id is always kept
    2. a VARIABLE rule beats a FORM rule        (the more specific rule wins)
    3. at the same level, EXCLUDE beats INCLUDE
    4. if any include is given it acts as a whitelist — anything not included
       is dropped. With only excludes, everything else is kept.
    Identifying fields are removed regardless of what is listed.

  So:
    --forms=A                        every variable of A
    --forms=A --exclude-vars=x       A without x
    --exclude-forms=A --vars=x       only x, even though x is in A
    --exclude-forms=A                everything except A

  A name that is not in the data dictionary stops the run — it is almost
  always a typo. --allow-missing-fields proceeds anyway.

FINDING THE NAMES
  --list-forms           list every form with its variable count, then stop
  --vars-of=FORM         list that form's variables, then stop

OTHER OPTIONS
  --project=NAME         default Emollient
  --out-dir=DIR          default $OUTDIR
  --prefix=NAME          filename prefix inside the bundle (default emol)
  --no-stata             skip the Stata files
  --keep-work            keep work/ — WARNING: it holds identifiable data
  --allow-no-repeats     do not fail when no repeat-instance columns are found
  -h, --help             this text
EOF
  if [ "$ROUTE" = "R" ]; then
    cat <<'EOF'
  --sample=N             pivot only N babies, for a quick trial
  --template=FILE        force the engine's full column set (blueprint_cols.php)
  --reuse-raw            reuse work/raw.csv instead of re-pulling from REDCap
                         (only works if the previous run used --keep-work)
EOF
  else
    cat <<'EOF'
  --mem=SIZE             memory limit for the transformer (default 4G)
  --report=NAME          default WideDump
EOF
  fi
  cat <<EOF

EXAMPLES
  bash tools/$me --list-forms
  bash tools/$me --forms=daily_control_log,daily_interventionemolliation_form
  bash tools/$me --forms=daily_control_log --vars=nss_sepsis,nss_hosp_code
  bash tools/$me --forms=daily_control_log --exclude-vars=int_oil_qty
  bash tools/$me --exclude-forms=serious_adverse_event
  bash tools/$me --fields=tools/fields.example.txt

EOF
  exit 0
}

step()  { printf '\n\033[1m==> %s\033[0m\n' "$1" | tee -a "$LOG"; }
note()  { printf '    %s\n' "$1" | tee -a "$LOG"; }
warn()  { printf '    \033[33m%s\033[0m\n' "$1" | tee -a "$LOG"; }
fail()  { printf '\n\033[31mFAILED: %s\033[0m\n' "$1" | tee -a "$LOG"; exit 1; }
secs()  { date +%s; }
ncols() { head -1 "$1" | tr ',' '\n' | wc -l | tr -d ' '; }
nrecs() { php -r '$f=fopen($argv[1],"r");fgetcsv($f,0,",","\"","");$n=0;while(fgetcsv($f,0,",","\"","")!==false)$n++;echo $n;' "$1"; }

lib_init() {
  cd "$(dirname "$0")/.."
  ROOT="$(pwd)"
  BUNDLE="$OUTDIR/bundle"
  WORK="$OUTDIR/work"
  LOG="$OUTDIR/build.log"
  mkdir -p "$BUNDLE" "$WORK"
  : > "$LOG"
  rm -f "$BUNDLE"/*          # never leave a stale file from a previous run
  T0=$(secs)
  DONE=0
  trap lib_on_exit EXIT
}

# Runs however the script ends. On failure, a half-built bundle/ is exactly the
# folder that gets sent by mistake, and work/ holds identifiable data — so both
# go, unless --keep-work was asked for.
lib_on_exit() {
  local rc=$?
  [ "$DONE" -eq 1 ] && return
  if [ -n "${BUNDLE:-}" ] && [ -d "$BUNDLE" ]; then
    rm -f "$BUNDLE"/*
    printf 'FAILED at %s — see ../build.log. Nothing in this folder is safe to send.\n' \
      "$(date '+%Y-%m-%d %H:%M')" > "$BUNDLE/BUILD_FAILED.txt"
  fi
  if [ "${KEEP_WORK:-0}" -eq 0 ] && [ -n "${WORK:-}" ]; then
    rm -rf "$WORK"
  fi
  printf '\n    bundle/ emptied and marked BUILD_FAILED; work/ %s\n' \
    "$([ "${KEEP_WORK:-0}" -eq 1 ] && echo 'KEPT (identifiable)' || echo 'deleted')" >&2
  exit "$rc"
}

lib_preflight() {
  step "Preflight"
  command -v php >/dev/null || fail "php not found"
  [ -f "$ROOT/vendor/autoload.php" ]            || fail "no vendor/autoload.php in $ROOT"
  [ -f "$ROOT/projects/$PROJECT/config.php" ]   || fail "unknown project: $PROJECT"
  [ -f "$ROOT/tools/phi_fields.txt" ]           || fail "tools/phi_fields.txt not found"
  for t in deidentify.php make_codebook.php csvcheck.php; do
    [ -f "$ROOT/tools/$t" ] || fail "tools/$t not found"
  done
  [ "$STATA" -eq 1 ] && { [ -f "$ROOT/tools/stata_prep.php" ] || fail "tools/stata_prep.php not found"; }
  if [ "$SEL_ON" -eq 1 ]; then
    [ -f "$ROOT/tools/select_fields.php" ] || fail "tools/select_fields.php not found"
    [ -f "$ROOT/tools/export_field_map.php" ] || fail "tools/export_field_map.php not found"
    [ -n "$FIELDS" ] && { [ -f "$FIELDS" ] || fail "field list not found: $FIELDS"; }
    note "subset  : $SEL_DESC"
  fi
  note "repo    : $ROOT"
  note "project : $PROJECT"
  note "bundle  : $BUNDLE"
}

# $1 = input, $2 = output. Keeps record_id plus the listed fields / forms.
# A name that matches nothing fails the run: a typo would otherwise produce a
# smaller bundle that looks complete. --allow-missing-fields relaxes this.
# Build the selector's arguments from whatever the caller set.
lib_sel_args() {
  SEL_ARGS=( "--fieldmap=$FMAP" )
  [ -n "$INC_FORMS" ] && SEL_ARGS+=( "--forms=$INC_FORMS" )
  [ -n "$INC_VARS"  ] && SEL_ARGS+=( "--vars=$INC_VARS" )
  [ -n "$EXC_FORMS" ] && SEL_ARGS+=( "--exclude-forms=$EXC_FORMS" )
  [ -n "$EXC_VARS"  ] && SEL_ARGS+=( "--exclude-vars=$EXC_VARS" )
  [ -n "$FIELDS"    ] && SEL_ARGS+=( "--fields=$FIELDS" )
  [ "${ALLOW_MISSING:-0}" -eq 1 ] && SEL_ARGS+=( "--allow-missing" )
  return 0
}

# The dictionary, needed to map a column to its form. One metadata call.
lib_fieldmap() {
  [ -s "$FMAP" ] && return 0
  step "Field map (data dictionary)"
  php tools/export_field_map.php --project="$PROJECT" --out="$FMAP" 2>&1 | tee -a "$LOG" \
    || fail "export_field_map.php failed"
}

# --list-forms: show what can be chosen, then stop.
lib_list_forms() {
  lib_fieldmap
  php tools/select_fields.php --fieldmap="$FMAP" --list ${VARS_OF:+--vars-of="$VARS_OF"}
  [ "${KEEP_WORK:-0}" -eq 0 ] && rm -rf "$WORK"
  DONE=1
  exit 0
}

# $1 = input, $2 = output. Applies the include/exclude rules.
lib_select() {
  step "Selection — $SEL_DESC"
  lib_sel_args
  local rc=0 tmp="$OUTDIR/.select.out"
  php tools/select_fields.php "$1" --check-only "${SEL_ARGS[@]}" > "$tmp" 2>&1 || rc=$?
  tee -a "$LOG" < "$tmp" | grep -E "mode|keeping|column\(s\)|NOT IN THE|decided by" || true
  rm -f "$tmp"
  if [ "$rc" -eq 3 ] && [ "${ALLOW_MISSING:-0}" -eq 0 ]; then
    fail "a listed form or variable is not in the data dictionary — see above (--allow-missing-fields to proceed)"
  fi
  [ "$rc" -ne 0 ] && [ "$rc" -ne 3 ] && fail "select_fields.php failed (exit $rc)"
  php tools/select_fields.php "$1" "$2" "${SEL_ARGS[@]}" >> "$LOG" 2>&1 \
    || fail "select_fields.php failed writing $2"
  note "$(tail -1 "$LOG")"
}

# $1 = file to check. Fails the run if any listed field survives.
lib_gate() {
  step "De-identification gate"
  local rc=0 tmp="$OUTDIR/.gate.out"
  php tools/deidentify.php "$1" --check-only > "$tmp" 2>&1 || rc=$?
  grep -E "columns:|identifying|Nothing to remove" "$tmp" | tee -a "$LOG" || true
  rm -f "$tmp"
  [ "$rc" -eq 2 ] && fail "identifying columns are STILL present in $1"
  [ "$rc" -ne 0 ] && fail "deidentify --check-only failed (exit $rc)"
  note "clean"
}

lib_shape() {
  step "Row and column count check"
  php tools/csvcheck.php "$BUNDLE/wide.csv" 2>&1 | tee -a "$LOG" | grep -E "columns|rows|ragged"
  COLS=$(ncols "$BUNDLE/wide.csv")
  ROWS=$(nrecs "$BUNDLE/wide.csv")
  I3=$(head -1 "$BUNDLE/wide.csv" | tr ',' '\n' | grep -c '_form_3_' || true)
  note "instance-3 columns: $I3"
  if [ "$I3" -eq 0 ] && [ "$ALLOW_NO_REPEATS" -eq 0 ]; then
    if [ "$SEL_ON" -eq 1 ]; then
      warn "no instance-3 columns — expected if the chosen fields are not on a repeating form"
    else
      fail "no instance-3 columns — repeat instances have been lost. (--allow-no-repeats to override)"
    fi
  fi
}

lib_metadata() {
  step "Codebook — built from the delivered header"
  php tools/make_codebook.php --project="$PROJECT" \
      --from-header="$BUNDLE/wide.csv" --out="$BUNDLE/$PREFIX" 2>&1 | tee -a "$LOG" \
    || fail "make_codebook.php failed"
  grep -q "Mode    : FROM HEADER" "$LOG" || fail "codebook was not built from the header"
  rm -f "$BUNDLE/${PREFIX}_labels.csv"      # redundant with the codebook

  CB=$(nrecs "$BUNDLE/${PREFIX}_codebook.csv")
  [ "$CB" -eq "$COLS" ] || fail "codebook has $CB rows but wide.csv has $COLS columns"
  note "codebook rows match columns: $CB"
}

lib_stata() {
  [ "$STATA" -eq 1 ] || { note "Stata files skipped (--no-stata)"; return; }
  step "Stata files"
  php tools/stata_prep.php "$BUNDLE/wide.csv" \
      --codebook="$BUNDLE/${PREFIX}_codebook.csv" --out="$BUNDLE/$PREFIX" 2>&1 | tee -a "$LOG" \
    || fail "stata_prep.php failed"
  SC=$(ncols "$BUNDLE/${PREFIX}_stata.csv")
  [ "$SC" -eq "$COLS" ] || fail "Stata file has $SC variables, wide.csv has $COLS"
  NL=$(grep -c '^label variable' "$BUNDLE/${PREFIX}_import.do" || true)
  note "variable labels in do-file: $NL"
  [ "$NL" -gt 0 ] || fail "do-file has no variable labels"
}

lib_readme() {
  step "README"
  if [ "$SEL_ON" -eq 1 ]; then
    SUBSET_NOTE="SUBSET — $SEL_DESC
                 (the codebook describes exactly these columns, nothing more)"
  fi
  local stata_block=""
  if [ "$STATA" -eq 1 ]; then
    stata_block="
STATA
  ${PREFIX}_stata.csv       same data, variable names shortened to Stata's 32-char limit
  ${PREFIX}_import.do       run this first; imports, applies variable labels, saves .dta.
                          Keep it in the same folder as ${PREFIX}_stata.csv.
  ${PREFIX}_crosswalk.csv   short name <-> full column name

  Requires Stata/SE or Stata/MP: $COLS variables (Stata/BE stops at 2,048).
  The analyst guide's examples use the FULL names — look them up in the crosswalk.
"
  fi
  cat > "$BUNDLE/README.txt" <<EOF
${PROJECT^^} STUDY — DATA EXPORT
Extract date : $(date '+%d %B %Y %H:%M')
Produced by  : $ROUTE route
Records      : $ROWS (one row per baby)
Columns      : $COLS
Content      : ${SUBSET_NOTE:-all fields}

DATA
  wide.csv                one row per baby, full column names (R / Python)
$stata_block
METADATA
  ${PREFIX}_codebook.csv    one row per column: question label, event, form,
                          instance, type, answer options, branching logic
  ${PREFIX}_choices.csv     code -> meaning (REDCap stores Y / N / NI, not text)

COLUMN NAMES
  {event}_{form}_{instance}_{field}, e.g.
  day1_arm_1_daily_interventionemolliation_form_3_int_datetime
  = day 1, emolliation form, third session that day, date and time

THREE THINGS THAT CAUSE WRONG ANSWERS
  1. Blank is not zero and not "No" — usually the question was never shown.
     See branching_logic in the codebook.
  2. NI is its own value. Do not fold it into N.
  3. Checkboxes are one 0/1 column per option.

DE-IDENTIFIED
  $(grep -vc '^\s*#\|^\s*$' tools/phi_fields.txt) identifying fields (names, addresses, contact numbers) removed.
  The columns do not exist. record_id is a study identifier.
EOF
  note "written"
}

lib_cleanup() {
  if [ "$KEEP_WORK" -eq 1 ]; then
    warn "work/ kept — it contains IDENTIFIABLE data: $WORK"
  else
    step "Removing identifiable intermediates"
    rm -rf "$WORK"
    note "work/ deleted"
  fi
}

lib_summary() {
  DONE=1
  step "Done in $(( $(secs) - T0 ))s"
  note "SEND THIS FOLDER: $BUNDLE"
  ( cd "$BUNDLE" && ls -lh | tail -n +2 | awk '{printf "      %-28s %s\n", $9, $5}' ) | tee -a "$LOG"
  note "log: $LOG"
  [ -n "${SAMPLE:-}" ] && warn "built from a SAMPLE — rerun without --sample for the full export"
  return 0
}

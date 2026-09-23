#!/usr/bin/env bash
#
# test_install.sh — prove the installed tools work before committing.
#
#   bash tools/test_install.sh              offline checks + a live --sample run
#   bash tools/test_install.sh --offline    syntax and help only, no REDCap
#   bash tools/test_install.sh --project=X  default Emollient
#
# Exit 0 means safe to commit.
#
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 1

PROJECT=Emollient; OFFLINE=0
for a in "$@"; do
  case "$a" in
    --offline) OFFLINE=1 ;;
    --project=*) PROJECT="${a#*=}" ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "Unknown option: $a" >&2; exit 2 ;;
  esac
done

PASS=0; FAIL=0
ok()   { printf '  \033[32mPASS\033[0m  %s\n' "$1"; PASS=$((PASS+1)); }
no()   { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=$((FAIL+1)); }
hdr()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
try()  { if eval "$2" >/dev/null 2>&1; then ok "$1"; else no "$1"; fi; }

hdr "1. Syntax"
for f in tools/*.php; do
  [ -f "$f" ] || continue
  php -l "$f" >/dev/null 2>&1 && PASS=$((PASS+1)) || no "php -l $f"
done
ok "$(ls tools/*.php 2>/dev/null | wc -l | tr -d ' ') PHP files lint clean"
for f in tools/*.sh; do
  [ -f "$f" ] || continue
  bash -n "$f" 2>/dev/null || no "bash -n $f"
done
ok "shell scripts parse"
command -v Rscript >/dev/null && { Rscript -e 'invisible(parse("tools/wide_pivot.R"))' >/dev/null 2>&1 && ok "wide_pivot.R parses" || no "wide_pivot.R parses"; } \
  || printf '  \033[33mSKIP\033[0m  R not installed on this machine\n'

hdr "2. Expected files present"
for f in make_wide.sh make_wide_php.sh bundle_lib.sh select_fields.php deidentify.php \
         phi_fields.txt make_codebook.php stata_prep.php export_field_map.php csvcheck.php; do
  [ -f "tools/$f" ] && PASS=$((PASS+1)) || no "tools/$f missing"
done
ok "core tools present"

hdr "3. .env is loaded wherever it is needed"
ENVMISS=""
for f in tools/*.php; do
  grep -q "vendor/autoload" "$f" 2>/dev/null || continue
  grep -qE "config\.php|ReportFacade|RedcapApiClient" "$f" 2>/dev/null || continue
  grep -q "Dotenv" "$f" 2>/dev/null || ENVMISS="$ENVMISS $(basename "$f")"
done
if [ -z "$ENVMISS" ]; then
  ok "every tool that reads config.php also loads .env"
else
  no "missing Dotenv load:$ENVMISS"
  printf '        These will fail with "EMOLLIENT_REDCAP_URL is not set".\n'
fi

hdr "4. Help and argument handling"
try "make_wide.sh --help"              "bash tools/make_wide.sh --help"
try "make_wide_php.sh --help"          "bash tools/make_wide_php.sh --help"
try "unknown option rejected"          "! bash tools/make_wide.sh --nonsense"
try "--help creates no directories"    "rm -rf /tmp/ti_help && bash tools/make_wide.sh --out-dir=/tmp/ti_help --help && [ ! -d /tmp/ti_help ]"

hdr "5. Engine state"
if grep -q 'detectionFields' reporting-engine/Application/ReportFacade.php 2>/dev/null; then
  ok "ReportFacade has the one-field-per-form detection"
else
  printf '  \033[33mWARN\033[0m  ReportFacade does not contain detectionFields() — WideDump via PHP may lose repeats\n'
fi
grep -q 'iterator_to_array($stream, false)' reporting-engine/Application/ReportFacade.php 2>/dev/null \
  && no "ReportFacade still buffers the full stream" || ok "ReportFacade does not buffer the full stream"

if [ "$OFFLINE" -eq 1 ]; then
  hdr "Offline mode — skipping the live run"
else
  hdr "6. Live REDCap: --list-forms"
  rm -rf /tmp/ti_list
  if bash tools/make_wide.sh --project="$PROJECT" --out-dir=/tmp/ti_list --list-forms > /tmp/ti_list.out 2>&1; then
    ok "listed $(grep -cE '^  [a-z_]+ +[0-9]+$' /tmp/ti_list.out || echo '?') forms"
  else
    no "--list-forms failed"; tail -5 /tmp/ti_list.out | sed 's/^/        /'
  fi

  hdr "7. Live REDCap: small bundle (--sample=50)"
  rm -rf /tmp/ti_run
  if bash tools/make_wide.sh --project="$PROJECT" --out-dir=/tmp/ti_run --sample=50 > /tmp/ti_run.out 2>&1; then
    ok "bundle built"
    B=/tmp/ti_run/bundle
    C=$(head -1 "$B/wide.csv" | tr ',' '\n' | wc -l | tr -d ' ')
    R=$(php -r '$f=fopen($argv[1],"r");fgetcsv($f,0,",","\"","");$n=0;while(fgetcsv($f,0,",","\"","")!==false)$n++;echo $n;' "$B/wide.csv")
    CB=$(php -r '$f=fopen($argv[1],"r");fgetcsv($f,0,",","\"","");$n=0;while(fgetcsv($f,0,",","\"","")!==false)$n++;echo $n;' "$B/emol_codebook.csv")
    printf '        %s columns, %s rows, codebook %s rows\n' "$C" "$R" "$CB"
    [ "$C" = "$CB" ] && ok "codebook matches the column count" || no "codebook has $CB rows, wide.csv has $C columns"
    php tools/deidentify.php "$B/wide.csv" --check-only >/dev/null 2>&1 && ok "no identifying columns" || no "identifying columns present"
    [ ! -d /tmp/ti_run/work ] && ok "identifiable work/ removed" || no "work/ still present"
    [ -f "$B/README.txt" ] && ok "README written" || no "README missing"
  else
    no "sample run failed"; tail -12 /tmp/ti_run.out | sed 's/^/        /'
  fi
fi

printf '\n\033[1m%d passed, %d failed\033[0m\n' "$PASS" "$FAIL"
if [ "$FAIL" -eq 0 ]; then
  echo "Safe to commit."
  rm -rf /tmp/ti_list /tmp/ti_run /tmp/ti_help /tmp/ti_list.out /tmp/ti_run.out
  exit 0
fi
echo "Do NOT commit until these are resolved. Output kept in /tmp/ti_*"
exit 1

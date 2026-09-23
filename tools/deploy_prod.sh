#!/usr/bin/env bash
#
# deploy_prod.sh — pull on production and verify.
#
#   bash tools/deploy_prod.sh              pull, offline tests, smoke test
#   bash tools/deploy_prod.sh --live       also run the live 50-baby bundle test
#   bash tools/deploy_prod.sh --yes        no confirmation prompt
#
# It refuses to pull when the working tree has changes that a pull could
# clobber, and reports what moved.
#
set -uo pipefail

LIVE=0; YES=0
for a in "$@"; do
  case "$a" in
    --live)   LIVE=1 ;;
    --yes|-y) YES=1 ;;
    -h|--help) sed -n '2,10p' "$0"; exit 0 ;;
    *) echo "Unknown option: $a" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
die()  { printf '\n\033[31mSTOPPED: %s\033[0m\n' "$1"; exit 1; }
ask()  { [ "$YES" -eq 1 ] && return 0; read -r -p "    $1 [y/N] " r; [ "$r" = y ] || [ "$r" = Y ]; }

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "not inside a git repo"
cd "$ROOT"

step "Before"
echo "    $ROOT   branch $(git rev-parse --abbrev-ref HEAD)   at $(git rev-parse --short HEAD)"
TRACKED=$(git status --porcelain | grep -vE '^\?\?' || true)
if [ -n "$TRACKED" ]; then
  echo "    Local changes to tracked files:"
  echo "$TRACKED" | sed 's/^/      /'
  ask "a pull may conflict with these — continue?" || die "cancelled"
fi
UNTRACKED=$(git status --porcelain | grep -E '^\?\?' || true)
[ -n "$UNTRACKED" ] && { echo "    Untracked (ignored by the pull):"; echo "$UNTRACKED" | sed 's/^/      /'; }

step "Pull"
BEFORE=$(git rev-parse HEAD)
git pull || die "pull failed"
AFTER=$(git rev-parse HEAD)

if [ "$BEFORE" = "$AFTER" ]; then
  echo "    already up to date"
else
  echo "    $(git rev-parse --short $BEFORE) -> $(git rev-parse --short $AFTER)"
  git diff --name-only "$BEFORE" "$AFTER" | sed 's/^/      /'
fi

step "Environment"
if [ -r .env ]; then echo "    .env readable as $(whoami)"; else die ".env is not readable as $(whoami) — every REDCap call will fail"; fi
command -v Rscript >/dev/null && echo "    R present: $(Rscript -e 'cat(as.character(getRversion()))' 2>/dev/null)" \
  || echo "    R not installed — make_wide.sh will stop at preflight, use make_wide_php.sh"

step "Tests"
if [ "$LIVE" -eq 1 ]; then bash tools/test_install.sh || die "tests failed"
else bash tools/test_install.sh --offline || die "tests failed"; fi

step "Smoke test (live REDCap)"
if php tools/diag_nicu_values.php --project=Emollient 2>&1 | head -4; then
  echo "    REDCap reachable"
else
  die "REDCap call failed"
fi

step "Done"
echo "    production is at $(git rev-parse --short HEAD)"

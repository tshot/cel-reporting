#!/usr/bin/env bash
#
# deploy_dev.sh — install the package, test it, then commit and push.
#
#   bash deploy_dev.sh ~/Downloads/cel-tools-v4.zip
#   bash deploy_dev.sh ~/Downloads/cel-tools-v4.zip --offline    skip the live REDCap test
#   bash deploy_dev.sh ~/Downloads/cel-tools-v4.zip --yes        no confirmation prompts
#   bash deploy_dev.sh ~/Downloads/cel-tools-v4.zip --no-push    commit but do not push
#
# It stops at the first failure and never commits anything the tests did not pass.
# Only tools/ and docs/ are staged — unrelated work in progress is left alone.
#
set -uo pipefail

ZIP=""; OFFLINE=""; YES=0; PUSH=1; EXTRA=""
for a in "$@"; do
  case "$a" in
    --offline)      OFFLINE="--offline" ;;
    --yes|-y)       YES=1 ;;
    --no-push)      PUSH=0 ;;
    --with-engine|--with-shared|--all) EXTRA="$EXTRA $a" ;;
    -h|--help)      sed -n '2,13p' "$0"; exit 0 ;;
    -*)             echo "Unknown option: $a" >&2; exit 2 ;;
    *)              ZIP="$a" ;;
  esac
done

[ -n "$ZIP" ] || { echo "Usage: bash deploy_dev.sh <package.zip> [--offline] [--yes] [--no-push]" >&2; exit 2; }
[ -f "$ZIP" ] || { echo "No such file: $ZIP" >&2; exit 1; }

step() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }
die()  { printf '\n\033[31mSTOPPED: %s\033[0m\n' "$1"; exit 1; }
ask()  { [ "$YES" -eq 1 ] && return 0; read -r -p "    $1 [y/N] " r; [ "$r" = y ] || [ "$r" = Y ]; }

ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" || die "not inside a git repo — cd to /var/www/reports first"
cd "$ROOT"

step "Repo"
echo "    $ROOT   branch $(git rev-parse --abbrev-ref HEAD)"
DIRTY_OTHER=$(git status --porcelain | grep -vE '^\?\?' | grep -vE ' (tools|docs)/' || true)
if [ -n "$DIRTY_OTHER" ]; then
  echo "    Uncommitted changes outside tools/ and docs/ — these will NOT be staged:"
  echo "$DIRTY_OTHER" | sed 's/^/      /'
fi

step "Unpack"
TMP=$(mktemp -d /tmp/celpkg.XXXXXX)
unzip -q "$ZIP" -d "$TMP" || die "unzip failed"
[ -f "$TMP/install.sh" ] || die "no install.sh in the zip — is this the right package?"
[ -f "$TMP/VERSION.txt" ] && sed -n '1,3p' "$TMP/VERSION.txt" | sed 's/^/    /'

step "Install"
bash "$TMP/install.sh" $EXTRA || die "install reported failures"

step "Test"
bash tools/test_install.sh $OFFLINE || die "tests failed — nothing has been committed"

step "Changes to be committed"
git add -A tools docs
git status --short -- tools docs | sed 's/^/    /'
CHANGES=$(git diff --cached --name-only -- tools docs | wc -l | tr -d ' ')
if [ "$CHANGES" -eq 0 ]; then
  echo "    nothing changed — already up to date"
  rm -rf "$TMP"; exit 0
fi

MSG="tools: update from $(basename "$ZIP")"
[ -f "$TMP/VERSION.txt" ] && MSG="tools: update from package $(sed -n 's/^version *: *//p' "$TMP/VERSION.txt")"

step "Commit"
echo "    message: $MSG"
ask "commit these $CHANGES file(s)?" || { git reset -q -- tools docs; die "cancelled — nothing committed"; }
git commit -q -m "$MSG" || die "commit failed"
echo "    committed $(git rev-parse --short HEAD)"

if [ "$PUSH" -eq 1 ]; then
  step "Push"
  ask "push to $(git remote get-url origin 2>/dev/null || echo origin)?" || { echo "    not pushed — run 'git push' when ready"; rm -rf "$TMP"; exit 0; }
  git push || die "push failed"
fi

rm -rf "$TMP"
step "Done"
echo "    Now on production:  bash tools/deploy_prod.sh"

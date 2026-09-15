#!/bin/bash
# patch_aggregators.sh
#
# Patches every aggregator on the server that still says
#   implements AggregatorInterface
# to instead say
#   extends AbstractAggregator
#
# Also swaps the use statement from AggregatorInterface to AbstractAggregator.
#
# Safe to run multiple times — already-patched files are skipped.
#
# Usage (from /var/www):
#   bash tools/patch_aggregators.sh

set -euo pipefail

SEARCH_DIRS=(
    "projects"
    "shared-lib/src"
)

PATCHED=0
SKIPPED=0
ERRORS=0

for dir in "${SEARCH_DIRS[@]}"; do
    while IFS= read -r -d '' file; do

        # Skip interfaces, registries, factories, and the abstract base itself
        case "$file" in
            *Interface* | *Registry* | *Factory* | *Abstract*) continue ;;
        esac

        # Only process files that still use implements AggregatorInterface
        if ! grep -q "implements AggregatorInterface" "$file" 2>/dev/null; then
            SKIPPED=$((SKIPPED + 1))
            continue
        fi

        echo "  Patching: $file"

        # 1. Swap use statement
        sed -i \
            's|use CEL\\\\Shared\\\\Domain\\\\Aggregator\\\\AggregatorInterface;|use CEL\\Shared\\Domain\\Aggregator\\AbstractAggregator;|g' \
            "$file"

        # 2. Swap class declaration
        #    Handles: implements AggregatorInterface
        #    Handles: implements AggregatorInterface, SecondPassAggregatorInterface
        sed -i \
            's| implements AggregatorInterface, SecondPassAggregatorInterface| extends AbstractAggregator|g' \
            "$file"
        sed -i \
            's| implements AggregatorInterface| extends AbstractAggregator|g' \
            "$file"

        # 3. Remove SecondPassAggregatorInterface use statement if present
        sed -i \
            '/use CEL\\Shared\\Domain\\Aggregator\\SecondPassAggregatorInterface;/d' \
            "$file"

        PATCHED=$((PATCHED + 1))

    done < <(find "$dir" -name "*Aggregator*.php" -print0 2>/dev/null)
done

echo ""
echo "Done. Patched: $PATCHED  |  Skipped (already OK): $SKIPPED  |  Errors: $ERRORS"
echo ""
echo "Verify with:"
echo "  grep -rn 'implements AggregatorInterface' projects/ shared-lib/src/ | grep -v Interface | grep -v Abstract"

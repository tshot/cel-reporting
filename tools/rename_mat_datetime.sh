#!/usr/bin/env bash
# Replace mat_datetime with mat_start_time in the Weekly Meeting files.
# Run from anywhere:  bash /var/www/reports/tools/rename_mat_datetime.sh
# Keeps a .bak copy of every file it changes; delete those once the report is checked.
set -euo pipefail

ROOT=/var/www/reports
FILES=(
  projects/Emollient/reports.php
  projects/Emollient/Aggregator/BaselineChecksAggregator.php
  projects/Emollient/Aggregator/WeeklyMeetingAggregator.php
  projects/Emollient/Export/WeeklyMeetingHtmlExporter.php
  projects/Emollient/Export/WeeklyMeetingCsvExporter.php
  projects/Emollient/Export/WeeklyMeetingExcelExporter.php
)

cd "$ROOT"
total=0
for f in "${FILES[@]}"; do
  [ -f "$f" ] || { echo "MISSING: $f"; exit 1; }
  n=$(grep -c 'mat_datetime' "$f" || true)
  if [ "$n" -gt 0 ]; then
    cp -p "$f" "$f.bak"
    sed -i 's/mat_datetime/mat_start_time/g' "$f"
    php -l "$f" >/dev/null || { echo "SYNTAX ERROR in $f, restoring backup"; mv "$f.bak" "$f"; exit 1; }
    echo "changed $n  $f"
    total=$((total + n))
  fi
done

echo "Total replacements: $total (expected 13)"
echo "Left over (should be none):"
grep -rn 'mat_datetime' "${FILES[@]}" || echo "  none"

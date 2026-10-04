#!/usr/bin/env python3
"""
patch_report_titles.py — let a report carry a display title separate from its
array key.

    python3 patch_report_titles.py          dry run: report, change nothing
    python3 patch_report_titles.py go       apply
    python3 patch_report_titles.py go /other/root

WHAT AND WHY
------------
A report's array key in reports.php is its identity: it is the report= value in
every URL and bookmark, and the stem the exporter aliases are built from. It is
also, today, the only thing shown to users — the home-page menu and the report
toolbar both print the key, so "MonthlySiteSummary" is what people read.

This adds an optional 'title' key. Where a report defines one, the menu and the
toolbar show it; where it does not, they show the array key exactly as now. The
key itself is never touched, so no URL changes.

TWO EDITS, both in reporting-engine/Presentation/Api/ReportController.php:

  1. the home-page menu row     — $reportDef is already in scope
  2. the report toolbar title   — the definition is loaded for this one

SAFETY
  - Dry run unless 'go' is given.
  - ALL-OR-NOTHING: both anchors must match before anything is written.
  - Idempotent: a file already carrying the marker is left alone.
  - Backs up to <file>.bak-YYYYmmdd-HHMMSS.
  - Runs php -l afterwards and RESTORES THE BACKUP if the result does not
    parse. This file is on the path of every report in every project; a syntax
    error here takes down the whole platform, not one report.
"""

import datetime
import pathlib
import re
import shutil
import subprocess
import sys

args = sys.argv[1:]
MODE = "go" if "go" in args else "dry"
rest = [a for a in args if a != "go"]
ROOT = pathlib.Path(rest[0] if rest else "/var/www/reports")
TARGET = ROOT / "reporting-engine/Presentation/Api/ReportController.php"

MARKER = "reportDisplayTitle"

print(f"file: {TARGET}")
print(f"mode: {'APPLY' if MODE == 'go' else 'dry run (nothing will be written)'}\n")

if not TARGET.is_file():
    sys.exit(f"!! not found: {TARGET}")

src = TARGET.read_text(encoding="utf-8")

if MARKER in src:
    print("Already patched. Nothing to do.")
    sys.exit(0)

problems = []
out = src

# ── edit 1: the home-page menu row ───────────────────────────────────────
MENU_OLD = """. "<td class='report-name'><strong>{$reportName}</strong></td>\""""
MENU_NEW = (
    """. "<td class='report-name'><strong>"\n"""
    """                        . $this->reportDisplayTitle($reportDef, $reportName)\n"""
    """                        . "</strong></td>\""""
)
if src.count(MENU_OLD) == 1:
    out = out.replace(MENU_OLD, MENU_NEW)
    print("  ok       menu row will use the title")
else:
    problems.append(f"menu row anchor matched {src.count(MENU_OLD)} times, expected 1")

# ── edit 2: the toolbar title ────────────────────────────────────────────
TB_OLD = """<span class='toolbar-title'>{$report}</span>"""
TB_NEW = """<span class='toolbar-title'>{$toolbarTitle}</span>"""
if out.count(TB_OLD) == 1:
    out = out.replace(TB_OLD, TB_NEW)
    print("  ok       toolbar will use the title")
else:
    problems.append(f"toolbar anchor matched {out.count(TB_OLD)} times, expected 1")

# the assignment, immediately before the toolbar's return
RET_OLD = '''        return "
<div class='report-toolbar'>'''
RET_NEW = '''        // Display title from reports.php 'title'; the array key otherwise.
        $toolbarTitle = $this->reportDisplayTitle(
            $this->loadReportsConfig($project)[$report] ?? [], $report
        );

        return "
<div class='report-toolbar'>'''
if out.count(RET_OLD) == 1:
    out = out.replace(RET_OLD, RET_NEW)
    print("  ok       toolbar title will be resolved")
else:
    problems.append(f"toolbar return anchor matched {out.count(RET_OLD)} times, expected 1")

# ── the helper itself, appended as the last method of the class ──────────
HELPER = '''
    /**
     * A report's display name.
     *
     * The array key in reports.php is the report's identity — it is the
     * report= value in every URL and the stem of the exporter aliases — so it
     * must not change when someone wants a longer or friendlier name. A report
     * may set 'title' for that; everything without one keeps showing its key,
     * exactly as before.
     */
    private function reportDisplayTitle(array $reportDef, string $fallback): string
    {
        $title = trim((string)($reportDef['title'] ?? ''));
        return htmlspecialchars(
            $title !== '' ? $title : $fallback,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
'''
m = re.search(r"\n\}\s*\r?\n?\s*$", out)
if m:
    out = out[:m.start()] + "\n" + HELPER + out[m.start():]
    print("  ok       helper method will be added")
else:
    problems.append("could not find the end of the class to append the helper")

# ── report ───────────────────────────────────────────────────────────────
if problems:
    print()
    for p in problems:
        print("  PROBLEM  " + p)
    sys.exit(
        "\n!! Aborted — nothing was written.\n"
        "   Paste the lines around the anchor named above and I will adjust it."
    )

if MODE != "go":
    print("\nDry run. Nothing written. Re-run with:")
    print(f"  python3 {pathlib.Path(sys.argv[0]).name} go")
    sys.exit(0)

# ── write, then verify, then roll back if it does not parse ──────────────
stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
backup = TARGET.with_suffix(TARGET.suffix + f".bak-{stamp}")
shutil.copy2(TARGET, backup)
TARGET.write_text(out, encoding="utf-8")
print(f"\n  wrote    {TARGET.name}  (backup: {backup.name})")

r = subprocess.run(["php", "-l", str(TARGET)], capture_output=True, text=True)
if r.returncode != 0:
    shutil.copy2(backup, TARGET)
    print("\n" + (r.stdout + r.stderr).strip())
    sys.exit(
        "\n!! The patched file does not parse — the ORIGINAL HAS BEEN RESTORED.\n"
        "   Nothing is broken. Send me the error above."
    )

print("  parses   php -l clean")
print(f"""
Next:
  1. In projects/Emollient/reports.php, inside 'MonthlySiteSummary':
       'title' => 'Monthly Site Summary for Enrollment/DOPR/LAMA/SAE',

  2. Reload the home page. That report's row shows the new name; every other
     report is unchanged, because none of them set 'title'.

  3. Open the report: the toolbar shows it too.

  To undo: cp {backup.name} {TARGET.name}
""")

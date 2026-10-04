#!/usr/bin/env python3
"""
wire_monthly_summary.py — make the three registration edits that put the
Monthly Site Summary report in the reports menu.

    python3 wire_monthly_summary.py          dry run: report, change nothing
    python3 wire_monthly_summary.py go       apply
    python3 wire_monthly_summary.py go /other/root

Edits, in one pass:

  1. shared-lib/src/Domain/Export/ExporterRegistry.php
       three alias -> class lines, after the weekly_meeting_csv line

  2. projects/Emollient/Aggregator/AggregatorRegistry.php
       a register() block at the end of boot()

  3. projects/Emollient/reports.php
       the MonthlySiteSummary report definition, before the closing ];
       (position in the file is cosmetic — 'group' decides where it appears
       in the menu)

SAFETY
  - Dry run unless 'go' is passed.
  - ALL-OR-NOTHING: every anchor must be found in every file before anything
    is written. A partial wiring is worse than none, because the report then
    half-exists and fails somewhere unobvious.
  - Idempotent: a file already carrying 'monthly_site_summary' is left alone.
    Running twice is safe. This matters — a patch applied in two passes is
    what doubled the Pending accumulator in the Discharge Form Completion
    report, and nobody noticed until a cross-check caught it.
  - Backs up each file to <file>.bak-YYYYmmdd-HHMMSS before writing.
  - Does NOT copy the aggregator or exporter files. Copy those first; the
    script checks they are in place and refuses if they are not.
"""

import datetime
import pathlib
import re
import shutil
import sys

# ── arguments ────────────────────────────────────────────────────────────
args = sys.argv[1:]
MODE = "go" if "go" in args else "dry"
rest = [a for a in args if a != "go"]
ROOT = pathlib.Path(rest[0] if rest else "/var/www/reports")

print(f"root: {ROOT}")
print(f"mode: {'APPLY' if MODE == 'go' else 'dry run (nothing will be written)'}\n")

MARKER = "monthly_site_summary"

# ── the files that must already be copied into place ─────────────────────
REQUIRED = [
    "projects/Emollient/Aggregator/MonthlySiteSummaryAggregator.php",
    "projects/Emollient/Export/MonthlySiteSummaryHtmlExporter.php",
    "projects/Emollient/Export/MonthlySiteSummaryCsvExporter.php",
    "projects/Emollient/Export/MonthlySiteSummaryExcelExporter.php",
]

print("-- class files in place --")
missing = []
for rel in REQUIRED:
    p = ROOT / rel
    print(f"  {'ok     ' if p.is_file() else 'MISSING'} {rel}")
    if not p.is_file():
        missing.append(rel)
if missing:
    sys.exit(
        "\n!! Copy the class files first — this script only does the wiring.\n"
        "   Nothing was changed."
    )

# ── the three edits ──────────────────────────────────────────────────────
EXPORTER_LINES = (
    "        'monthly_site_summary'       => \\CEL\\Projects\\Emollient\\Export\\MonthlySiteSummaryHtmlExporter::class,\n"
    "        'monthly_site_summary_csv'   => \\CEL\\Projects\\Emollient\\Export\\MonthlySiteSummaryCsvExporter::class,\n"
    "        'monthly_site_summary_excel' => \\CEL\\Projects\\Emollient\\Export\\MonthlySiteSummaryExcelExporter::class,\n"
)

AGG_BLOCK = """
        // Monthly Site Summary — Enrollment, LAMA, DOPR and SAE by site and
        // month, on both the event and enrolment-cohort bases. See the class
        // docblock for why the two can disagree.
        self::register('monthly_site_summary', fn($pk, $cfg) =>
            new MonthlySiteSummaryAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null,
                is_file(__DIR__ . '/../site_labels.php')
                    ? require __DIR__ . '/../site_labels.php'
                    : [],
                (bool)($cfg['include_post_28'] ?? false)
            )
        );
"""

REPORT_BLOCK = """
    /*
    |--------------------------------------------------------------------------
    | MONTHLY SITE SUMMARY  (TSC)
    |--------------------------------------------------------------------------
    | Enrollment, LAMA, DOPR and SAE by site and month.
    |
    |   Enrolled  enr_consent_granted = Yes and enr_datetime present
    |   LAMA      dis_discharge_type = TYP_LAMA
    |   DOPR      dis_discharge_type = TYP_DOPR
    |   SAE       sae_start_date (Q8) present  -- NOT Q5 sae_datetime
    |
    | An event counts in the month of its own date, and only when that date is
    | recorded. Anything recorded without its date is reported under Data notes
    | rather than silently dropped.
    |
    | NOT counted: discharges on the "after 28 days of stay" form. Set
    | 'include_post_28' => true to include them.
    |
    | URL:
    |   ?project=Emollient&report=MonthlySiteSummary&format=html
    |   ...&format=excel
    |   ...&sites=JSS,SNMC&date_from=2026-04-01&date_to=2026-09-30
    |--------------------------------------------------------------------------
    */
    'MonthlySiteSummary' => [
        'group'            => 'Clinical Reports',
        'mode'             => 'aggregate',
        'aggregator'       => 'monthly_site_summary',
        'exporter'         => 'monthly_site_summary',
        'formats'          => ['html', 'download_html', 'pdf', 'csv', 'excel'],
        'site_code_field'  => 'enr_hosp_code',
        'site_labels_path' => __DIR__ . '/site_labels.php',
        'date_from'        => STUDY_START,
        'date_to'          => date('Y-m-d'),
        'events'           => ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1'],
        'fields'           => [
            'record_id',
            // enrolment (day0_arm_1)
            'enr_datetime', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
            // discharge (discharge_arm_1)
            'dis_datetime', 'dis_discharge_type', 'dis_in_hosp', 'dis_hosp_code',
            // SAE (other_forms_arm_1) -- sae_datetime is fetched only so the
            // report can say how Q5 and Q8 compare; it never dates an SAE.
            'sae_start_date', 'sae_datetime', 'sae_hosp_code',
        ],
    ],
"""

# ── plan each edit without writing ───────────────────────────────────────
plans = []   # (path, new_text, description)
skipped = []
problems = []


def plan(rel, fn, desc):
    path = ROOT / rel
    if not path.is_file():
        problems.append(f"not found: {rel}")
        return
    src = path.read_text(encoding="utf-8")
    if MARKER in src:
        skipped.append(f"{rel} (already wired)")
        return
    out = fn(src)
    if out is None:
        problems.append(f"anchor not found in {rel}")
        return
    plans.append((path, out, desc))


# 1 ── ExporterRegistry: after the weekly_meeting_csv alias line
def edit_exporter(src):
    m = re.search(
        r"^[ \t]*'weekly_meeting_csv'\s*=>\s*\\?CEL\\Projects\\Emollient\\Export\\"
        r"WeeklyMeetingCsvExporter::class,[ \t]*\r?\n",
        src, re.MULTILINE)
    if not m:
        return None
    return src[:m.end()] + EXPORTER_LINES + src[m.end():]


# 2 ── AggregatorRegistry: at the end of boot()
def edit_aggregator(src):
    if "'clinical_outcomes'" not in src:
        return None                       # not the file we think it is
    # boot() is the last method; close it after our block
    m = re.search(r"\n[ \t]*\}\s*\n\}\s*\r?\n?\s*$", src)
    if not m:
        return None
    return src[:m.start()] + "\n" + AGG_BLOCK + src[m.start():]


# 3 ── reports.php: before the closing ];  ('group' decides menu placement,
#      so where it sits in the file is cosmetic)
def edit_reports(src):
    m = re.search(r"\n\];\s*\r?\n?\s*$", src)
    if not m:
        return None
    return src[:m.start()] + "\n" + REPORT_BLOCK + src[m.start():]


plan("shared-lib/src/Domain/Export/ExporterRegistry.php",
     edit_exporter, "3 exporter alias lines")
plan("projects/Emollient/Aggregator/AggregatorRegistry.php",
     edit_aggregator, "monthly_site_summary aggregator registration")
plan("projects/Emollient/reports.php",
     edit_reports, "MonthlySiteSummary report definition")

# ── report ───────────────────────────────────────────────────────────────
print("\n-- planned edits --")
for path, _, desc in plans:
    print(f"  edit     {path.relative_to(ROOT)}  ({desc})")
for s in skipped:
    print(f"  skip     {s}")
for p in problems:
    print(f"  PROBLEM  {p}")

if problems:
    sys.exit(
        "\n!! Aborted — nothing was written.\n"
        "   A half-wired report fails somewhere unobvious, so this is "
        "all-or-nothing.\n"
        "   Paste the surrounding lines of the file named above and I'll "
        "adjust the anchor."
    )

if not plans:
    print("\nAlready wired. Nothing to do.")
    sys.exit(0)

if MODE != "go":
    print(f"\nDry run. {len(plans)} file(s) would change. Re-run with:")
    print(f"  python3 {pathlib.Path(sys.argv[0]).name} go")
    sys.exit(0)

# ── write ────────────────────────────────────────────────────────────────
stamp = datetime.datetime.now().strftime("%Y%m%d-%H%M%S")
print()
for path, text, _ in plans:
    backup = path.with_suffix(path.suffix + f".bak-{stamp}")
    shutil.copy2(path, backup)
    path.write_text(text, encoding="utf-8")
    print(f"  wrote    {path.relative_to(ROOT)}   (backup: {backup.name})")

print(f"""
Next:
  cd {ROOT}
  php -l shared-lib/src/Domain/Export/ExporterRegistry.php
  php -l projects/Emollient/Aggregator/AggregatorRegistry.php
  php -l projects/Emollient/reports.php

  php tools/test_agg.php              # expect ALL PASS, 43 passed
  php tools/run_monthly_summary.php --html=/tmp/tsc.html

  Then the menu: Clinical Reports -> Monthly Site Summary
  or ?project=Emollient&report=MonthlySiteSummary&format=html

  Re-run this script once more: it must report "Already wired" and write
  nothing.

  To undo: restore the three .bak-{stamp} files.
""")

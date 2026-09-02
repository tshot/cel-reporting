<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * MissingDailyMonitoringCsvExporter
 *
 * A focused, single-purpose CSV export for one question: "which daily
 * monitoring forms are still outstanding, and when were they due?"
 *
 * Reuses the SAME 'form_completion' aggregator/config already wired for the
 * 'DailyMonitoringCompletion' report (see reports.php) — no aggregator or
 * config changes were needed. This is a separate exporter/report entry
 * ('MissingDailyMonitoringForm') rather than a change to the existing
 * DailyMonitoringCompletion CSV, so nothing already consuming that report's
 * CSV (record_id/site/arm/day/status) is affected.
 *
 * Output: one row per (baby, missing day). Columns, in the order requested:
 *   record_id   — baby id
 *   event_name  — "Day1".."Day28" (the missing day, REDCap-style)
 *   facility    — enr_hosp_code, the raw facility code (not a mapped label)
 *   due_date    — enr_baby_dob + day number, Y-m-d
 *
 * Sorted by facility, then by day number ascending. Day number and REDCap's
 * own internal event ordinal (day1_arm_1 = event #3 .. day28_arm_1 = #30 in
 * this project's event grid) are monotonically equivalent, so sorting by day
 * number produces the same order as sorting by that ordinal would.
 *
 * "Missing" here means: due (not beyond the baby's end day — end day is
 * bounded by whichever comes first of day 28, today, or the earliest of
 * discharge/protocol-deviation/withdrawal/SAE — see FormCompletionAggregator)
 * AND daily_clinical_monitoring_complete is blank, 0, or 1 (i.e. not exactly
 * '2'/complete). The day a baby is discharged (or otherwise exits) on is
 * treated as not-yet-due rather than missing if no form was filed that same
 * day — an existing, deliberate rule in FormCompletionAggregator (teams don't
 * typically file a new daily form on the exit day itself).
 *
 * Registered as 'missing_daily_monitoring_csv'. Used by the
 * 'MissingDailyMonitoringForm' report (formats => ['csv']) via the engine's
 * {exporter}_csv convention — see ExporterRegistry / ReportController::serveCsv().
 */
class MissingDailyMonitoringCsvExporter implements ExporterInterface
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {}

    public function export(iterable $data, ?string $outputPath = null): void
    {
        $payload      = is_array($data) ? $data : iterator_to_array($data);
        $participants = $payload['participants'] ?? [];

        $handle = $outputPath
            ? fopen($outputPath, 'w')
            : fopen('php://output', 'w');

        // BOM for Excel UTF-8 compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, ['record_id', 'event_name', 'facility', 'due_date'], ',', '"', '\\');

        // Flatten to one row per (baby, missing day) first, so we can sort
        // by facility then day number across ALL babies — not just within
        // one baby at a time.
        $rows = [];
        foreach ($participants as $p) {
            $id   = $p['record_id'] ?? '';
            $site = $p['site'] ?? '';   // FormCompletionAggregator sets this from enr_hosp_code
            $dob  = !empty($p['dob']) ? \DateTime::createFromFormat('Y-m-d', $p['dob']) : null;

            foreach (($p['missing_days'] ?? []) as $day) {
                $dueDate = '';
                if ($dob instanceof \DateTime) {
                    $due = (clone $dob)->modify("+{$day} days");
                    $dueDate = $due->format('Y-m-d');
                }
                $rows[] = [
                    'record_id'  => $id,
                    'event_name' => 'Day' . $day,
                    'facility'   => $site,
                    'due_date'   => $dueDate,
                    'day'        => $day,   // kept only for sorting, not written out
                ];
            }
        }

        usort($rows, function ($a, $b) {
            return [$a['facility'], $a['day']] <=> [$b['facility'], $b['day']];
        });

        foreach ($rows as $r) {
            fputcsv($handle, [$r['record_id'], $r['event_name'], $r['facility'], $r['due_date']], ',', '"', '\\');
        }

        fclose($handle);
    }
}

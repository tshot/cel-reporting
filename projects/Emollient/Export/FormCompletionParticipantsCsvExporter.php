<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * FormCompletionParticipantsCsvExporter
 *
 * Full participant detail CSV for multi-day completion reports
 * (DailyMonitoring, SkinScoring, NeonatalSepsis, DailyEmolliation).
 *
 * One row per participant. All columns from the HTML participant detail
 * table are included, so this is suitable for analysis, archival, or
 * loading into Excel / Stata / R.
 *
 * Columns:
 *   record_id, site, site_name, arm, dob, enrolment_date,
 *   days_due, end_reason, stop_date,
 *   expected, completed, partial, parent_not_done,
 *   pct, pct_actual,
 *   missing_days, partial_days, parent_not_done_days, forms_moved_by_date
 *
 * Day-list columns are emitted as space-separated lists ("3 7 14") so
 * they survive a default CSV import without quoting issues.
 *
 * Registered as 'form_completion_participants_csv'. The engine routes
 * ?format=participants_csv to '{exporter_alias}_participants_csv'.
 */
class FormCompletionParticipantsCsvExporter implements ExporterInterface
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {}

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload      = is_array($data) ? $data : iterator_to_array($data);
        $participants = $payload['participants'] ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];

        $handle = $outputPath
            ? fopen($outputPath, 'w')
            : fopen('php://output', 'w');

        // BOM for Excel UTF-8 compatibility
        fwrite($handle, "\xEF\xBB\xBF");

        // Header row
        fputcsv($handle, [
            'record_id', 'site', 'site_name', 'arm',
            'dob', 'enrolment_date',
            'days_due', 'end_reason', 'stop_date',
            'expected', 'completed', 'partial', 'parent_not_done',
            'pct', 'pct_actual',
            'missing_days', 'partial_days', 'parent_not_done_days',
            'forms_moved_by_date',
        ], ',', '"', '\\');

        // Stable sort: site, then record_id
        uasort($participants, function ($a, $b) {
            return [$a['site'] ?? '', $a['record_id'] ?? '']
                <=> [$b['site'] ?? '', $b['record_id'] ?? ''];
        });

        foreach ($participants as $p) {
            $siteCode  = $p['site'] ?? '';
            $siteName  = $siteLabels[$siteCode] ?? $siteCode;

            // Day-number lists serialised as space-separated strings — keeps
            // them human-readable AND import-friendly in Excel/Stata/R.
            $missingDays = implode(' ', $p['missing_days'] ?? []);
            $partialDays = implode(' ', $p['partial_days'] ?? []);
            $blockedDays = implode(' ', $p['blocked_days'] ?? []);

            fputcsv($handle, [
                $p['record_id']           ?? '',
                $siteCode,
                $siteName,
                $p['arm']                 ?? '',
                $p['dob']                 ?? '',
                $p['enrollment_date']     ?? '',
                $p['end_day_display']     ?? ($p['end_day'] ?? ''),
                $p['end_reason']          ?? '',
                $p['stop_date']           ?? '',
                $p['expected']            ?? 0,
                $p['completed']           ?? 0,
                $p['partial']             ?? 0,
                $p['blocked']             ?? 0,
                $p['pct']                 ?? '',
                $p['pct_actual']          ?? '',
                $missingDays,
                $partialDays,
                $blockedDays,
                $p['forms_moved_by_date'] ?? 0,
            ], ',', '"', '\\');
        }

        fclose($handle);
    }

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void {
        $this->export($data, $outputPath);
    }
}

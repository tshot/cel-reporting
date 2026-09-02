<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * OneTimeFormCompletionIncompleteCsvExporter
 *
 * CSV companion to OneTimeFormCompletionHtmlExporter for the one-time
 * forms (Socioeconomic, BaselineGeneral, BaselineAnthro, MaternalHistory).
 *
 * Output: one row per participant whose form is still 'missing'.
 * 'not_due' (closed by PD/SW) and 'complete' rows are excluded.
 *
 * Columns:
 *   record_id, site, arm, enrollment_date, status
 *
 * Registered as 'one_time_completion_csv'. ReportController routes
 * ?format=csv to this when a report uses 'exporter' => 'one_time_completion'.
 */
class OneTimeFormCompletionIncompleteCsvExporter implements ExporterInterface
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

        // Header — keep the column count small per requirement
        // ("for single time forms we need just the id"). Site, arm, and
        // enrollment date are included for context but the file is
        // sortable/filterable on record_id alone.
        fputcsv($handle, ['record_id', 'site', 'arm', 'enrolled', 'status'], ',', '"', '\\');

        // Sort by site then record_id
        uasort($participants, function ($a, $b) {
            return [$a['site'] ?? '', $a['record_id'] ?? '']
                <=> [$b['site'] ?? '', $b['record_id'] ?? ''];
        });

        foreach ($participants as $p) {
            // Only export rows that are actually missing/incomplete.
            $status = $p['status'] ?? '';
            if ($status !== 'missing') {
                continue;
            }

            $id       = $p['record_id'] ?? '';
            $siteCode = $p['site']      ?? '';
            $site     = $siteLabels[$siteCode] ?? $siteCode;
            $arm      = $p['arm']       ?? '';
            $enrolled = $p['enr_date']  ?? '';

            fputcsv($handle, [$id, $site, $arm, $enrolled, $status], ',', '"', '\\');
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

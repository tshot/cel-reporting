<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * FormCompletionIncompleteCsvExporter
 *
 * CSV companion to FormCompletionHtmlExporter for multi-day forms
 * (DailyMonitoring, SkinScoring, NeonatalSepsis, etc.).
 *
 * Output: one row per (participant, missing-or-partial-or-parent-not-done day).
 *
 * Columns:
 *   record_id, site, arm, day, status
 *
 * - 'day' uses the same Day-N display number the HTML report shows
 *   (Day 0 if day0FormName is configured, else Day 1 onward).
 * - 'status' is one of:
 *     'missing'         — child form not done; parent IS done.
 *     'partial'         — multi-session form, some sessions on the day done.
 *     'parent_not_done' — parent form (e.g. daily monitoring) not done that
 *                         day, so child form was never expected to be filled.
 *
 * Registered as 'form_completion_csv'. The engine's ReportController
 * automatically routes ?format=csv to this exporter when a report uses
 * 'exporter' => 'form_completion'.
 */
class FormCompletionIncompleteCsvExporter implements ExporterInterface
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

        // Header
        fputcsv($handle, ['record_id', 'site', 'arm', 'day', 'status'], ',', '"', '\\');

        // Sort participants by site then record_id for predictable output
        uasort($participants, function ($a, $b) {
            return [$a['site'] ?? '', $a['record_id'] ?? '']
                <=> [$b['site'] ?? '', $b['record_id'] ?? ''];
        });

        foreach ($participants as $p) {
            $id       = $p['record_id'] ?? '';
            $siteCode = $p['site']      ?? '';
            $site     = $siteLabels[$siteCode] ?? $siteCode;
            $arm      = $p['arm']       ?? '';

            // Missing days
            foreach (($p['missing_days'] ?? []) as $d) {
                fputcsv($handle, [$id, $site, $arm, "Day $d", 'missing'], ',', '"', '\\');
            }
            // Partial days (incomplete sessions on multi-session forms)
            foreach (($p['partial_days'] ?? []) as $d) {
                fputcsv($handle, [$id, $site, $arm, "Day $d", 'partial'], ',', '"', '\\');
            }
            // Parent-not-done days (parent form not completed → child form
            // can't be filled). Surfaced separately so sites can act on the
            // upstream form rather than mistaking it for a missing child.
            foreach (($p['blocked_days'] ?? []) as $d) {
                fputcsv($handle, [$id, $site, $arm, "Day $d", 'parent_not_done'], ',', '"', '\\');
            }
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

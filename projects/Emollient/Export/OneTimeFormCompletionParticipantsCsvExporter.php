<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * OneTimeFormCompletionParticipantsCsvExporter
 *
 * Full participant detail CSV for the one-time completion reports
 * (Socioeconomic, BaselineGeneral, BaselineAnthro, MaternalHistory).
 *
 * One row per participant — every consented participant, regardless of
 * status (complete / missing / not_due). Suitable for archival,
 * analysis, or pivoting in Excel.
 *
 * Columns:
 *   record_id, site, site_name, arm, enrolled, status, close_reason
 *
 * Registered as 'one_time_completion_participants_csv'.
 */
class OneTimeFormCompletionParticipantsCsvExporter implements ExporterInterface
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

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            'record_id', 'site', 'site_name', 'arm',
            'enrolled', 'status', 'close_reason',
        ], ',', '"', '\\');

        uasort($participants, function ($a, $b) {
            return [$a['site'] ?? '', $a['record_id'] ?? '']
                <=> [$b['site'] ?? '', $b['record_id'] ?? ''];
        });

        foreach ($participants as $p) {
            $siteCode = $p['site'] ?? '';
            $siteName = $siteLabels[$siteCode] ?? $siteCode;

            fputcsv($handle, [
                $p['record_id']    ?? '',
                $siteCode,
                $siteName,
                $p['arm']          ?? '',
                $p['enr_date']     ?? '',
                $p['status']       ?? '',
                $p['close_reason'] ?? '',
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

<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * LengthOfStayCsvExporter — the per-baby audit file.
 *
 * One row per enrolled baby in the cohort, with a fixed column order. Every
 * baby appears exactly once, carrying the outcome that explains whether and
 * why an LOS was computed, so the file reconciles to the audit tables.
 *
 * Why a dedicated exporter: LengthOfStayAggregator returns a nested result
 * (patients, audit tables, statistics). The generic CsvExporter treats each
 * top-level key as a row, which produced a single row named "patients" with
 * every baby JSON-encoded into one cell.
 *
 * Registered as 'length_of_stay_csv', so ?format=csv on any report whose
 * exporter is 'length_of_stay' resolves here.
 *
 * Written RFC 4180 (empty escape character), so pandas.read_csv, R and Excel
 * read it without special arguments. A UTF-8 BOM is written for Excel; pandas
 * should read with encoding='utf-8-sig' so the first header stays 'record_id'.
 */
class LengthOfStayCsvExporter implements ExporterInterface
{
    private const COLUMNS = [
        'record_id', 'site', 'site_name', 'study_arm',
        'dob', 'enrolled_on', 'age_days', 'admission_date',
        'day28_status', 'discharge_source', 'discharge_date', 'discharge_type',
        'los_days', 'outcome', 'outcome_label', 'detail',
    ];

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {}

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload    = is_array($data) ? $data : iterator_to_array($data);
        $patients   = $payload['patients']    ?? [];
        $siteLabels = $payload['site_labels'] ?? [];

        $handle = fopen($outputPath ?: 'php://output', 'w');
        if ($handle === false) {
            throw new \RuntimeException('LengthOfStayCsvExporter: cannot open output');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::COLUMNS, ',', '"', '');

        foreach ($patients as $p)
        {
            $p['site_name'] = $siteLabels[$p['site'] ?? ''] ?? ($p['site'] ?? '');
            $row = [];
            foreach (self::COLUMNS as $c) {
                $v     = $p[$c] ?? '';
                $row[] = is_bool($v) ? ($v ? 'Y' : 'N') : (string)$v;
            }
            fputcsv($handle, $row, ',', '"', '');
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

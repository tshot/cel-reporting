<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * WeeklyMeetingCsvExporter
 *
 * Exports Weekly Meeting report as CSV matching the HTML layout:
 *   Rows    = metrics
 *   Columns = Section, Metric, Total, Site1, Site2, ...
 */
class WeeklyMeetingCsvExporter implements ExporterInterface
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {}

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload  = is_array($data) ? $data : iterator_to_array($data);
        $csv      = $this->buildCsv($payload);

        if ($outputPath) {
            file_put_contents($outputPath, $csv);
            return;
        }

        $dateFrom = $payload['period']['date_from'] ?? date('Y-m-d');
        $dateTo   = $payload['period']['date_to']   ?? date('Y-m-d');
        $filename = "WeeklyMeeting_{$dateFrom}_to_{$dateTo}.csv";

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');
        echo $csv;
    }

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void {
        $this->export($data, $outputPath);
    }

    // =========================================================================

    private function csv(array $row): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $row, ',', '"', '\\');
        rewind($handle);
        $line = stream_get_contents($handle);
        fclose($handle);
        return $line;
    }

    private function buildCsv(array $payload): string
    {
        $prescreening = $payload['prescreening'] ?? [];
        $screening    = $payload['screening']    ?? [];
        $discharge    = $payload['discharge']    ?? [];
        $metricMeta   = $payload['metricMeta']   ?? [];
        $period       = $payload['period']       ?? [];
        $dateFrom     = $period['date_from']     ?? '';
        $dateTo       = $period['date_to']       ?? '';

        // Build ordered site columns
        $sites = [];
        foreach ([$prescreening, $screening, $discharge] as $sec) {
            foreach ($sec as $siteCounts) {
                foreach (array_keys($siteCounts) as $site) {
                    if ($site !== 'Total') $sites[$site] = true;
                }
            }
        }
        $sites   = array_keys($sites);
        $columns = array_merge(['Total'], $sites);

        $out = '';

        // Report header
        $out .= $this->csv(['Weekly Meeting Report']);
        $out .= $this->csv(["Period: {$dateFrom} to {$dateTo}"]);
        $out .= $this->csv([]);

        // Column headers
        $out .= $this->csv(array_merge(['Section', 'Metric'], $columns));

        // ── Section 1: Pre-Screening ──────────────────────────────────────────
        $out .= $this->csv([]);
        $out .= $this->csv(['=== PRE-SCREENING (date field: baby_datetime) ===']);

        $lastSection    = '';
        $lastSubsection = '';
        foreach ($metricMeta as $metric => $meta) {
            if (!in_array($meta['section'] ?? '', ['PreScreened'], true)) continue;

            $section    = $meta['section']    ?? '';
            $subsection = $meta['subsection'] ?? '';
            $label      = $meta['label']      ?? $metric;

            if ($section !== $lastSection) {
                $lastSection    = $section;
                $lastSubsection = '';
                $out .= $this->csv(["--- {$section} ---"]);
            }
            if ($subsection !== '' && $subsection !== $lastSubsection) {
                $lastSubsection = $subsection;
                $out .= $this->csv(['', "  [{$subsection}]"]);
            }

            $row = [$section, $label];
            foreach ($columns as $col) {
                $row[] = $prescreening[$metric][$col] ?? 0;
            }
            $out .= $this->csv($row);
        }

        // Birth weight subsection
        $out .= $this->csv(['', '--- Birth Weight Distribution (baby_birth_wt_hosp) ---']);
        foreach ([
            'bwt_below_700'  => 'Below 700g',
            'bwt_700_999'    => '700 – 999g',
            'bwt_1000_1499'  => '1000 – 1499g',
            'bwt_1500_1800'  => '1500 – 1800g',
            'bwt_above_1800' => 'Above 1800g',
        ] as $metric => $label) {
            $row = ['Pre-Screening', $label];
            foreach ($columns as $col) {
                $row[] = $prescreening[$metric][$col] ?? 0;
            }
            $out .= $this->csv($row);
        }

        // ── Section 2: Screening & Enrollment ────────────────────────────────
        $out .= $this->csv([]);
        $out .= $this->csv(['=== SCREENING & ENROLLMENT (baby_datetime / enr_datetime) ===']);

        $lastSection    = '';
        $lastSubsection = '';
        foreach ($metricMeta as $metric => $meta) {
            if (!in_array($meta['section'] ?? '', ['Screened', 'To be Enrolled', 'Enrolled'], true)) continue;

            $section    = $meta['section']    ?? '';
            $subsection = $meta['subsection'] ?? '';
            $label      = $meta['label']      ?? $metric;

            if ($section !== $lastSection) {
                $lastSection    = $section;
                $lastSubsection = '';
                $out .= $this->csv(["--- {$section} ---"]);
            }
            if ($subsection !== '' && $subsection !== $lastSubsection) {
                $lastSubsection = $subsection;
                $out .= $this->csv(['', "  [{$subsection}]"]);
            }

            $row = [$section, $label];
            foreach ($columns as $col) {
                $row[] = $screening[$metric][$col] ?? 0;
            }
            $out .= $this->csv($row);
        }

        // ── Section 3: Discharge ──────────────────────────────────────────────
        $out .= $this->csv([]);
        $out .= $this->csv(['=== DISCHARGE ===']);

        $lastSection = '';
        foreach ($this->dischargeMeta() as $metric => $m) {
            $section   = $m['section']    ?? '';
            $label     = $m['label']      ?? $metric;
            $dateField = $m['date_field'] ?? '';

            if ($section !== $lastSection) {
                $lastSection = $section;
                $out .= $this->csv(["--- {$section}" . ($dateField ? " (date field: {$dateField})" : '') . " ---"]);
            }

            $row = [$section, $label];
            foreach ($columns as $col) {
                $row[] = $discharge[$metric][$col] ?? 0;
            }
            $out .= $this->csv($row);
        }

        return $out;
    }

    private function dischargeMeta(): array
    {
        return [
            'dis_total'                          => ['label' => 'Total Discharge',                       'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_lama_abscond'                   => ['label' => 'Total LAMA / Abscond',                  'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_dopr'                           => ['label' => 'Total DOPR',                            'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_protocol_dev'                   => ['label' => 'Protocol Deviation',                    'section' => 'Discharge',        'date_field' => 'pd_datetime'],
            'dis_sae'                            => ['label' => 'SAE',                                   'section' => 'Discharge',        'date_field' => 'sae_datetime'],
            'dis_withdrawal'                     => ['label' => 'Withdrawal',                            'section' => 'Discharge',        'date_field' => 'sw_datetime'],
            'dis_death'                          => ['label' => 'Death at Discharge',                    'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_death_fu29'                     => ['label' => 'Death at 29-day Follow-up',             'section' => 'Discharge',        'date_field' => 'fu28_datetime'],
            'dis_still_in_hospital'              => ['label' => 'Still in Hospital',                     'section' => 'Milestones',       'date_field' => 'up to date_to'],
            'dis_still_in_hospital_intervention' => ['label' => '  Intervention',                        'section' => 'Milestones',       'date_field' => ''],
            'dis_still_in_hospital_control'      => ['label' => '  Control',                             'section' => 'Milestones',       'date_field' => ''],
            'dis_28_days'                        => ['label' => '28 days completed — still in Hospital', 'section' => 'Milestones',       'date_field' => '(no date filter)'],
            'dis_fu29_done'                      => ['label' => 'Total 29-day Follow-up Completed',      'section' => '29-Day Follow-up', 'date_field' => 'fu28_datetime'],
        ];
    }
}

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
        $sepsis       = $payload['sepsis']       ?? [];
        $emollient    = $payload['emollient']    ?? [];
        $metricMeta   = $payload['metricMeta']   ?? [];
        $period       = $payload['period']       ?? [];
        $dateFrom     = $period['date_from']     ?? '';
        $dateTo       = $period['date_to']       ?? '';

        // Build ordered site columns
        $sites = [];
        foreach ([$prescreening, $screening, $discharge, $sepsis, $emollient,
                  $payload['antenatal'] ?? [], $payload['gaSource'] ?? []] as $sec) {
            foreach ($sec as $siteCounts) {
                foreach (array_keys($siteCounts) as $site) {
                    if ($site !== 'Total') $sites[$site] = true;
                }
            }
        }
        // Same site order as the HTML: site_labels order first, then any others
        $siteLabels = $payload['site_labels'] ?? [];
        $ordered    = [];
        foreach (array_keys($siteLabels) as $code) {
            if (isset($sites[$code])) $ordered[] = $code;
        }
        foreach (array_keys($sites) as $code) {
            if (!in_array($code, $ordered, true)) $ordered[] = $code;
        }
        $columns = array_merge(['Total'], $ordered);

        $out = '';

        // Report header
        $out .= $this->csv(['Weekly Meeting Report']);
        $out .= $this->csv(["Period: {$dateFrom} to {$dateTo}"]);
        $out .= $this->csv(['Generated: ' . date('Y-m-d H:i')]);
        $out .= $this->csv([]);

        // Definitions: most apparent mismatches with site registers come from these
        $out .= $this->csv(['=== DEFINITIONS (read before comparing with a site register) ===']);
        foreach ($this->definitions($dateFrom, $dateTo, $payload) as $line) {
            $out .= $this->csv(['', $line]);
        }
        $out .= $this->csv([]);

        // Column headers: same labels and order as the HTML
        $out .= $this->csv(array_merge(['Section', 'Metric'],
            array_map(fn($c) => $c === 'Total' ? 'Total' : ($siteLabels[$c] ?? $c), $columns)));

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

        // ── Section 4: Sepsis & Blood Workup ──────────────────────────────────
        $out .= $this->csv([]);
        $out .= $this->csv(['=== SEPSIS & BLOOD WORKUP (date field: nss_datetime) ===']);
        $out .= $this->metaRows($sepsis, $payload['sepsisMeta'] ?? [], $columns);

        // ── Section 5: Emolliation Stopped ────────────────────────────────────
        $out .= $this->csv([]);
        $out .= $this->csv(['=== EMOLLIATION STOPPED (date field: int_datetime) ===']);
        $out .= $this->metaRows($emollient, $payload['emollientMeta'] ?? [], $columns);

        // ── Sections 6 & 7: baseline_arm_1 checks ─────────────────────────────
        $out .= $this->csv([]);
        $out .= $this->csv(['=== ANTENATAL CORTICOSTEROIDS (date field: mat_start_time) ===']);
        $out .= $this->metaRows($payload['antenatal'] ?? [], $payload['antenatalMeta'] ?? [], $columns);
        $out .= $this->csv([]);
        $out .= $this->csv(['=== GESTATIONAL AGE SOURCES (date field: base_datetime) ===']);
        $out .= $this->metaRows($payload['gaSource'] ?? [], $payload['gaSourceMeta'] ?? [], $columns);

        return $out;
    }

    /**
     * Rows for a section described by a self-contained meta array
     * ([metric => [label, role, section]]). Sub-detail rows are indented.
     */
    private function metaRows(array $data, array $meta, array $columns): string
    {
        $out         = '';
        $lastSection = null;
        foreach ($meta as $metric => $m) {
            if (str_ends_with($metric, '_cult_other') && (int)($data[$metric]['Total'] ?? 0) === 0) continue;

            $section = $m['section'] ?? '';
            if ($section !== $lastSection) {
                $lastSection = $section;
                $out .= $this->csv(["--- {$section} ---"]);
            }
            $indent = ($m['role'] ?? '') === 'sub2' ? '  ' : '';
            $row = [$section, $indent . ($m['label'] ?? $metric)];
            foreach ($columns as $col) {
                $row[] = $data[$metric][$col] ?? 0;
            }
            $out .= $this->csv($row);
        }
        if (!$meta) $out .= $this->csv(['', 'No data for this period']);
        return $out;
    }

    /** Plain-text definitions printed at the top of the CSV (the HTML shows them as section notes). */
    private function definitions(string $dateFrom, string $dateTo, array $payload): array
    {
        $fmt = fn($d) => ($t = strtotime($d)) ? date('j M Y', $t) : $d;
        $lb  = (int)($payload['sepsisNotes']['lookback_days'] ?? 2);
        $exc = $payload['sepsisNotes']['exclusive'] ?? true;
        return [
            "Window: {$fmt($dateFrom)} to {$fmt($dateTo)}, both days included. A form counts when its date field (below) falls in the window.",
            'Counts are babies, not forms: a baby with several forms counts once per row. Exceptions: Emolliation Stopped rows that say "instances" or "study days".',
            'Date that decides the week, by section: Pre-Screening and Screening = baby_datetime (a site register may go by admission date instead); '
          . 'Enrolment = enr_datetime; Discharge = dis_datetime; Protocol deviation = pd_datetime; SAE = sae_datetime; Withdrawal = sw_datetime; '
          . '29-day follow-up = fu28_datetime; Sepsis = nss_datetime; Emolliation = int_datetime; Antenatal corticosteroids = mat_start_time; GA sources = base_datetime.',
            'No date filter (as on the report date): Still in Hospital, 28 days completed still in hospital, and more than 40 days of age (age to the last day of the window).',
            'Still in Hospital = enrolled babies with no exit. Exits: discharge form (discharged, or still admitted at day 28), protocol deviation, withdrawal. An SAE is not an exit. '
          . 'A stop form with its site left blank counts against the enrolment site.',
            'Site: the site recorded on the form; where blank, the enrolment site. Columns are in the same order as the HTML report.',
            "Sepsis: tests counted from {$lb} day(s) before the baby's first Confirmed / Suspected form up to the window end. "
          . 'Test done = ticked (CBC, CRP) or culture = Y; result = a number (TLC, CRP) or Positive / Negative; NI, blank and 9999999999 = result missing. '
          . ($exc ? 'Suspected excludes babies also Confirmed in the window.' : 'A baby with both impressions counts in both Confirmed and Suspected.'),
            'Emolliation: stopped = int_emoliate_baby N; fully stopped day = all 3 entries of the study day N (Day 0: all recorded entries). '
          . 'One instance can have several reasons, so reason rows can add up to more than the stopped count.',
            'Antenatal corticosteroids: marked = mat_antenatal_steriod Y; anything else or blank = not marked. GA: "No 1st-trimester USG" = all babies except USG marked with 1st trimester. Both arms.',
            'Percentages are shown in the HTML report only.',
            'Per-baby lists behind every count: use "Download Data (Excel)" on the HTML report (one sheet per list, e.g. Still in Hospital, Sepsis PerBaby, Emollient PerBaby).',
        ];
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
            'dis_40_days'                        => ['label' => '  of which more than 40 days of age',   'section' => 'Milestones',       'date_field' => ''],
            'dis_fu29_done'                      => ['label' => 'Total 29-day Follow-up Completed',      'section' => '29-Day Follow-up', 'date_field' => 'fu28_datetime'],
        ];
    }
}

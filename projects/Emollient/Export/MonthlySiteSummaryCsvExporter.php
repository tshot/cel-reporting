<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * MonthlySiteSummaryCsvExporter
 *
 * One rectangular CSV of the whole report, from MonthlySiteSummaryAggregator.
 *
 *   Basis, Site, Site name, Measure, <one column per month>, Total
 *
 * Three blocks, distinguished by the Basis column:
 *   Event basis        an event in the month it happened
 *   Cohort basis       an event in the month the baby was enrolled
 *   Discharge types    all seven coded values, not only LAMA and DOPR
 *
 * The third block is here and not on the report: only LAMA and DOPR are
 * displayed, but the other five are counted anyway, so a question about
 * referrals or deaths is answered by opening this file rather than by
 * changing code.
 *
 * The data notes follow as key/value rows after a blank line. They are part of
 * the export on purpose — a count that travels without the number of records
 * it had to exclude is a number nobody can check.
 *
 * ?sites= is applied by the aggregator, so this file contains exactly the
 * sites the report was asked for.
 */
class MonthlySiteSummaryCsvExporter implements ExporterInterface
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {
        // Signature fixed by ExporterRegistry; nothing needed here.
    }

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $csv     = $this->build($payload);

        if ($outputPath) {
            file_put_contents($outputPath, $csv);
            return;
        }

        $from = $payload['period']['date_from'] ?? date('Y-m-d');
        $to   = $payload['period']['date_to']   ?? date('Y-m-d');
        $name = "monthly_site_summary_{$from}_to_{$to}.csv";

        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$name}\"");
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

    // =====================================================================

    private function build(array $p): string
    {
        $months   = $p['months']       ?? [];
        $mlabels  = $p['month_labels'] ?? [];
        $sites    = $p['sites']        ?? [];
        $labels   = $p['site_labels']  ?? [];
        $measures = $p['measures']     ?? [];
        $types    = $p['discharge_type_labels'] ?? [];
        $period   = $p['period']       ?? [];

        $out = '';
        $row = function (array $r) use (&$out) { $out .= $this->line($r); };

        // ── provenance first, so a stray copy can still be identified ────
        $row(['Monthly Site Summary — Emollient']);
        $row(['Period', ($period['date_from'] ?? '') . ' to ' . ($period['date_to'] ?? '')]);
        $row(['Generated', $period['generated'] ?? '']);
        $row([]);

        if (!$months || !$sites) {
            $row(['No records matched this period and site selection.']);
            return $out;
        }

        $header = array_merge(['Basis', 'Site', 'Site name', 'Measure'], $mlabels, ['Total']);
        $row($header);

        // ── the two bases ────────────────────────────────────────────────
        foreach ([['Event basis', 'event'], ['Cohort basis', 'cohort']] as [$title, $key]) {
            $grid = $p[$key] ?? [];
            foreach ($sites as $s) {
                foreach ($measures as $mk => $mlabel) {
                    $line = [$title, $s, $labels[$s] ?? $s, $mlabel];
                    $sum  = 0;
                    foreach ($months as $m) {
                        $v = (int)($grid[$s][$m][$mk] ?? 0);
                        $line[] = $v;
                        $sum   += $v;
                    }
                    $line[] = $sum;
                    $row($line);
                }
            }
            // total line per measure, so a reader need not re-add the columns
            foreach ($measures as $mk => $mlabel) {
                $line = [$title, 'TOTAL', 'All selected sites', $mlabel];
                $sum  = 0;
                foreach ($months as $m) {
                    $v = 0;
                    foreach ($sites as $s) $v += (int)($grid[$s][$m][$mk] ?? 0);
                    $line[] = $v;
                    $sum   += $v;
                }
                $line[] = $sum;
                $row($line);
            }
        }

        // ── all seven discharge types ────────────────────────────────────
        $dt = $p['discharge_types'] ?? [];
        foreach ($sites as $s) {
            foreach ($types as $code => $tlabel) {
                $line = ['Discharge types', $s, $labels[$s] ?? $s, $tlabel . ' (' . $code . ')'];
                $sum  = 0;
                foreach ($months as $m) {
                    $v = (int)($dt[$s][$m][$code] ?? 0);
                    $line[] = $v;
                    $sum   += $v;
                }
                $line[] = $sum;
                $row($line);
            }
        }

        // ── data notes ───────────────────────────────────────────────────
        $row([]);
        $row(['DATA NOTES — records NOT included in the counts above']);
        foreach ($this->notes($p) as [$k, $v]) $row([$k, $v]);

        $row([]);
        $row(['HOW EACH FIGURE IS COUNTED']);
        foreach (($p['definitions'] ?? []) as $k => $v) $row([$k, $v]);

        $row([]);
        $row(['EXCLUDED BY DESIGN']);
        foreach (($p['exclusions'] ?? []) as $x) $row(['', $x]);

        return $out;
    }

    /** @return array<array{0:string,1:string|int}> */
    private function notes(array $p): array
    {
        $d = $p['diagnostics'] ?? [];
        $n = [
            ['Records in the export',                   (int)($d['babies_seen'] ?? 0)],
            ['No site, never enrolled (expected)',     (int)($d['no_site_not_enrolled'] ?? 0)],
            ['No site but enrolled — worth chasing',   (int)($d['no_site_enrolled'] ?? 0)],
            
            ['Excluded by the site filter',             (int)($d['filtered_out_by_site'] ?? 0)],
            ['Enrolled with no enr_datetime',           (int)($d['enrolled_no_date']  ?? 0)],
            ['Enrolment date unreadable',               (int)($d['enrolled_bad_date'] ?? 0)],
            ['Enrolment outside the period',            (int)($d['outside_period']    ?? 0)],
        ];
        foreach (($d['discharge_no_date'] ?? []) as $type => $v) {
            $n[] = [$type . ' with no discharge date — not counted', (int)$v];
        }
        $n[] = ['SAE forms present',                    (int)($d['sae_form_present']  ?? 0)];
        $n[] = ['SAE with Q8 blank — not counted',      (int)($d['sae_q8_missing']    ?? 0)];
        $n[] = ['SAE with Q5 filled but Q8 blank',      (int)($d['sae_q5_only']       ?? 0)];
        $n[] = ['SAE Q8 present but unreadable',        (int)($d['sae_q8_bad_date']   ?? 0)];
        $n[] = ['Discharge site differs from enrolment',(int)($d['site_mismatch_discharge'] ?? 0)];
        $n[] = ['SAE site differs from enrolment',      (int)($d['site_mismatch_sae'] ?? 0)];
        foreach (($d['cohort_unplaceable'] ?? []) as $k => $v) {
            $n[] = ['Unplaceable on the cohort basis — ' . $k, (int)$v];
        }
        return $n;
    }

    private function line(array $row): string
    {
        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $row, ',', '"', '\\');
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);
        return $s;
    }
}

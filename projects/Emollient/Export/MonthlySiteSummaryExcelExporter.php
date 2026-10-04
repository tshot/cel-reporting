<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * MonthlySiteSummaryExcelExporter
 *
 * A five-sheet workbook from MonthlySiteSummaryAggregator:
 *
 *   1. Summary          site × measure for the whole period, with the
 *                       unplanned-discharge share
 *   2. Event basis      site × month, one block per measure
 *   3. Cohort basis     the same, by the baby's enrolment month
 *   4. Discharge types  all seven coded values, not only LAMA and DOPR
 *   5. Data notes       what was excluded, how each figure is counted
 *
 * Sheet 5 is not an appendix. A count that travels without the records it had
 * to exclude is a number nobody downstream can check, and this workbook is
 * built to be forwarded.
 *
 * PhpSpreadsheet is used when available. Without it the workbook is written as
 * SpreadsheetML 2003 — a single XML file that Excel and LibreOffice both open
 * with every sheet intact. Falling back to one flat CSV would lose the sheets,
 * which is the whole point of asking for Excel.
 */
class MonthlySiteSummaryExcelExporter implements ExporterInterface
{
    private const HDR   = '1F3864';
    private const SUBHD = 'D9E2F3';

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {
        // Signature fixed by ExporterRegistry.
    }

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $p    = is_array($data) ? $data : iterator_to_array($data);
        $xlsx = class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet');
        $body = $xlsx ? $this->buildWithPhpSpreadsheet($p) : $this->buildSpreadsheetML($p);

        $from = $p['period']['date_from'] ?? date('Y-m-d');
        $to   = $p['period']['date_to']   ?? date('Y-m-d');
        $name = "monthly_site_summary_{$from}_to_{$to}." . ($xlsx ? 'xlsx' : 'xls');

        if ($outputPath) {
            file_put_contents($outputPath, $body);
            return;
        }

        header('Content-Type: ' . ($xlsx
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/vnd.ms-excel'));
        header("Content-Disposition: attachment; filename=\"{$name}\"");
        header('Cache-Control: max-age=0');
        echo $body;
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
    // Sheet content — built once, rendered by either backend
    // =====================================================================

    /**
     * @return array<string, array{meta:string, rows:array<int,array<int,mixed>>, head:int}>
     *         head = the 1-based row index of the header row within rows, or 0
     */
    private function sheets(array $p): array
    {
        $months   = $p['months']       ?? [];
        $mlabels  = $p['month_labels'] ?? [];
        $sites    = $p['sites']        ?? [];
        $labels   = $p['site_labels']  ?? [];
        $measures = $p['measures']     ?? [];
        $types    = $p['discharge_type_labels'] ?? [];
        $period   = $p['period']       ?? [];

        $stamp = 'Period ' . ($period['date_from'] ?? '') . ' to ' . ($period['date_to'] ?? '')
               . '   |   generated ' . ($period['generated'] ?? '');

        // ── 1. Summary ───────────────────────────────────────────────────
        $tot  = $p['event_site_totals'] ?? [];
        $rows = [array_merge(['Site code', 'Site'], array_values($measures), ['Unplanned %'])];
        $sum  = array_fill_keys(array_keys($measures), 0);
        foreach ($sites as $s) {
            $t = $tot[$s] ?? [];
            $r = [$s, $labels[$s] ?? $s];
            foreach ($measures as $k => $_) { $v = (int)($t[$k] ?? 0); $r[] = $v; $sum[$k] += $v; }
            $r[] = !empty($t['enrolled'])
                 ? round($t['unplanned'] / $t['enrolled'] * 100, 1) : null;
            $rows[] = $r;
        }
        $r = ['', 'All selected sites'];
        foreach ($measures as $k => $_) $r[] = $sum[$k];
        $r[] = $sum['enrolled'] ? round($sum['unplanned'] / $sum['enrolled'] * 100, 1) : null;
        $rows[] = $r;

        $out = ['Summary' => [
            'meta' => 'Whole period, event basis.   ' . $stamp,
            'rows' => $rows, 'head' => 1, 'total' => count($rows),
        ]];

        // ── 2 & 3. The two bases ─────────────────────────────────────────
        foreach ([['Event basis', 'event'], ['Cohort basis', 'cohort']] as [$title, $key]) {
            $grid = $p[$key] ?? [];
            $rows = [array_merge(['Measure', 'Site code', 'Site'], $mlabels, ['Total'])];
            foreach ($measures as $mk => $mlabel) {
                foreach ($sites as $s) {
                    $r = [$mlabel, $s, $labels[$s] ?? $s];
                    $t = 0;
                    foreach ($months as $m) { $v = (int)($grid[$s][$m][$mk] ?? 0); $r[] = $v; $t += $v; }
                    $r[] = $t;
                    $rows[] = $r;
                }
                $r = [$mlabel, '', 'TOTAL'];
                $t = 0;
                foreach ($months as $m) {
                    $v = 0;
                    foreach ($sites as $s) $v += (int)($grid[$s][$m][$mk] ?? 0);
                    $r[] = $v; $t += $v;
                }
                $r[] = $t;
                $rows[] = $r;
            }
            $meta = $title === 'Event basis'
                ? 'Each event in the month it happened.   ' . $stamp
                : 'Each event in the month the baby was enrolled. Totals can be lower than the '
                . 'event basis — see Data notes.   ' . $stamp;
            $out[$title] = ['meta' => $meta, 'rows' => $rows, 'head' => 1, 'total' => 0];
        }

        // ── 4. All discharge types ───────────────────────────────────────
        $dt   = $p['discharge_types'] ?? [];
        $rows = [array_merge(['Discharge type', 'Code', 'Site code', 'Site'], $mlabels, ['Total'])];
        foreach ($types as $code => $tlabel) {
            foreach ($sites as $s) {
                $r = [$tlabel, $code, $s, $labels[$s] ?? $s];
                $t = 0;
                foreach ($months as $m) { $v = (int)($dt[$s][$m][$code] ?? 0); $r[] = $v; $t += $v; }
                $r[] = $t;
                $rows[] = $r;
            }
        }
        $out['Discharge types'] = [
            'meta' => 'Every coded value of dis_discharge_type, dated by dis_datetime. The report '
                    . 'shows only LAMA and DOPR; the rest are here so a further question needs no '
                    . 'code change.   ' . $stamp,
            'rows' => $rows, 'head' => 1, 'total' => 0,
        ];

        // ── 5. Data notes ────────────────────────────────────────────────
        $d    = $p['diagnostics'] ?? [];
        $rows = [['Item', 'Count', 'Note']];
        $rows[] = ['Records in the export', (int)($d['babies_seen'] ?? 0), ''];
        $rows[] = ['No site, never enrolled', (int)($d['no_site_not_enrolled'] ?? 0), 'Expected — prescreening instances that were never enrolled'];
        $rows[] = ['No site but enrolled', (int)($d['no_site_enrolled'] ?? 0), 'A real gap — cannot be placed at a site'];
        $rows[] = ['Excluded by the site filter',          (int)($d['filtered_out_by_site'] ?? 0), ''];
        $rows[] = ['Enrolled with no enr_datetime',        (int)($d['enrolled_no_date'] ?? 0), ''];
        $rows[] = ['Enrolment date unreadable',            (int)($d['enrolled_bad_date'] ?? 0), ''];
        $rows[] = ['Enrolment outside the period',         (int)($d['outside_period'] ?? 0), ''];
        foreach (($d['discharge_no_date'] ?? []) as $type => $v) {
            $rows[] = [$type . ' with no discharge date', (int)$v,
                in_array($type, ['TYP_LAMA','TYP_DOPR'], true)
                    ? 'AFFECTS THIS REPORT — recorded but uncounted' : ''];
        }
        $q5 = (int)($d['sae_q5_only'] ?? 0);
        $rows[] = ['SAE forms present',                    (int)($d['sae_form_present'] ?? 0), ''];
        $rows[] = ['SAE with Q8 blank',                    (int)($d['sae_q8_missing'] ?? 0),
                   'Not counted — SAEs are dated by Q8 (sae_start_date)'];
        $rows[] = ['SAE with Q5 filled but Q8 blank',      $q5,
                   $q5 > 0 ? 'If this is not near zero, Q8 is not the field sites are filling' : ''];
        $rows[] = ['SAE Q8 present but unreadable',        (int)($d['sae_q8_bad_date'] ?? 0), ''];
        $rows[] = ['Discharge site differs from enrolment',(int)($d['site_mismatch_discharge'] ?? 0),
                   'Site is taken from enr_hosp_code throughout'];
        $rows[] = ['SAE site differs from enrolment',      (int)($d['site_mismatch_sae'] ?? 0), ''];
        foreach (($d['cohort_unplaceable'] ?? []) as $k => $v) {
            $rows[] = ['Unplaceable on cohort basis — ' . $k, (int)$v,
                       'Exactly why the two bases can differ'];
        }
        $rows[] = ['', '', ''];
        $rows[] = ['HOW EACH FIGURE IS COUNTED', '', ''];
        foreach (($p['definitions'] ?? []) as $k => $v) $rows[] = [$k, '', $v];
        $rows[] = ['', '', ''];
        $rows[] = ['EXCLUDED BY DESIGN', '', ''];
        foreach (($p['exclusions'] ?? []) as $x) $rows[] = ['', '', $x];

        $out['Data notes'] = [
            'meta' => 'What is NOT in the figures on the other sheets.   ' . $stamp,
            'rows' => $rows, 'head' => 1, 'total' => 0,
        ];

        return $out;
    }

    // =====================================================================
    // PhpSpreadsheet
    // =====================================================================

    private function buildWithPhpSpreadsheet(array $p): string
    {
        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sp->removeSheetByIndex(0);

        foreach ($this->sheets($p) as $name => $sheet) {
            $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, $name);
            $sp->addSheet($ws);

            $rows  = $sheet['rows'];
            $width = max(array_map('count', $rows ?: [[]]));
            $last  = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max($width, 1));

            // meta banner
            $ws->setCellValue('A1', $sheet['meta']);
            $ws->mergeCells("A1:{$last}1");
            $ws->getStyle('A1')->applyFromArray([
                'font' => ['bold' => true, 'size' => 10, 'name' => 'Arial', 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => self::HDR]],
            ]);
            $ws->getRowDimension(1)->setRowHeight(20);

            foreach ($rows as $i => $row) {
                $excelRow = $i + 2;
                foreach (array_values($row) as $j => $val) {
                    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($j + 1);
                    if ($val === null || $val === '') continue;
                    $ws->setCellValue($col . $excelRow, $val);
                }
            }

            // header row
            $hdr = $sheet['head'] + 1;
            $ws->getStyle("A{$hdr}:{$last}{$hdr}")->applyFromArray([
                'font'    => ['bold' => true, 'size' => 10, 'name' => 'Arial'],
                'fill'    => ['fillType' => 'solid', 'startColor' => ['rgb' => self::SUBHD]],
                'borders' => ['bottom' => ['borderStyle' => 'thin']],
            ]);

            // total row, where there is one
            if (!empty($sheet['total'])) {
                $tr = $sheet['total'] + 1;
                $ws->getStyle("A{$tr}:{$last}{$tr}")->applyFromArray([
                    'font'    => ['bold' => true, 'size' => 10, 'name' => 'Arial'],
                    'borders' => ['top' => ['borderStyle' => 'thin']],
                ]);
            }

            $ws->getStyle("A1:{$last}" . (count($rows) + 1))
               ->getFont()->setName('Arial')->setSize(10);
            for ($c = 1; $c <= $width; $c++) {
                $ws->getColumnDimension(
                    \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c)
                )->setAutoSize(true);
            }
            $ws->freezePane('A' . ($hdr + 1));
        }

        $sp->setActiveSheetIndex(0);
        ob_start();
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp))->save('php://output');
        return (string)ob_get_clean();
    }

    // =====================================================================
    // SpreadsheetML 2003 — no dependencies, still a real multi-sheet workbook
    // =====================================================================

    private function buildSpreadsheetML(array $p): string
    {
        $x  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $x .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
        $x .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"'
            . ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
        $x .= '<Styles>'
            . '<Style ss:ID="meta"><Font ss:Bold="1" ss:Color="#FFFFFF" ss:FontName="Arial" ss:Size="10"/>'
            . '<Interior ss:Color="#' . self::HDR . '" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="hdr"><Font ss:Bold="1" ss:FontName="Arial" ss:Size="10"/>'
            . '<Interior ss:Color="#' . self::SUBHD . '" ss:Pattern="Solid"/></Style>'
            . '<Style ss:ID="body"><Font ss:FontName="Arial" ss:Size="10"/></Style>'
            . '</Styles>';

        foreach ($this->sheets($p) as $name => $sheet) {
            $x .= '<Worksheet ss:Name="' . $this->xe($this->sheetName($name)) . '"><Table>';
            $x .= '<Row><Cell ss:StyleID="meta"><Data ss:Type="String">'
                . $this->xe($sheet['meta']) . '</Data></Cell></Row>';

            foreach ($sheet['rows'] as $i => $row) {
                $style = ($i + 1) === $sheet['head'] ? 'hdr' : 'body';
                $x .= '<Row>';
                foreach (array_values($row) as $val) {
                    if ($val === null || $val === '') {
                        $x .= '<Cell ss:StyleID="' . $style . '"/>';
                        continue;
                    }
                    $type = is_int($val) || is_float($val) ? 'Number' : 'String';
                    $x .= '<Cell ss:StyleID="' . $style . '"><Data ss:Type="' . $type . '">'
                        . $this->xe((string)$val) . '</Data></Cell>';
                }
                $x .= '</Row>';
            }
            $x .= '</Table></Worksheet>';
        }
        return $x . '</Workbook>';
    }

    /** Excel sheet names: 31 chars, and none of : \ / ? * [ ] */
    private function sheetName(string $n): string
    {
        return mb_substr(str_replace([':','\\','/','?','*','[',']'], '-', $n), 0, 31);
    }

    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}

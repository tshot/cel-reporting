<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * FormCompletionIncompleteExcelExporter
 *
 * Per-site Excel workbook of pending/incomplete forms for the multi-day
 * completion reports (DailyMonitoring, SkinScoring, NeonatalSepsis).
 *
 * Workbook structure:
 *   Sheet 1: "Summary"   — one row per site, totals by status
 *   Sheets 2..n: one per site — rows of pending forms
 *                 (each row = one participant, one day)
 *
 * Pending = missing + partial + parent_not_done.
 *
 * Registered as 'form_completion_excel'. The engine's ReportController
 * routes ?format=excel to this when a report uses 'exporter' => 'form_completion'.
 */
class FormCompletionIncompleteExcelExporter implements ExporterInterface
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
        $formName     = $payload['form_name']    ?? 'form';

        // ── Group participants by site ──────────────────────────────────────
        $bySite = [];
        foreach ($participants as $p) {
            $siteCode = $p['site'] ?? 'Unknown';
            $bySite[$siteCode][] = $p;
        }
        ksort($bySite);

        // ── Pre-compute per-site totals for Summary ─────────────────────────
        $siteTotals = [];
        foreach ($bySite as $siteCode => $rows) {
            $tot = ['participants' => 0, 'missing' => 0, 'partial' => 0, 'parent_not_done' => 0];
            foreach ($rows as $p) {
                $missCnt = count($p['missing_days'] ?? []);
                $partCnt = count($p['partial_days'] ?? []);
                $blkCnt  = count($p['blocked_days'] ?? []);
                if ($missCnt + $partCnt + $blkCnt > 0) {
                    $tot['participants']++;
                    $tot['missing']         += $missCnt;
                    $tot['partial']         += $partCnt;
                    $tot['parent_not_done'] += $blkCnt;
                }
            }
            $siteTotals[$siteCode] = $tot;
        }

        // ── Build workbook ──────────────────────────────────────────────────
        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sp->removeSheetByIndex(0);

        $this->buildSummarySheet($sp, $siteTotals, $siteLabels, $formName);

        foreach ($bySite as $siteCode => $rows) {
            $this->buildSiteSheet($sp, $siteCode, $siteLabels[$siteCode] ?? $siteCode, $rows);
        }

        // Set Summary as the active sheet
        $sp->setActiveSheetIndexByName('Summary');

        // Write
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp);
        if ($outputPath) {
            $writer->save($outputPath);
        } else {
            $writer->save('php://output');
        }
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
    // Summary sheet
    // =========================================================================

    private function buildSummarySheet(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $sp,
        array $siteTotals,
        array $siteLabels,
        string $formName
    ): void {
        $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, 'Summary');
        $sp->addSheet($ws);

        // Title row
        $ws->setCellValue('A1', "Pending Forms — {$formName}");
        $ws->mergeCells('A1:F1');
        $ws->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(1)->setRowHeight(24);

        // Headers
        $headers = ['Site', 'Site Name', 'Participants Pending', 'Missing Days',
                    'Partial Days', 'Parent Not Done'];
        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$col}2", $h);
        }
        $ws->getStyle('A2:F2')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E75B6']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        // Data rows
        $r = 3;
        $grand = ['participants' => 0, 'missing' => 0, 'partial' => 0, 'parent_not_done' => 0];
        foreach ($siteTotals as $siteCode => $t) {
            $ws->setCellValue("A{$r}", $siteCode);
            $ws->setCellValue("B{$r}", $siteLabels[$siteCode] ?? $siteCode);
            $ws->setCellValue("C{$r}", $t['participants']);
            $ws->setCellValue("D{$r}", $t['missing']);
            $ws->setCellValue("E{$r}", $t['partial']);
            $ws->setCellValue("F{$r}", $t['parent_not_done']);
            $bg = ($r % 2 === 0) ? 'FFFFFF' : 'F5F8FC';
            $ws->getStyle("A{$r}:F{$r}")->applyFromArray([
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $bg]],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
                'borders'   => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E0E0E0']]],
            ]);
            $ws->getStyle("C{$r}:F{$r}")->getAlignment()->setHorizontal('center');
            foreach ($grand as $k => $_) $grand[$k] += $t[$k];
            $r++;
        }

        // Total row
        $ws->setCellValue("A{$r}", 'TOTAL');
        $ws->mergeCells("A{$r}:B{$r}");
        $ws->setCellValue("C{$r}", $grand['participants']);
        $ws->setCellValue("D{$r}", $grand['missing']);
        $ws->setCellValue("E{$r}", $grand['partial']);
        $ws->setCellValue("F{$r}", $grand['parent_not_done']);
        $ws->getStyle("A{$r}:F{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '375623']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        foreach (range('A', 'F') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $ws->freezePane('A3');
    }

    // =========================================================================
    // Per-site sheet
    // =========================================================================

    private function buildSiteSheet(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $sp,
        string $siteCode,
        string $siteName,
        array  $rows
    ): void {
        // Excel sheet names: max 31 chars, no [ ] : * ? / \
        $sheetName = $this->safeSheetName($siteCode);
        $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, $sheetName);
        $sp->addSheet($ws);

        // Title row
        $ws->setCellValue('A1', "{$siteName} ({$siteCode}) — Pending Forms");
        $ws->mergeCells('A1:E1');
        $ws->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E75B6']],
            'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(1)->setRowHeight(22);

        // Headers
        $headers = ['Record ID', 'Arm', 'Day', 'Status', 'Reason / Stop'];
        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$col}2", $h);
        }
        $ws->getStyle('A2:E2')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '375623']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(2)->setRowHeight(18);

        // Sort participants by record_id for predictable layout
        usort($rows, fn($a, $b) => strcmp($a['record_id'] ?? '', $b['record_id'] ?? ''));

        // Status colour map
        $statusColours = [
            'missing'         => 'C62828',
            'partial'         => 'E65100',
            'parent_not_done' => '6A4F00',
        ];

        $r = 3;
        $rowsWritten = 0;
        foreach ($rows as $p) {
            $id      = $p['record_id'] ?? '';
            $arm     = $p['arm']       ?? '';
            $endRsn  = $p['end_reason'] ?? '';
            $endDayD = $p['end_day_display'] ?? null;
            $stopHint = $endRsn ? "{$endRsn} (Day {$endDayD})" : '';

            // Group rows in this order: missing, partial, parent_not_done
            foreach (['missing_days' => 'missing',
                      'partial_days' => 'partial',
                      'blocked_days' => 'parent_not_done'] as $key => $status) {
                foreach (($p[$key] ?? []) as $d) {
                    $ws->setCellValue("A{$r}", $id);
                    $ws->setCellValue("B{$r}", $arm);
                    $ws->setCellValue("C{$r}", "Day {$d}");
                    $ws->setCellValue("D{$r}", $status);
                    $ws->setCellValue("E{$r}", $stopHint);

                    $bg = ($rowsWritten % 2 === 0) ? 'FFFFFF' : 'F5F8FC';
                    $ws->getStyle("A{$r}:E{$r}")->applyFromArray([
                        'font'      => ['size' => 10],
                        'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $bg]],
                        'alignment' => ['vertical' => 'center'],
                        'borders'   => ['bottom' => ['borderStyle' => 'thin',
                                                     'color' => ['rgb' => 'E0E0E0']]],
                    ]);
                    $ws->getStyle("D{$r}")->applyFromArray([
                        'font'      => ['bold' => true,
                                        'color' => ['rgb' => $statusColours[$status] ?? '333333']],
                        'alignment' => ['horizontal' => 'center'],
                    ]);
                    $ws->getStyle("C{$r}")->getAlignment()->setHorizontal('center');
                    $r++;
                    $rowsWritten++;
                }
            }
        }

        if ($rowsWritten === 0) {
            $ws->setCellValue('A3', 'No pending forms for this site. ✓');
            $ws->mergeCells('A3:E3');
            $ws->getStyle('A3')->applyFromArray([
                'font'      => ['italic' => true, 'color' => ['rgb' => '2E7D32']],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
        }

        foreach (range('A', 'E') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $ws->freezePane('A3');
        if ($rowsWritten > 0) {
            $ws->setAutoFilter('A2:E2');
        }
    }

    private function safeSheetName(string $name): string
    {
        // Excel: max 31 chars; cannot contain : \ / ? * [ ]
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '_', $name);
        return mb_substr($name, 0, 31);
    }
}

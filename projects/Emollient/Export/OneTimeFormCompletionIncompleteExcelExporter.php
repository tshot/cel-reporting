<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * OneTimeFormCompletionIncompleteExcelExporter
 *
 * Per-site Excel workbook of pending one-time forms (Socioeconomic,
 * BaselineGeneral, BaselineAnthro, MaternalHistory).
 *
 * Workbook structure:
 *   Sheet 1: "Summary" — one row per site with counts of missing forms
 *   Sheets 2..n: one per site — list of participants whose form is missing
 *
 * Only rows with status = 'missing' are exported. 'complete' and 'not_due'
 * (PD or SW filed) participants are excluded.
 *
 * Registered as 'one_time_completion_excel'.
 */
class OneTimeFormCompletionIncompleteExcelExporter implements ExporterInterface
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

        // ── Group missing-status participants by site ───────────────────────
        $bySite = [];
        foreach ($participants as $p) {
            if (($p['status'] ?? '') !== 'missing') continue;
            $siteCode = $p['site'] ?? 'Unknown';
            $bySite[$siteCode][] = $p;
        }
        ksort($bySite);

        // ── Build workbook ──────────────────────────────────────────────────
        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sp->removeSheetByIndex(0);

        $this->buildSummarySheet($sp, $bySite, $siteLabels, $formName);

        foreach ($bySite as $siteCode => $rows) {
            $this->buildSiteSheet($sp, $siteCode, $siteLabels[$siteCode] ?? $siteCode, $rows);
        }

        if (count($bySite) === 0) {
            // No missing forms at any site — drop a friendly placeholder sheet
            $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, 'No Pending Forms');
            $sp->addSheet($ws);
            $ws->setCellValue('A1', "All {$formName} forms are either complete or formally exempted (PD/SW). ✓");
            $ws->mergeCells('A1:E1');
            $ws->getStyle('A1')->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => '2E7D32'], 'size' => 12],
                'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
            ]);
        }

        $sp->setActiveSheetIndex(0);

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
        array $bySite,
        array $siteLabels,
        string $formName
    ): void {
        $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, 'Summary');
        $sp->addSheet($ws);

        $ws->setCellValue('A1', "Pending Forms — {$formName}");
        $ws->mergeCells('A1:C1');
        $ws->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '1F3864']],
            'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(1)->setRowHeight(24);

        $headers = ['Site', 'Site Name', 'Pending Count'];
        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$col}2", $h);
        }
        $ws->getStyle('A2:C2')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E75B6']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        $r = 3;
        $total = 0;
        foreach ($bySite as $siteCode => $rows) {
            $cnt = count($rows);
            $ws->setCellValue("A{$r}", $siteCode);
            $ws->setCellValue("B{$r}", $siteLabels[$siteCode] ?? $siteCode);
            $ws->setCellValue("C{$r}", $cnt);
            $bg = ($r % 2 === 0) ? 'FFFFFF' : 'F5F8FC';
            $ws->getStyle("A{$r}:C{$r}")->applyFromArray([
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $bg]],
                'borders'   => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E0E0E0']]],
            ]);
            $ws->getStyle("C{$r}")->getAlignment()->setHorizontal('center');
            $total += $cnt;
            $r++;
        }

        $ws->setCellValue("A{$r}", 'TOTAL');
        $ws->mergeCells("A{$r}:B{$r}");
        $ws->setCellValue("C{$r}", $total);
        $ws->getStyle("A{$r}:C{$r}")->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '375623']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);

        foreach (range('A', 'C') as $c) {
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
        $sheetName = $this->safeSheetName($siteCode);
        $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, $sheetName);
        $sp->addSheet($ws);

        $ws->setCellValue('A1', "{$siteName} ({$siteCode}) — Pending Forms");
        $ws->mergeCells('A1:D1');
        $ws->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '2E75B6']],
            'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(1)->setRowHeight(22);

        $headers = ['Record ID', 'Arm', 'Enrolled', 'Case Note'];
        foreach ($headers as $i => $h) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValue("{$col}2", $h);
        }
        $ws->getStyle('A2:D2')->applyFromArray([
            'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => '375623']],
            'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
        ]);
        $ws->getRowDimension(2)->setRowHeight(18);

        usort($rows, fn($a, $b) => strcmp($a['record_id'] ?? '', $b['record_id'] ?? ''));

        $r = 3;
        foreach ($rows as $i => $p) {
            $ws->setCellValue("A{$r}", $p['record_id'] ?? '');
            $ws->setCellValue("B{$r}", $p['arm']       ?? '');
            $ws->setCellValue("C{$r}", $p['enr_date']  ?? '');
            $ws->setCellValue("D{$r}", $p['close_reason'] ?? '');

            $bg = ($i % 2 === 0) ? 'FFFFFF' : 'F5F8FC';
            $ws->getStyle("A{$r}:D{$r}")->applyFromArray([
                'font'      => ['size' => 10],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $bg]],
                'alignment' => ['vertical' => 'center'],
                'borders'   => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E0E0E0']]],
            ]);
            $r++;
        }

        foreach (range('A', 'D') as $c) {
            $ws->getColumnDimension($c)->setAutoSize(true);
        }
        $ws->freezePane('A3');
        $ws->setAutoFilter('A2:D2');
    }

    private function safeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]/', '_', $name);
        return mb_substr($name, 0, 31);
    }
}

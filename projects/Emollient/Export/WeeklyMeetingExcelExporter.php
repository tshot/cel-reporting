<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * WeeklyMeetingExcelExporter
 *
 * Generates a downloadable Excel file with raw form data for the report period.
 * Eight tabs:
 *
 *   1. Pre-Screening     — baby_prescreening_and_screening_form (baby_datetime)
 *   2. Discharge         — discharge_form (dis_datetime)
 *   3. 28-Day Status     — discharge_after_28_days_of_stay (no date filter)
 *   4. 29-Day Follow-up  — day_28_follow_up_form (fu28_datetime)
 *   5. Sepsis PerBaby    — one row per baby with confirmed / suspected sepsis (nss_datetime)
 *   6. Sepsis Forms      — one row per sepsis-impression form, for checking (nss_datetime)
 *   7. Emollient PerBaby — one row per baby with >= 1 stopped instance (int_datetime)
 *   8. Emollient Stopped — one row per stopped emolliation instance (int_datetime)
 *
 * Reads from payload keys produced by WeeklyMeetingAggregator:
 *   rawPrescreening, rawDischarge, rawDay28Status, rawFollowup29,
 *   rawSepsisPerBaby, rawSepsisForms, rawEmolPerBaby, rawEmolStopped
 */
class WeeklyMeetingExcelExporter implements ExporterInterface
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {
        // Accepts these params for ExporterRegistry compatibility — not used
    }

    // =========================================================================
    // ExporterInterface
    // =========================================================================

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload  = is_array($data) ? $data : iterator_to_array($data);
        $excel    = $this->buildExcel($payload);

        $filename = 'weekly_meeting_data_'
                  . ($payload['period']['date_from'] ?? date('Y-m-d'))
                  . '_to_'
                  . ($payload['period']['date_to']   ?? date('Y-m-d'))
                  . '.xlsx';

        if ($outputPath) {
            file_put_contents($outputPath, $excel);
        } else {
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            header('Cache-Control: max-age=0');
            echo $excel;
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
    // Excel builder
    // =========================================================================

    private function buildExcel(array $payload): string
    {
        $period     = $payload['period']   ?? [];
        $dateFrom   = $period['date_from'] ?? '';
        $dateTo     = $period['date_to']   ?? '';
        $reportDate = date('Y-m-d');

        if (class_exists('\PhpOffice\PhpSpreadsheet\Spreadsheet')) {
            return $this->buildWithPhpSpreadsheet($payload, $dateFrom, $dateTo, $reportDate);
        }

        return $this->buildXlsxManually($payload, $dateFrom, $dateTo, $reportDate);
    }

    // =========================================================================
    // PhpSpreadsheet path (preferred)
    // =========================================================================

    private function buildWithPhpSpreadsheet(
        array  $payload,
        string $dateFrom,
        string $dateTo,
        string $reportDate
    ): string {
        $sp = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sp->removeSheetByIndex(0);

        $defs      = $this->sheetDefs($payload, $dateFrom, $dateTo, $reportDate);
        $sheets    = array_map(fn($d) => $d['rows'],   $defs);
        $sheetMeta = array_map(fn($d) => $d['meta'],   $defs);
        $colours   = array_map(fn($d) => $d['colour'], $defs);

        foreach ($sheets as $sheetName => $rows) {
            $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($sp, $sheetName);
            $sp->addSheet($ws);

            $hdrColour = $colours[$sheetName] ?? '1F3864';

            // Row 1 — meta info
            $ws->setCellValue('A1', $sheetMeta[$sheetName]);
            $ws->mergeCells('A1:Z1');
            $ws->getStyle('A1')->applyFromArray([
                'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 10],
                'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $hdrColour]],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
            ]);
            $ws->getRowDimension(1)->setRowHeight(20);

            if (empty($rows)) {
                $ws->setCellValue('A2', 'No data for this period.');
                continue;
            }

            // Row 2 — column headers
            $headers = array_keys($rows[0]);
            $col = 1;
            foreach ($headers as $h) {
                $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . 2, $h);
                $ws->getStyle(Coordinate::stringFromColumnIndex($col) . 2)->applyFromArray([
                    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Arial', 'size' => 9],
                    'fill'      => ['fillType' => 'solid', 'startColor' => ['rgb' => $hdrColour]],
                    'alignment' => ['horizontal' => 'center', 'vertical' => 'center'],
                ]);
                $ws->getColumnDimension(Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
                $col++;
            }
            $ws->getRowDimension(2)->setRowHeight(18);

            // Data rows — values cell by cell, styles applied per range
            // (per-cell styling is very slow on the larger sepsis / emolliation sheets)
            $lastCol = Coordinate::stringFromColumnIndex(count($headers));
            foreach ($rows as $i => $row) {
                $excelRow = $i + 3;
                $col = 1;
                foreach ($headers as $h) {
                    $ws->setCellValue(Coordinate::stringFromColumnIndex($col) . $excelRow, $row[$h] ?? '');
                    $col++;
                }
                if ($i % 2 === 1) {
                    $ws->getStyle("A{$excelRow}:{$lastCol}{$excelRow}")->applyFromArray([
                        'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'F5F8FC']],
                    ]);
                }
            }
            $lastRow = count($rows) + 2;
            $ws->getStyle("A3:{$lastCol}{$lastRow}")->applyFromArray([
                'font'      => ['name' => 'Arial', 'size' => 9],
                'alignment' => ['horizontal' => 'left', 'vertical' => 'center'],
                'borders'   => ['bottom' => ['borderStyle' => 'thin', 'color' => ['rgb' => 'E0E0E0']]],
            ]);
            $ws->getDefaultRowDimension()->setRowHeight(16);

            $ws->freezePane('A3');
            $ws->setAutoFilter("A2:{$lastCol}2");
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($sp);
        ob_start();
        $writer->save('php://output');
        return ob_get_clean();
    }

    // =========================================================================
    // Sheet definitions — shared by both build paths
    // =========================================================================

    /** @return array<string, array{rows: array, meta: string, colour: string}> */
    private function sheetDefs(array $payload, string $dateFrom, string $dateTo, string $reportDate): array
    {
        $p = "{$dateFrom} to {$dateTo}";
        return [
            'Pre-Screening'     => ['rows' => $payload['rawPrescreening']  ?? [], 'colour' => '2E75B6',
                                    'meta' => "baby_prescreening_and_screening_form  |  Date filter: baby_datetime {$p}"],
            'Discharge'         => ['rows' => $payload['rawDischarge']     ?? [], 'colour' => '375623',
                                    'meta' => "discharge_form  |  Date filter: dis_datetime {$p}"],
            '28-Day Status'     => ['rows' => $payload['rawDay28Status']   ?? [], 'colour' => 'C55A11',
                                    'meta' => "discharge_after_28_days_of_stay  |  As on report date: {$reportDate} (no date filter)"],
            '29-Day Follow-up'  => ['rows' => $payload['rawFollowup29']    ?? [], 'colour' => '7030A0',
                                    'meta' => "day_28_follow_up_form  |  Date filter: fu28_datetime {$p}"],
            'Sepsis PerBaby'    => ['rows' => $payload['rawSepsisPerBaby'] ?? [], 'colour' => 'A61C3C',
                                    'meta' => "neonatal_sepsis_screening  |  One row per baby with confirmed / suspected sepsis  |  Date filter: nss_datetime {$p}"],
            'Sepsis Forms'      => ['rows' => $payload['rawSepsisForms']   ?? [], 'colour' => 'A61C3C',
                                    'meta' => "neonatal_sepsis_screening  |  One row per CONF / SUSP form (for checking)  |  Date filter: nss_datetime {$p}"],
            'Emollient PerBaby' => ['rows' => $payload['rawEmolPerBaby']   ?? [], 'colour' => '0E7C7B',
                                    'meta' => "daily_interventionemolliation_form  |  One row per baby with >= 1 instance not emolliated  |  Date filter: int_datetime {$p}"],
            'Emollient Stopped' => ['rows' => $payload['rawEmolStopped']   ?? [], 'colour' => '0E7C7B',
                                    'meta' => "daily_interventionemolliation_form  |  One row per instance with int_emoliate_baby = N  |  Date filter: int_datetime {$p}"],
        ];
    }

    // =========================================================================
    // Manual XLSX build (fallback — no dependencies)
    // =========================================================================

    private function buildXlsxManually(
        array  $payload,
        string $dateFrom,
        string $dateTo,
        string $reportDate
    ): string {
        $defs      = $this->sheetDefs($payload, $dateFrom, $dateTo, $reportDate);
        $sheets    = array_map(fn($d) => $d['rows'], $defs);
        $sheetMeta = array_map(fn($d) => $d['meta'], $defs);

        $sheetXmls  = [];
        $sheetNames = array_keys($sheets);

        foreach ($sheetNames as $name) {
            $sheetXmls[] = $this->buildSheetXml($sheets[$name], $sheetMeta[$name]);
        }

        return $this->packXlsx($sheetNames, $sheetXmls);
    }

    private function buildSheetXml(array $rows, string $meta): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<sheetData>';
        $xml .= '<row r="1"><c r="A1" t="inlineStr"><is><t>' . $this->xe($meta) . '</t></is></c></row>';

        if (empty($rows)) {
            $xml .= '<row r="2"><c r="A2" t="inlineStr"><is><t>No data for this period.</t></is></c></row>';
            $xml .= '</sheetData></worksheet>';
            return $xml;
        }

        $headers = array_keys($rows[0]);
        $xml .= '<row r="2">';
        foreach ($headers as $ci => $h) {
            $col = $this->colName($ci);
            $xml .= "<c r=\"{$col}2\" t=\"inlineStr\"><is><t>" . $this->xe($h) . '</t></is></c>';
        }
        $xml .= '</row>';

        foreach ($rows as $ri => $row) {
            $excelRow = $ri + 3;
            $xml .= "<row r=\"{$excelRow}\">";
            foreach ($headers as $ci => $h) {
                $col = $this->colName($ci);
                $val = $row[$h] ?? '';
                $xml .= "<c r=\"{$col}{$excelRow}\" t=\"inlineStr\"><is><t>" . $this->xe((string)$val) . '</t></is></c>';
            }
            $xml .= '</row>';
        }

        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    private function packXlsx(array $sheetNames, array $sheetXmls): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'wm_xlsx_');
        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $ct  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $ct .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $ct .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $ct .= '<Default Extension="xml"  ContentType="application/xml"/>';
        $ct .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        foreach ($sheetNames as $i => $name) {
            $n   = $i + 1;
            $ct .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }
        $ct .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $ct);

        $rels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $rels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        $rels .= '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>';
        $rels .= '</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        $wb  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wb .= '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
        $wb .= '<sheets>';
        foreach ($sheetNames as $i => $name) {
            $n   = $i + 1;
            $wb .= "<sheet name=\"" . $this->xe($name) . "\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
        }
        $wb .= '</sheets></workbook>';
        $zip->addFromString('xl/workbook.xml', $wb);

        $wbRels  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $wbRels .= '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheetNames as $i => $name) {
            $n       = $i + 1;
            $wbRels .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
        }
        $wbRels .= '</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        foreach ($sheetXmls as $i => $sheetXml) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sheetXml);
        }

        $zip->close();
        $content = file_get_contents($tmpFile);
        unlink($tmpFile);
        return $content;
    }

    private function xe(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function colName(int $zeroIdx): string
    {
        $n   = $zeroIdx + 1;
        $col = '';
        while ($n > 0) {
            $rem  = ($n - 1) % 26;
            $col  = chr(65 + $rem) . $col;
            $n    = (int)(($n - 1) / 26);
        }
        return $col;
    }
}

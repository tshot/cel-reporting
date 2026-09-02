<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * WeeklyMeetingHtmlExporter
 *
 * Renders the three-section weekly meeting report:
 *
 *   Section 1 — Pre-Screening   (date field: baby_datetime)
 *   Section 2 — Screening & Enrollment  (date fields: baby_datetime / enr_datetime)
 *   Section 3 — Discharge       (date fields per metric)
 *
 * Each section header shows:
 *   - Section title
 *   - Date field used for filtering
 *   - Date range applied
 *
 * Layout: one table per section, rows = metrics, columns = Total + sites.
 */
class WeeklyMeetingHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $toolbar;

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) 
    {
        $this->inline  = $inline;
        $this->toolbar = $toolbar;
    }

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->render($payload);
        if ($outputPath) { file_put_contents($outputPath, $html); }
        else             { header('Content-Type: text/html; charset=UTF-8'); echo $html; }
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

    private function render(array $payload): string
    {
        $prescreening = $payload['prescreening'] ?? [];
        $screening    = $payload['screening']    ?? [];
        $discharge    = $payload['discharge']    ?? [];
        $metricMeta   = $payload['metricMeta']   ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $period       = $payload['period']       ?? [];

        // ── Override metricMeta for Weekly Meeting display ─────────────────
        // Indent Intervention and Control under Total Enrolled
        foreach (['enr_intervention', 'enr_control'] as $m) {
            if (isset($metricMeta[$m])) $metricMeta[$m]['indent'] = true;
        }

        $dateFrom = $period['date_from'] ?? null;
        $dateTo   = $period['date_to']   ?? null;
        $dateRange = $this->dateRangeLabel($dateFrom, $dateTo);

        // Build ordered site columns from all three sections
        $allSites = [];
        foreach ([$prescreening, $screening, $discharge] as $sectionData) {
            foreach ($sectionData as $siteCounts) {
                foreach (array_keys($siteCounts) as $site) {
                    if ($site !== 'Total') $allSites[$site] = true;
                }
            }
        }
        // Order by site_labels if provided
        $orderedSites = [];
        foreach (array_keys($siteLabels) as $code) 
        {
            if (isset($allSites[$code])) $orderedSites[] = $code;
        }
        foreach (array_keys($allSites) as $site) 
        {
            if (!in_array($site, $orderedSites, true)) $orderedSites[] = $site;
        }
        $columns = array_merge(['Total'], $orderedSites);

        $css = $this->inline
            ? '<style>' . $this->inlineCss() . '</style>'
            : "<link rel='stylesheet' href='/assets/css/emollient_eligible.css'>\n"
            . "<link rel='stylesheet' href='/assets/css/weekly_meeting.css'>";

        $js = $this->inline
            ? '<script>' . $this->readFile('js/weekly_meeting.js') . '</script>'
            : "<script src='/assets/js/weekly_meeting.js'></script>";

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Weekly Meeting Report — Emollient</title>
<?= $css ?>
</head>
<body>
<div class="report-container">
  <?= $this->toolbar ?>

  <div class="report-header">
    <div>
      <div class="report-title">&#x1F4CB; Weekly Meeting &mdash; Site Summary</div>
      <div class="report-period"><?= htmlspecialchars($dateRange) ?></div>
    </div>
    <div class="wm-controls" style="margin-bottom:0">
      <button class="wm-btn" onclick="wmDownloadCsv()">&#8659; Export CSV</button>
      <button class="wm-btn wm-btn-secondary" onclick="wmDownloadExcel()">&#8659; Download Data (Excel)</button>
      <button class="wm-btn wm-btn-secondary" onclick="window.print()">&#x1F5A8; Print</button>
    </div>
  </div>

<?php
    // ── Section 1: Pre-Screening ─────────────────────────────────────────────
    echo $this->renderSection(
        title:      'Pre-Screening',
        dateField:  'baby_datetime',
        dateRange:  $dateRange,
        data:       $prescreening,
        metricMeta: $metricMeta,
        columns:    $columns,
        siteLabels: $siteLabels,
        sections:   ['PreScreened'],
        showPct:    true
    );

    // ── Section 2: Screening & Enrollment ────────────────────────────────────
    echo $this->renderSection(
        title:      'Screening & Enrollment',
        dateField:  'baby_datetime (screening) / enr_datetime (enrollment)',
        dateRange:  $dateRange,
        data:       $screening,
        metricMeta: $metricMeta,
        columns:    $columns,
        siteLabels: $siteLabels,
        sections:   ['Screened', 'To be Enrolled', 'Enrolled'],
        showPct:    true
    );

    // ── Section 3: Discharge ─────────────────────────────────────────────────
    $dischargeMeta = $this->dischargeMeta();
    echo $this->renderDischargeSection(
        dateRange:  $dateRange,
        data:       $discharge,
        meta:       $dischargeMeta,
        columns:    $columns,
        siteLabels: $siteLabels
    );
?>

</div><!-- .report-container -->

<?= $js ?>
<script>
function wmDownloadExcel() {
    var url = window.location.href;
    // Replace or add format=excel
    if (url.indexOf('format=') !== -1) {
        url = url.replace(/format=[^&]+/, 'format=excel');
    } else {
        url += (url.indexOf('?') !== -1 ? '&' : '?') + 'format=excel';
    }
    window.location.href = url;
}
</script>
</body>
</html>
<?php
        return ob_get_clean();
    }

    // =========================================================================
    // Section renderers
    // =========================================================================

    /**
     * Render a section using EligibilityMetricDefinitions metricMeta.
     * Only renders metrics whose 'section' key is in $sections.
     */
    private function renderSection(
        string $title,
        string $dateField,
        string $dateRange,
        array  $data,
        array  $metricMeta,
        array  $columns,
        array  $siteLabels,
        array  $sections,
        bool   $showPct = false
    ): string 
    {
        $colCount  = count($columns) + ($showPct ? 2 : 1);
        $headerRow = $this->buildHeaderRow($columns, $siteLabels, $showPct);

        $body        = '';
        $lastSection = '';
        $lastSubsection = '';

        foreach ($metricMeta as $metric => $meta) 
        {
            if (!in_array($meta['section'] ?? '', $sections, true)) continue;

            $section    = $meta['section']    ?? '';
            $subsection = $meta['subsection'] ?? '';
            $label      = $meta['label']      ?? $metric;
            $isPrimary  = ($meta['role'] ?? '') === 'primary';
            $denomKey   = $meta['denominator'] ?? null;
            $totalMetric = $meta['subsection_total_metric'] ?? null;

            // Section header row
            if ($section !== $lastSection) 
            {
                $lastSection    = $section;
                $lastSubsection = '';
                $body .= "<tr class='wm-section-hdr'>"
                       . "<td colspan='{$colCount}'>{$section}</td></tr>";
            }

            // When subsection changes — emit total row for previous subsection first
            if ($subsection !== $lastSubsection && $lastSubsection !== '') 
            {
                $body .= $this->subsectionTotalRow($data, $columns, $colCount, $lastSubsection, $lastSection, $showPct);
            }

            // Subsection header (only when starting a new one)
            if ($subsection !== '' && $subsection !== $lastSubsection) 
            {
                $lastSubsection = $subsection;
                $body .= "<tr class='wm-subsection-hdr'>"
                       . "<td colspan='{$colCount}'>{$subsection}</td></tr>";
            }

            $cls = $isPrimary ? 'wm-primary' : '';
            $isIndented = ($meta['indent'] ?? false);
            $labelClass = $isIndented ? 'wm-metric-label wm-metric-indented' : 'wm-metric-label';
            $body .= "<tr class='{$cls}'><td class='{$labelClass}'>"
                   . htmlspecialchars($label) . "</td>";

            foreach ($columns as $col) 
            {
                $val  = $data[$metric][$col] ?? 0;
                $tcls = $col === 'Total' ? 'wm-total-val' : '';
                $body .= "<td class='{$tcls}'>{$val}</td>";
            }

            // % column
            if ($showPct && $denomKey) 
            {
                $pct = '';
                $denomTotal = $data[$denomKey]['Total'] ?? 0;
                $valTotal   = $data[$metric]['Total']   ?? 0;
                if ($denomTotal > 0) {
                    $pct = round($valTotal / $denomTotal * 100, 1) . '%';
                }
                $body .= "<td class='wm-pct-val'>{$pct}</td>";
            } 
            elseif ($showPct) 
            {
                $body .= "<td class='wm-pct-val'></td>";
            }

            $body .= '</tr>';
        }

        // Emit total for the last subsection
        if ($lastSubsection !== '') {
            $body .= $this->subsectionTotalRow($data, $columns, $colCount, $lastSubsection, $lastSection, $showPct);
        }

        // ── Birth Weight subsection (Pre-Screening only) ──────────────────
        if (in_array('PreScreened', $sections, true)) {
            $body .= "<tr class='wm-subsection-hdr'>"
                   . "<td colspan='{$colCount}'>Birth Weight Distribution (baby_birth_wt_hosp)</td></tr>";

            $bwtRows = [
                'bwt_below_700'  => 'Below 700g',
                'bwt_700_999'    => '700 – 999g',
                'bwt_1000_1499'  => '1000 – 1499g',
                'bwt_1500_1800'	 => '1500 – 1800g',
                'bwt_above_1800' => 'Above 1800g',
            ];
            foreach ($bwtRows as $metric => $label) 
            {
                $body .= "<tr><td class='wm-metric-label'>" . htmlspecialchars($label) . "</td>";
                foreach ($columns as $col) 
                {
                    $val  = $data[$metric][$col] ?? 0;
                    $tcls = $col === 'Total' ? 'wm-total-val' : '';
                    $body .= "<td class='{$tcls}'>{$val}</td>";
                }
                if ($showPct) $body .= "<td class='wm-pct-val'></td>";
                $body .= '</tr>';
            }
        }

        return $this->wrapSection($title, $dateField, $dateRange, $headerRow, $body, $showPct);
    }

    /**
     * Render Discharge section using its own meta definition.
     */
    private function renderDischargeSection(
        string $dateRange,
        array  $data,
        array  $meta,
        array  $columns,
        array  $siteLabels
    ): string 
    {
        $colCount  = count($columns) + 1;
        $headerRow = $this->buildHeaderRow($columns, $siteLabels, false);

        $body        = '';
        $lastSection = '';

        foreach ($meta as $metric => $m) 
        {
            $section   = $m['section']   ?? '';
            $label     = $m['label']     ?? $metric;
            $dateField = $m['date_field'] ?? '';
            $isPrimary = ($m['role'] ?? '') === 'primary';

            if ($section !== $lastSection) {
                $lastSection = $section;
                // Section header includes date field
                $body .= "<tr class='wm-section-hdr'>"
                       . "<td colspan='{$colCount}'>{$section}"
                       . ($dateField ? " <span class='wm-date-field'>({$dateField})</span>" : '')
                       . "</td></tr>";
            }

            $cls = $isPrimary ? 'wm-primary' : '';
            $isStillInHosp = ($metric === 'dis_still_in_hospital');
            $isIndented    = ($m['indent'] ?? false);
            $labelSuffix = $isStillInHosp
                ? " <span class='wm-formula-note' title='Enrolled minus (Discharged + PD + SAE + Withdrawal)'>= Enrolled &minus; Stopped</span>"
                : '';
            $labelClass = $isIndented ? 'wm-metric-label wm-metric-indented' : 'wm-metric-label';
            $body .= "<tr class='{$cls}'><td class='{$labelClass}'>"
                   . htmlspecialchars($label) . $labelSuffix . "</td>";

            foreach ($columns as $col) {
                $val  = $data[$metric][$col] ?? 0;
                $tcls = $col === 'Total' ? 'wm-total-val' : '';
                $body .= "<td class='{$tcls}'>{$val}</td>";
            }
            $body .= '</tr>';
        }

        return $this->wrapSection('Discharge', 'dis_datetime / pd_datetime / fu28_datetime', $dateRange, $headerRow, $body, false);
    }

    // =========================================================================
    // Discharge meta
    // =========================================================================

    private function dischargeMeta(): array
    {
        return [
            // ── Discharge ────────────────────────────────────────────────────
            'dis_total'        => ['label' => 'Total Discharge',                  'section' => 'Discharge',        'date_field' => 'dis_datetime',   'role' => 'primary'],
            'dis_lama_abscond' => ['label' => 'Total LAMA / Abscond',             'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_dopr'         => ['label' => 'Total DOPR',                       'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_protocol_dev' => ['label' => 'Protocol Deviation',               'section' => 'Discharge',        'date_field' => 'pd_datetime'],
            'dis_sae'          => ['label' => 'SAE',                              'section' => 'Discharge',        'date_field' => 'sae_datetime'],
            'dis_withdrawal'   => ['label' => 'Withdrawal',                       'section' => 'Discharge',        'date_field' => 'sw_datetime'],
            'dis_death'        => ['label' => 'Death at Discharge',               'section' => 'Discharge',        'date_field' => 'dis_datetime'],
            'dis_death_fu29'   => ['label' => 'Death at 29-day Follow-up',        'section' => 'Discharge',        'date_field' => 'fu28_datetime'],
            // ── Milestones ───────────────────────────────────────────────────
            'dis_still_in_hospital'              => ['label' => 'Still in Hospital',                      'section' => 'Milestones', 'date_field' => 'up to date_to',  'role' => 'primary'],
            'dis_still_in_hospital_intervention' => ['label' => 'Intervention',   'section' => 'Milestones', 'date_field' => '', 'indent' => true],
            'dis_still_in_hospital_control'      => ['label' => 'Control',         'section' => 'Milestones', 'date_field' => '', 'indent' => true],
            'dis_28_days'                        => ['label' => '28 days completed — still in Hospital',  'section' => 'Milestones', 'date_field' => '(no date filter)'],
            // ── 29-day Follow-up ─────────────────────────────────────────────
            'dis_fu29_done'    => ['label' => 'Total 29-day Follow-up Completed', 'section' => '29-Day Follow-up', 'date_field' => 'fu28_datetime',  'role' => 'primary'],
        ];
    }

    // =========================================================================
    // HTML helpers
    /**
     * Emit a subsection total row.
     * Map of subsection name → metric key that holds the subtotal.
     */
    /**
     * Emit a subsection total row.
     * Uses $section + $subsection together to pick the correct derived metric key.
     */
    private function subsectionTotalRow(
        array  $data,
        array  $columns,
        int    $colCount,
        string $subsection,
        string $section,
        bool   $showPct
    ): string 
    {
        // Map section+subsection → the derived total metric key from EligibilityWorkflow
        $metricKeyMap = [
            'PreScreened|Single Exclusion'   => 'prescrn_single_excl',
            'PreScreened|Multiple Exclusion' => 'prescrn_multiple_excl',
            'Screened|Single Exclusion'      => 'screened_single_excl',
            'Screened|Multiple Exclusion'    => 'screened_multiple_excl',
        ];

        $mapKey    = "{$section}|{$subsection}";
        $metricKey = $metricKeyMap[$mapKey] ?? null;
        if ($metricKey === null) return '';

        $row  = "<tr class='wm-subsection-total'>";
        $row .= "<td class='wm-metric-label'><strong>Total {$subsection}</strong></td>";
        foreach ($columns as $col) 
        {
            $val  = $data[$metricKey][$col] ?? 0;
            $tcls = $col === 'Total' ? 'wm-total-val' : '';
            $row .= "<td class='{$tcls}'><strong>{$val}</strong></td>";
        }
        if ($showPct) $row .= "<td class='wm-pct-val'></td>";
        $row .= '</tr>';
        return $row;
    }

    // =========================================================================

    private function buildHeaderRow(array $columns, array $siteLabels, bool $showPct): string
    {
        $row = '<tr><th class="wm-metric-col">Metric</th>';
        foreach ($columns as $col) 
        {
            $lbl = $col === 'Total' ? 'Total' : ($siteLabels[$col] ?? $col);
            $cls = $col === 'Total' ? 'wm-total-col' : 'wm-site-col';
            $row .= "<th class='{$cls}'>" . htmlspecialchars($lbl) . "</th>";
        }
        if ($showPct) $row .= '<th class="wm-pct-col">%</th>';
        $row .= '</tr>';
        return $row;
    }

    private function wrapSection(
        string $title,
        string $dateField,
        string $dateRange,
        string $headerRow,
        string $body,
        bool   $showPct
    ): string 
    {
        $metaHtml = $dateField
            ? "<span class='wm-section-meta'>Date field: <code>" . htmlspecialchars($dateField) . "</code> &mdash; " . htmlspecialchars($dateRange) . "</span>"
            : '';
        return "
  <div class='wm-section-wrap'>
    <div class='wm-section-title'>
      <span class='wm-section-name'>" . htmlspecialchars($title) . "</span>
      {$metaHtml}
    </div>
    <div class='wm-table-wrap'>
      <table class='wm-table'>
        <thead>{$headerRow}</thead>
        <tbody>{$body}</tbody>
      </table>
    </div>
  </div>
";
    }

    private function dateRangeLabel(?string $from, ?string $to): string
    {
        if ($from && $to)  return "Period: {$from} to {$to}";
        if ($to)           return "Period: up to {$to}";
        if ($from)         return "Period: from {$from}";
        return 'All dates';
    }

    private function inlineCss(): string
    {
        return $this->readFile('css/emollient_eligible.css')
             . "\n"
             . $this->readFile('css/weekly_meeting.css');
    }

    private function readFile(string $relativePath): string
    {
        $abs = realpath(
            __DIR__ . '/../../../reporting-engine/public/assets/' . $relativePath
        );
        return ($abs && file_exists($abs)) ? file_get_contents($abs) : '';
    }
}

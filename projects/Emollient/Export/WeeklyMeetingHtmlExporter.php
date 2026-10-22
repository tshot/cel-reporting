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
        $sepsis       = $payload['sepsis']       ?? [];
        $emollient    = $payload['emollient']    ?? [];
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

        // Build ordered site columns from all five sections
        $allSites = [];
        foreach ([$prescreening, $screening, $discharge, $sepsis, $emollient] as $sectionData) {
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
      <button class="wm-btn" onclick="wmDownloadFormat('csv')">&#8659; Export CSV</button>
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

    // ── Sections 4 & 5 come from separate pulls — show a failure in its section ─
    $tm      = $payload['timing'] ?? [];
    $errNote = fn(string $part, string $what) => !empty($tm["{$part}_error"])
        ? '<strong style="color:#c0392b">' . $what . ' data could not be fetched from REDCap: '
          . htmlspecialchars($tm["{$part}_error"]) . ' — figures below are incomplete.</strong>'
        : '';

    // ── Section 4: Sepsis & Blood Workup ─────────────────────────────────────
    $sn = $payload['sepsisNotes'] ?? [];
    $lb = (int)($sn['lookback_days'] ?? 2);
    echo $this->renderMetaSection(
        title:      'Sepsis & Blood Workup',
        dateField:  'nss_datetime',
        dateRange:  $dateRange,
        data:       $sepsis,
        meta:       $payload['sepsisMeta'] ?? [],
        columns:    $columns,
        siteLabels: $siteLabels,
        notes:      array_filter([
            $errNote('sepsis', 'Sepsis'),
            'Counts are babies. Tests (CBC, CRP, culture) are taken from any sepsis form from '
          . "{$lb} day(s) before the baby&rsquo;s first Confirmed / Suspected form in the period up to the period end; a baby counts once per test. "
          . 'Culture result = latest result entered up to the period end (Positive &gt; Negative &gt; Awaited). '
          . '% = of babies in the group; culture results = of babies with culture done.',
            ($sn['exclusive'] ?? true)
                ? 'Suspected excludes babies also confirmed in the period, so Confirmed + Suspected = distinct babies (add &amp;sepsis_exclusive=0 to the URL to count them in both).'
                : 'A baby with both impressions in the period is counted in both Confirmed and Suspected (remove &amp;sepsis_exclusive=0 from the URL to count each baby once).',
            'Pending from earlier weeks: the baby&rsquo;s last culture before the period start had no result by then, however long ago. '
          . '&ldquo;Received&rdquo; = result entered on a form dated in this period. A result typed into the original culture form cannot be dated, '
          . 'so such a baby drops out of the pending list instead of showing as received.',
            'Baby-wise detail: Excel sheets “Sepsis PerBaby”, “Sepsis Forms” and “Sepsis Pending” (with age in days).',
            !empty($sn['undated']) ? (int)$sn['undated'] . ' Confirmed / Suspected form(s) with no nss_datetime were not counted.' : '',
            !empty($sn['no_site']) ? (int)$sn['no_site'] . ' baby(ies) with no site were not counted.' : '',
            !empty($sn['other_result_codes'])
                ? 'Other culture result codes: ' . htmlspecialchars(implode(', ', array_keys($sn['other_result_codes']))) . '.' : '',
        ])
    );

    // ── Section 5: Emolliation Stopped ───────────────────────────────────────
    $en = $payload['emollientNotes'] ?? [];
    echo $this->renderMetaSection(
        title:      'Emolliation Stopped',
        dateField:  'int_datetime',
        dateRange:  $dateRange,
        data:       $emollient,
        meta:       $payload['emollientMeta'] ?? [],
        columns:    $columns,
        siteLabels: $siteLabels,
        notes:      array_filter([
            $errNote('emollient', 'Emolliation'),
            'Stopped = int_emoliate_baby is N. Full day stopped = every recorded instance of a study day is N. '
          . 'An instance can carry more than one reason, so reason rows can add up to more than the stopped count. '
          . 'Baby-wise detail: Excel sheets “Emollient PerBaby” and “Emollient Stopped”.',
            !empty($en['undated']) ? (int)$en['undated'] . ' emolliation instance(s) with no int_datetime were not counted.' : '',
            !empty($en['no_site']) ? (int)$en['no_site'] . ' emolliation instance(s) with no site were not counted.' : '',
        ])
    );

    // ── Timing footer (screen only) ──────────────────────────────────────────
    if ($tm) {
        $parts = ['Main REDCap pull + sections 1–3: ' . ($tm['main_seconds'] ?? '?') . ' s'];
        foreach (['sepsis' => 'Sepsis pull', 'emollient' => 'Emolliation pull'] as $part => $lbl) {
            if (!isset($tm["{$part}_seconds"])) continue;
            $parts[] = "{$lbl}: " . $tm["{$part}_seconds"] . ' s for ' . (int)$tm["{$part}_babies"]
                     . ' babies (' . (int)$tm["{$part}_rows"] . ' rows)';
        }
        $parts[] = 'Total: ' . ($tm['total_seconds'] ?? '?') . ' s';
        echo "<div class='wm-timing'>" . htmlspecialchars(implode('  ·  ', $parts)) . "</div>";
    }
?>

</div><!-- .report-container -->

<?= $js ?>
<script>
// Re-request the same report (same dates / sites) in another server-side format.
// format=csv  → WeeklyMeetingCsvExporter, format=excel → WeeklyMeetingExcelExporter
function wmDownloadFormat(fmt) {
    var url = window.location.href;
    if (url.indexOf('format=') !== -1) {
        url = url.replace(/format=[^&]+/, 'format=' + fmt);
    } else {
        url += (url.indexOf('?') !== -1 ? '&' : '?') + 'format=' + fmt;
    }
    window.location.href = url;
}
function wmDownloadExcel() { wmDownloadFormat('excel'); }
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

    /**
     * Render a section from a self-describing meta array (Sepsis, Emolliation).
     * meta: [metric => [label, role (primary|sub|sub2), section, pct_of]]
     * % column = Total / Total of pct_of (same convention as the other sections).
     */
    private function renderMetaSection(
        string $title,
        string $dateField,
        string $dateRange,
        array  $data,
        array  $meta,
        array  $columns,
        array  $siteLabels,
        array  $notes = []
    ): string
    {
        $colCount  = count($columns) + 2;
        $headerRow = $this->buildHeaderRow($columns, $siteLabels, true);

        $body        = '';
        $lastSection = null;

        foreach ($meta as $metric => $m)
        {
            // Hide the "other culture result" row when nobody has one
            if (str_ends_with($metric, '_cult_other') && (int)($data[$metric]['Total'] ?? 0) === 0) continue;

            $section = $m['section'] ?? '';
            if ($section !== $lastSection) {
                $lastSection = $section;
                $body .= "<tr class='wm-section-hdr'><td colspan='{$colCount}'>"
                       . htmlspecialchars($section) . "</td></tr>";
            }

            $role       = $m['role'] ?? 'sub';
            $cls        = $role === 'primary' ? 'wm-primary' : '';
            $labelClass = $role === 'sub2' ? 'wm-metric-label wm-metric-indented' : 'wm-metric-label';

            $body .= "<tr class='{$cls}'><td class='{$labelClass}'>" . htmlspecialchars($m['label'] ?? $metric) . "</td>";
            foreach ($columns as $col) {
                $val  = $data[$metric][$col] ?? 0;
                $tcls = $col === 'Total' ? 'wm-total-val' : '';
                $body .= "<td class='{$tcls}'>{$val}</td>";
            }

            $pct = '';
            if (!empty($m['pct_of'])) {
                $den = (int)($data[$m['pct_of']]['Total'] ?? 0);
                if ($den > 0) $pct = round(($data[$metric]['Total'] ?? 0) / $den * 100, 1) . '%';
            }
            $body .= "<td class='wm-pct-val'>{$pct}</td></tr>";
        }

        if (!$meta) {
            $body .= "<tr><td class='wm-metric-label' colspan='{$colCount}'>No data for this period.</td></tr>";
        }
        if ($notes) {
            $body .= "<tr class='wm-section-note'><td colspan='{$colCount}'>" . implode('<br>', $notes) . "</td></tr>";
        }

        return $this->wrapSection($title, $dateField, $dateRange, $headerRow, $body, true);
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

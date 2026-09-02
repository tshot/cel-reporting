<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * EligibilityHtmlExporter
 *
 * Renders the Emollient eligibility report as a 3-page WHO-style HTML report:
 *   Page 1 — Prescreening / Screening summary with CONSORT diagram
 *   Page 2 — Enrollment breakup by site and study arm
 *   Page 3 — Demographics summary (Intervention / Control / Total)
 *
 * Two modes:
 *   inline = true  → embeds CSS in <style> tag (CLI / email, self-contained)
 *   inline = false → links to $cssPath (web serving)
 */
class EligibilityHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '/assets/css/emollient_eligible.css',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->cssPath = $cssPath;
        $this->toolbar = $toolbar;
    }

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);

        $html = $this->render(
            $payload['summary'],
            $payload['metricMeta'],
            $payload['workflow'],
            $payload['enrollment']   ?? [],
            $payload['demographics'] ?? [],
            $payload['label_map']    ?? [],
            $payload['site_labels']  ?? [],
            $payload['period']       ?? []
        );

        if ($outputPath) 
        {
            file_put_contents($outputPath, $html);
        } 
        else 
        {
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }

    // -------------------------------------------------------------------------
    // Section export — renders a single section as a standalone interactive
    // page with contenteditable, colour controls, and PNG/PDF/HTML export.
    // -------------------------------------------------------------------------
    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->renderSection($section, $payload, $inline);

        if ($outputPath) {
            file_put_contents($outputPath, $html);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }

    private function renderSection(string $section, array $payload, bool $inline = false): string
    {
        $summary      = $payload['summary']      ?? [];
        $summaryByDc  = $payload['summary_by_dc'] ?? [];
        $metricMeta   = $payload['metricMeta']   ?? [];
        $workflow     = $payload['workflow']     ?? [];
        $enrollment   = $payload['enrollment']   ?? [];
        $demographics = $payload['demographics'] ?? [];
        $labelMap     = $payload['label_map']    ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $period       = $payload['period']       ?? [];

        // Collect site columns in site_labels.php order (not alphabetical).
        // site_labels keys define the display sequence; any site codes not in
        // the map are appended at the end.
        $allSiteCodes = [];
        foreach ($summary as $rows) {
            if (is_array($rows)) {
                foreach (array_keys($rows) as $col) {
                    if ($col !== 'Total') $allSiteCodes[$col] = true;
                }
            }
        }
        $sites = [];
        foreach (array_keys($siteLabels) as $code) {
            if (isset($allSiteCodes[$code])) $sites[] = $code;
        }
        foreach (array_keys($allSiteCodes) as $code) {
            if (!in_array($code, $sites, true)) $sites[] = $code;
        }
        $columns = array_merge(['Total'], $sites);

        $dateFrom = $period['date_from'] ?? null;
        $dateTo   = $period['date_to']   ?? null;
        if ($dateFrom && $dateTo)   $periodLabel = "Period: {$dateFrom} to {$dateTo}";
        elseif ($dateTo)            $periodLabel = "Period: up to {$dateTo}";
        elseif ($dateFrom)          $periodLabel = "Period: from {$dateFrom}";
        else                        $periodLabel = "Period: All dates";

        switch ($section) {
            case 'consort':
                $title       = 'CONSORT Diagram';
                $content     = $this->renderConsort($workflow);
                $hasColumns  = false;
                $colDefs     = [];
                break;
            case 'screening':
                $title       = 'Prescreening / Screening / Enrollment';
                $content     = $this->renderScreeningTable($summary, $metricMeta, $columns, $siteLabels, $periodLabel);
                $hasColumns  = true;
                // col index 0 = Metric label, 1..n = data columns
                $colDefs = [['idx' => 1, 'label' => 'Total']];
                foreach ($sites as $i => $s) {
                    $colDefs[] = ['idx' => $i + 2, 'label' => $siteLabels[$s] ?? $s];
                }
                break;
            case 'enrollment':
                // Enrollment is now merged into the screening table.
                // Redirect to screening section for backward compatibility.
                $title       = 'Prescreening / Screening / Enrollment';
                $content     = $this->renderScreeningTable($summary, $metricMeta, $columns, $siteLabels, $periodLabel);
                $hasColumns  = true;
                $colDefs = [['idx' => 1, 'label' => 'Total']];
                foreach ($sites as $i => $s) {
                    $colDefs[] = ['idx' => $i + 2, 'label' => $siteLabels[$s] ?? $s];
                }
                break;
            case 'demographics':
                $title       = 'Demographics Summary';
                $content     = $this->renderDemographics($demographics, $labelMap, $periodLabel);
                $hasColumns  = true;
                $colDefs     = [
                    ['idx' => 1, 'label' => 'Intervention'],
                    ['idx' => 2, 'label' => 'Control'],
                    ['idx' => 3, 'label' => 'Total'],
                ];
                break;
            case 'dc_breakdown':
                $title = 'Prescreening / Screening — by Data Collector';
                // Build DC columns from summary_by_dc keys (sorted alphabetically)
                $allDcCodes = [];
                foreach ($summaryByDc as $rows) {
                    if (is_array($rows)) {
                        foreach (array_keys($rows) as $col) {
                            if ($col !== 'Total') $allDcCodes[$col] = true;
                        }
                    }
                }
                ksort($allDcCodes);
                $dcCols  = array_merge(['Total'], array_keys($allDcCodes));
                // Reuse renderScreeningTable — DC codes as column headers, no site label map
                $content = $this->renderScreeningTable($summaryByDc, $metricMeta, $dcCols, [], $periodLabel);
                $hasColumns = true;
                $colDefs = [['idx' => 1, 'label' => 'Total']];
                foreach (array_keys($allDcCodes) as $i => $dc) {
                    $colDefs[] = ['idx' => $i + 2, 'label' => $dc];
                }
                break;
            default:
                $title      = 'Unknown section';
                $content    = '<p>Unknown section: ' . htmlspecialchars($section) . '</p>';
                $hasColumns = false;
                $colDefs    = [];
        }

        // Build column manager panel items as JSON for JS
        $colDefsJson    = json_encode($colDefs);
        $hasColsJson    = $hasColumns ? 'true' : 'false';

        // ── Report CSS (always inlined — needed for table styling) ────────────
        $cssFile    = realpath(__DIR__ . '/../../../reporting-engine/public/assets/css/emollient_eligible.css');
        $reportCss  = ($cssFile && file_exists($cssFile)) ? file_get_contents($cssFile) : '';

        // ── UI CSS and JS — external when on server, inlined for download ──────
        if ($inline) {
            $uiCssFile = realpath(__DIR__ . '/../../../reporting-engine/public/assets/css/section-export.css');
            $uiJsFile  = realpath(__DIR__ . '/../../../reporting-engine/public/assets/js/section-export.js');
            $uiCss     = ($uiCssFile && file_exists($uiCssFile)) ? file_get_contents($uiCssFile) : '';
            $uiJs      = ($uiJsFile  && file_exists($uiJsFile))  ? file_get_contents($uiJsFile)  : '';
            $uiCssTag  = "<style>\n{$uiCss}\n</style>";
            $uiJsTag   = "<script>\n{$uiJs}\n</script>";
        } else {
            $uiCssTag  = "<link rel='stylesheet' href='/assets/css/section-export.css'>";
            $uiJsTag   = "<script src='/assets/js/section-export.js'></script>";
        }

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Emollient &mdash; <?= htmlspecialchars($title) ?></title>
<style>
<?= $reportCss ?>
</style>
<?= $uiCssTag ?>
</head>
<body>

<div id="export-toolbar">
    <span class="section-title"><?= htmlspecialchars($title) ?></span>
    <span class="sep">|</span>
    <div class="colour-row">
        <label title="Table header background">Hdr <input type="color" id="hdr-color" value="#e6e6e6"></label>
        <label title="Section row background">Section <input type="color" id="sec-color" value="#f2f2f2"></label>
    </div>
    <span class="sep">|</span>
    <button class="exp-btn" id="col-toggle-btn" onclick="toggleColPanel()" style="display:none">&#8645; Columns</button>
    <button class="exp-btn" onclick="window.print()">&#128438; Print / PDF</button>
    <button class="exp-btn" onclick="copyImage()">&#128247; Copy image</button>
    <button class="exp-btn" onclick="downloadHtml()">&#8659; HTML</button>
    <button class="exp-btn" onclick="copyTable()">&#128203; Copy table</button>
</div>

<div id="page-body">
    <div id="section-wrap">
        <?= $content ?>
    </div>
    <div id="col-panel">
        <h4>&#8645; Manage columns</h4>
        <p class="hint">Drag to reorder. Uncheck to hide. Click Apply to update the table.</p>
        <div id="col-list"></div>
        <button class="apply-btn" onclick="applyColumns()">&#10003; Apply</button>
    </div>
</div>

<script src="/assets/js/html2canvas.min.js"></script>
<script>
// Data variables — always inline since they are dynamic per-request
window.SECTION_HAS_COLUMNS = <?= $hasColsJson ?>;
window.SECTION_COL_DEFS    = <?= $colDefsJson ?>;
window.SECTION_NAME        = <?= json_encode($section) ?>;
</script>
<?= $uiJsTag ?>
</body>
</html>
<?php
        return ob_get_clean();
    }

    // -------------------------------------------------------------------------
    // Main render — assembles the full 3-page report
    // -------------------------------------------------------------------------
    private function render(
        array $summary,
        array $metricMeta,
        array $workflow,
        array $enrollment   = [],
        array $demographics = [],
        array $labelMap     = [],
        array $siteLabels   = [],
        array $period       = []
    ): string
    {
        $currentDate = (new \DateTime())->format('Y-m-d H:i');

        $dateFrom = $period['date_from'] ?? null;
        $dateTo   = $period['date_to']   ?? null;
        if ($dateFrom && $dateTo)   $periodLabel = "Period: {$dateFrom} to {$dateTo}";
        elseif ($dateTo)            $periodLabel = "Period: up to {$dateTo}";
        elseif ($dateFrom)          $periodLabel = "Period: from {$dateFrom}";
        else                        $periodLabel = "Period: All dates";

        // Collect site columns in site_labels.php order (not alphabetical).
        $allSiteCodes = [];
        foreach ($summary as $rows)
        {
            if (is_array($rows))
            {
                foreach (array_keys($rows) as $col)
                {
                    if ($col !== 'Total') $allSiteCodes[$col] = true;
                }
            }
        }
        $sites = [];
        foreach (array_keys($siteLabels) as $code)
        {
            if (isset($allSiteCodes[$code])) $sites[] = $code;
        }
        foreach (array_keys($allSiteCodes) as $code)
        {
            if (!in_array($code, $sites, true)) $sites[] = $code;
        }
        $columns = array_merge(['Total'], $sites);

        $html  = $this->htmlHead();
        $html .= $this->toolbar;
        $html .= $this->renderConsort($workflow);
        $html .= $this->renderScreeningTable($summary, $metricMeta, $columns, $siteLabels, $periodLabel);

        if (!empty($demographics))
            $html .= $this->renderDemographics($demographics, $labelMap, $periodLabel);

        $html .= '</body></html>';

        return $html;
    }

    // ── Extracted screening table render (used by full render and section) ─
    private function renderScreeningTable(
        array $summary, array $metricMeta, array $columns,
        array $siteLabels, string $periodLabel
    ): string {
        $currentDate = (new \DateTime())->format('Y-m-d H:i');

        $html  = "<table class='report-table compact'>";
        $html .= "<caption>Emollient Prescreening / Screening / Enrollment"
               . "<span class='report-period'>{$periodLabel}</span>"
               . "<span class='report-date'>Report generated: {$currentDate}</span></caption>";
        $html .= "<thead><tr><th>Metric</th>";
        foreach ($columns as $col) {
            $display = ($col === 'Total') ? 'Total' : ($siteLabels[$col] ?? $col);
            $html .= '<th>' . htmlspecialchars($display) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        $currentSection = null;
        $currentSub     = null;

        foreach ($metricMeta as $metric => $meta) {
            if ($meta['section'] !== $currentSection) {
                $currentSection = $meta['section'];
                $currentSub     = null;
                $span = count($columns) + 1;
                $html .= "<tr class='section-main'><td colspan='{$span}'>"
                       . htmlspecialchars($currentSection) . "</td></tr>";
            }
            if (($meta['role'] ?? '') === 'primary') {
                $html .= "<tr class='total-row'><td><strong>"
                       . htmlspecialchars($meta['label']) . "</strong></td>";
                foreach ($columns as $col) $html .= '<td>' . ($summary[$metric][$col] ?? 0) . '</td>';
                $html .= '</tr>';
                continue;
            }
            if (!empty($meta['subsection']) && $meta['subsection'] !== $currentSub) {
                $currentSub  = $meta['subsection'];
                $subTotalKey = $meta['subsection_total_metric'] ?? null;
                $denKey      = $meta['denominator'] ?? null;
                $html .= "<tr class='section-sub'><td><strong>"
                       . htmlspecialchars($currentSub) . "</strong></td>";
                foreach ($columns as $col) {
                    $subCount = $subTotalKey ? ($summary[$subTotalKey][$col] ?? 0) : 0;
                    if ($denKey && ($summary[$denKey][$col] ?? 0) > 0) {
                        $den = $summary[$denKey][$col];
                        $pct = number_format($subCount / $den * 100, 1);
                        $html .= "<td><strong>{$subCount} ({$pct}%)</strong></td>";
                    } else {
                        $html .= "<td><strong>{$subCount}</strong></td>";
                    }
                }
                $html .= '</tr>';
            }
            $html   .= "<tr class='indent-1'><td>" . htmlspecialchars($meta['label']) . "</td>";
            $denKey  = $meta['denominator'] ?? null;
            foreach ($columns as $col) {
                $count = $summary[$metric][$col] ?? 0;
                if ($denKey && ($summary[$denKey][$col] ?? 0) > 0) {
                    $den  = $summary[$denKey][$col];
                    $pct  = number_format(($count / $den) * 100, 1);
                    $html .= "<td>{$count} ({$pct}%)</td>";
                } else {
                    $html .= "<td>{$count}</td>";
                }
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // CONSORT diagram — matches .consort / .consort-flow / .consort-box /
    // .consort-arrow / .consort-branch-row / .consort-branch-line /
    // .consort-exclude structure in styles.css
    // -------------------------------------------------------------------------
    private function renderConsort(array $w): string
    {
        $preExclReasons = $this->exclusionList($w['pre_excl_reasons'] ?? []);
        $scrExclReasons = $this->exclusionList($w['scr_excl_reasons'] ?? []);

        return "
<div class='consort'>
  <div class='consort-flow'>

    <div class='consort-box'>Babies PreScreened (N = {$w['prescreened']})</div>

    <div class='consort-branch-row'>
      <div class='consort-arrow'></div>
      <div class='consort-branch-line'></div>
      <div class='consort-exclude'>
        <strong>Excluded at PreScreening (N = {$w['pre_excl']})</strong>
        {$preExclReasons}
      </div>
    </div>

    <div class='consort-box'>Babies Screened (N = {$w['screened']})</div>

    <div class='consort-branch-row'>
      <div class='consort-arrow'></div>
      <div class='consort-branch-line'></div>
      <div class='consort-exclude'>
        <strong>Excluded at Screening (N = {$w['scr_excl']})</strong>
        {$scrExclReasons}
      </div>
    </div>

    <div class='consort-box'>Eligible for Enrollment (N = {$w['eligible']})</div>

  </div>
</div>";
    }

    // -------------------------------------------------------------------------
    // Build exclusion reason list — only show reasons with count > 0
    // -------------------------------------------------------------------------
    private function exclusionList(array $reasons): string
    {
        $items = array_filter($reasons, fn($n) => $n > 0);
        if (empty($items)) return '';

        $html = '<ul>';
        foreach ($items as $label => $count) 
        {
            $html .= '<li>' . htmlspecialchars($label) . ' (' . $count . ')</li>';
        }
        $html .= '</ul>';
        return $html;
    }

    // -------------------------------------------------------------------------
    // HTML head — links external styles.css or inlines it for CLI
    // -------------------------------------------------------------------------
    private function htmlHead(): string
    {
        if ($this->inline) 
        {
            // __DIR__ = .../projects/Emollient/Export
            // 3 levels up reaches the monorepo root, then into reporting-engine
            $cssFile  = realpath(__DIR__ . '/../../../reporting-engine/public/assets/css/emollient_eligible.css');
            $css      = ($cssFile && file_exists($cssFile)) ? file_get_contents($cssFile) : '';
            $styleTag = "<style>\n{$css}\n</style>";
        } 
        else 
        {
            $styleTag = "<link rel='stylesheet' href='{$this->cssPath}'>";
        }

        return "<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<title>Emollient Eligibility Report</title>
{$styleTag}
</head>
<body>";
    }

    // -------------------------------------------------------------------------
    // Enrollment breakup table — rendered below the eligibility table
    // -------------------------------------------------------------------------
    private function renderEnrollmentBreakup(array $enrollment, array $siteLabels = [], string $periodLabel = ''): string
    {
        $sites = array_filter($enrollment, fn($k) => $k !== 'Total', ARRAY_FILTER_USE_KEY);
        $total = $enrollment['Total'] ?? null;

        $rows = '';
        foreach ($sites as $site => $d)
        {
            $display = $siteLabels[$site] ?? $site;
            $incomplete = $d['incomplete'] ?? 0;
            $incCell    = $incomplete > 0
                ? "<span style='background:#fff3e0;color:#e65100;font-weight:600;"
                  . "border-radius:4px;padding:1px 7px;font-size:11px;'"
                  . " title='Enrollment form saved without study arm selection'>"
                  . "&#9888; {$incomplete}</span>"
                : "<span style='color:#9e9e9e'>0</span>";
            $rows .= '<tr>'
                   . '<td>' . htmlspecialchars($display) . '</td>'
                   . '<td>' . $d['consent_refused'] . '</td>'
                   . '<td>' . $d['intervention'] . '</td>'
                   . '<td>' . $d['control'] . '</td>'
                   . '<td>' . $d['enrolled'] . '</td>'
                   . "<td style='text-align:center'>{$incCell}</td>"
                   . '</tr>';
        }

        if ($total)
        {
            $totalInc     = $total['incomplete'] ?? 0;
            $totalIncCell = $totalInc > 0
                ? "<strong><span style='background:#fff3e0;color:#e65100;font-weight:700;"
                  . "border-radius:4px;padding:1px 7px;font-size:11px;'>"
                  . "&#9888; {$totalInc}</span></strong>"
                : "<span style='color:#9e9e9e'>0</span>";
            $rows .= '<tr class="total-row">'
                   . '<td><strong>Total</strong></td>'
                   . '<td><strong>' . $total['consent_refused'] . '</strong></td>'
                   . '<td><strong>' . $total['intervention'] . '</strong></td>'
                   . '<td><strong>' . $total['control'] . '</strong></td>'
                   . '<td><strong>' . $total['enrolled'] . '</strong></td>'
                   . "<td style='text-align:center'>{$totalIncCell}</td>"
                   . '</tr>';
        }

        // Warning banner — shown when any site has incomplete enrollment forms.
        // Use Total bucket directly — it's already the sum across all sites.
        // (array_column would double-count since Total is included in $enrollment.)
        $totalIncomplete = $enrollment['Total']['incomplete'] ?? 0;
        $warningBanner   = '';
        if ($totalIncomplete > 0) {
            $warningBanner = "<div style='background:#fff3e0;border-left:4px solid #e65100;"
                . "padding:10px 16px;margin-bottom:14px;border-radius:0 6px 6px 0;"
                . "font-size:13px;color:#bf360c;'>"
                . "<strong>&#9888; {$totalIncomplete} incomplete enrollment form(s)</strong> — "
                . "consent recorded but study arm not yet selected. "
                . "Please complete the enrollment form in REDCap."
                . "</div>";
        }

        $periodSpan = $periodLabel
            ? "<span class='report-period'>{$periodLabel}</span>"
            : '';

        return $warningBanner . "
<div class='section-gap'></div>
<table class='report-table compact'>
  <caption>Enrollment Breakup{$periodSpan}
    <span class='report-date'>Consent Refused and Study Arm Allocation</span>
  </caption>
  <thead>
    <tr>
      <th>Site</th>
      <th>Consent Refused</th>
      <th>Intervention</th>
      <th>Control</th>
      <th>Total Enrolled</th>
      <th title='Consented but study arm not yet assigned — incomplete form'>Incomplete &#9888;</th>
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>";
    }


    // -------------------------------------------------------------------------
    // -------------------------------------------------------------------------
    // Page 3 - Demographics summary
    // Rows: variables. Columns: Intervention | Control | Total
    // Continuous  : Mean (SD) + Median (IQR)
    // Categorical : n (%) per category value
    // -------------------------------------------------------------------------
    private function renderDemographics(array $demographics, array $labelMap = [], string $periodLabel = ''): string
    {
        // ── Split records by study arm ────────────────────────────────────────
        $arms = ['Intervention' => [], 'Control' => [], 'Total' => []];

        foreach ($demographics as $d)
        {
            // enr_study_arm is the authoritative arm assignment (same field used
            // in the enrollment table). base_study_arm may be blank if baseline
            // form not yet completed — using it as primary caused n mismatch.
            $arm = trim((string)($d['enr_study_arm'] ?? $d['base_study_arm'] ?? $d['ses_study_arm'] ?? ''));

            if ($arm === 'Intervention') {
                $arms['Intervention'][] = $d;
                $arms['Total'][]        = $d;   // Total = Intervention + Control only
            } elseif ($arm === 'Control') {
                $arms['Control'][]      = $d;
                $arms['Total'][]        = $d;   // babies with unknown arm excluded from Total
            }
            // Babies with blank/unknown arm are excluded from all columns —
            // including Total — so that Intervention + Control = Total always.
        }

        $n = [
            'Intervention' => count($arms['Intervention']),
            'Control'      => count($arms['Control']),
            'Total'        => count($arms['Total']),
        ];

        // ── Continuous: Mean (SD) + Median (IQR)  Min–Max ───────────────────
        // fmt(): formats a float cleanly — no trailing zeros, commas for thousands
        $fmt = function(float $v): string {
            if ($v == floor($v)) return number_format((int)$v);          // whole: 20,000
            $s = rtrim(rtrim(number_format($v, 1), '0'), '.');
            // re-add thousands separator if >= 1000
            return number_format((float)str_replace(',', '', $s), 1);
        };

        $continuous = function(array $records, string $field) use ($fmt): string
        {
            $vals = array_values(array_filter(
                array_map(fn($d) => ($d[$field] ?? '') !== '' ? (float)$d[$field] : null, $records),
                fn($v) => $v !== null
            ));
            $cnt = count($vals);
            if ($cnt === 0) return '—';

            $mean = array_sum($vals) / $cnt;
            $sd   = $cnt > 1
                ? sqrt(array_sum(array_map(fn($v) => ($v - $mean) ** 2, $vals)) / ($cnt - 1))
                : 0.0;

            sort($vals);
            $median = $this->percentile($vals, 0.50);
            $p25    = $this->percentile($vals, 0.25);
            $p75    = $this->percentile($vals, 0.75);
            $min    = $vals[0];
            $max    = $vals[$cnt - 1];

            return sprintf(
                '%s (%s)<br><small>%s (%s&ndash;%s) / %s&ndash;%s</small>',
                $fmt($mean), $fmt($sd),
                $fmt($median), $fmt($p25), $fmt($p75),
                $fmt($min), $fmt($max)
            );
        };

        // ── Categorical: n(%) per value, codes resolved to labels ─────────────
        // $labelMap[$field][$rawCode] = 'Display Label'
        // If a code has no entry in the map it is shown as-is (safe fallback).
        $categorical = function(array $records, string $field) use ($labelMap): array
        {
            $fieldMap = $labelMap[$field] ?? [];   // code → label for this field

            $counts = [];
            $total  = 0;
            foreach ($records as $d)
            {
                $raw = trim((string)($d[$field] ?? ''));
                if ($raw === '') continue;

                // Resolve to human-readable label; fall back to raw code
                $label = $fieldMap[$raw] ?? $raw;

                $counts[$label] = ($counts[$label] ?? 0) + 1;
                $total++;
            }

            // Preserve the order defined in label_map.php (not alphabetical).
            // First emit labels in label_map order, then any unmapped values.
            $out = [];
            foreach ($fieldMap as $code => $label)
            {
                if (isset($counts[$label]))
                {
                    $pct         = $total > 0 ? round($counts[$label] / $total * 100, 1) : 0;
                    $out[$label] = "{$counts[$label]} ({$pct}%)";
                    unset($counts[$label]);
                }
            }
            foreach ($counts as $label => $cnt)
            {
                $pct         = $total > 0 ? round($cnt / $total * 100, 1) : 0;
                $out[$label] = "{$cnt} ({$pct}%)";
            }
            return $out;
        };

        // ── Build HTML ────────────────────────────────────────────────────────
        $h  = '<thead><tr>'
            . '<th>Variable</th>'
            . "<th>Intervention<br><small>(n={$n['Intervention']})</small></th>"
            . "<th>Control<br><small>(n={$n['Control']})</small></th>"
            . "<th>Total<br><small>(n={$n['Total']})</small></th>"
            . '</tr></thead><tbody>';

        // ── Baseline section ──────────────────────────────────────────────────
        $h .= "<tr class='section-main'><td colspan='4'>Baseline Characteristics</td></tr>";

        foreach ([
            'baby_nicu_admit_time_hrs_mins' => 'Age at NICU Admission (hrs)',
            'baby_weight_nicu'              => 'Weight at NICU Admission (g)',
            'base_calc_ga_wks'              => 'Gestational Age (wks)',
        ] as $field => $label)
        {
            $h .= "<tr class='section-sub'><td colspan='4'><em>{$label} — Mean (SD) / Median (IQR) / Min&ndash;Max</em></td></tr>";
            $h .= '<tr>'
                . "<td class='indent-1'>{$label}</td>"
                . '<td>' . $continuous($arms['Intervention'], $field) . '</td>'
                . '<td>' . $continuous($arms['Control'],      $field) . '</td>'
                . '<td>' . $continuous($arms['Total'],        $field) . '</td>'
                . '</tr>';
        }

        // ── Socioeconomic section ─────────────────────────────────────────────
        $h .= "<tr class='section-main'><td colspan='4'>Socioeconomic Characteristics</td></tr>";

        // Continuous SES
        foreach ([
            'ses_fmly_incm' => 'Family Income',
            'ses_age_mthr'  => "Mother's Age (yrs)",
            'ses_age_fthr'  => "Father's Age (yrs)",
        ] as $field => $label)
        {
            $h .= "<tr class='section-sub'><td colspan='4'><em>{$label} — Mean (SD) / Median (IQR) / Min&ndash;Max</em></td></tr>";
            $h .= '<tr>'
                . "<td class='indent-1'>{$label}</td>"
                . '<td>' . $continuous($arms['Intervention'], $field) . '</td>'
                . '<td>' . $continuous($arms['Control'],      $field) . '</td>'
                . '<td>' . $continuous($arms['Total'],        $field) . '</td>'
                . '</tr>';
        }

        // Categorical SES
        foreach ([
            'ses_religion'      => 'Religion',
            'ses_caste'         => 'Caste',
            'ses_mthr_edu_qual' => "Mother's Education",
            'ses_head_edu_qual' => 'Household Head Education',
        ] as $field => $label)
        {
            $h .= "<tr class='section-sub'><td colspan='4'><em>{$label} — n (%)</em></td></tr>";

            $idxI = $categorical($arms['Intervention'], $field);
            $idxC = $categorical($arms['Control'],      $field);
            $idxT = $categorical($arms['Total'],        $field);

            // Use Total arm key order (which preserves label_map.php order).
            // Append any values present in Intervention or Control but not Total.
            $allVals = array_keys($idxT);
            foreach (array_merge(array_keys($idxI), array_keys($idxC)) as $v)
            {
                if (!in_array($v, $allVals, true)) $allVals[] = $v;
            }

            foreach ($allVals as $val)
            {
                $h .= '<tr>'
                    . '<td class="indent-1">' . htmlspecialchars($val) . '</td>'
                    . '<td>' . ($idxI[$val] ?? '0 (0%)') . '</td>'
                    . '<td>' . ($idxC[$val] ?? '0 (0%)') . '</td>'
                    . '<td>' . ($idxT[$val] ?? '0 (0%)') . '</td>'
                    . '</tr>';
            }
        }

        $h .= '</tbody>';
        $count = count($demographics);

        $periodSpan = $periodLabel
            ? "<span class='report-period'>{$periodLabel}</span>"
            : '';

        return "
<div class='section-gap'></div>
<table class='report-table compact'>
  <caption>Demographics Summary{$periodSpan}
    <span class='report-date'>Eligible enrolled babies (N = {$count}) — Baseline and Socioeconomic Characteristics by Study Arm</span>
  </caption>
  {$h}
</table>";
    }

    // ── Percentile (R type 7 linear interpolation) ────────────────────────────
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) return 0.0;
        if ($n === 1) return (float)$sorted[0];
        $h     = ($n - 1) * $p;
        $lower = (int) floor($h);
        $upper = (int) ceil($h);
        return $sorted[$lower] + ($h - $lower) * ($sorted[$upper] - $sorted[$lower]);
    }

}

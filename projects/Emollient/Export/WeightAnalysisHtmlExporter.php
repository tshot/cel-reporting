<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * WeightAnalysisHtmlExporter
 *
 * Renders the Weight Analysis report as an 8-section interactive HTML page.
 *
 * Sections:
 *   1  Summary cards
 *   2  Site summary table   (weights + velocity with colour-coded badges)
 *   3  Arm comparison table (Intervention vs Control)
 *   4  Site x Sex breakdown
 *   5  Mean weight trajectory chart (line, per site + All)
 *   6  Growth velocity charts (box plot + histogram side by side)
 *   7  Birth weight regain stacked bar (% by threshold per site)
 *   8  Per-patient table (sortable, filterable by site/arm/sex/ID)
 *
 * External assets:
 *   CSS  /assets/css/emollient_eligible.css  (shared base)
 *        /assets/css/weight_report.css       (weight-specific)
 *   JS   /assets/js/weight_report.js         (charts + filter + sort)
 *
 * inline=true inlines all CSS/JS for self-contained downloads.
 * Chart.js and datalabels plugin always loaded from CDN.
 */
class WeightAnalysisHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    private const PATH_CSS_BASE = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';
    private const PATH_CSS_WT   = '/../../../reporting-engine/public/assets/css/weight_report.css';
    private const PATH_JS_WT    = '/../../../reporting-engine/public/assets/js/weight_report.js';
    private const PATH_JS_SEC   = '/../../../reporting-engine/public/assets/js/section-export.js';
    private const PATH_CSS_SEC  = '/../../../reporting-engine/public/assets/css/section-export.css';

    private const SITE_COLORS = [
        '#1a5ea8','#2e7d52','#b35a00','#7b1fa2',
        '#006064','#c62828','#37474f','#00695c',
    ];

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
        $html    = $this->renderFullPage($payload);
        if ($outputPath) { file_put_contents($outputPath, $html); }
        else             { header('Content-Type: text/html; charset=UTF-8'); echo $html; }
    }

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->renderSectionPage($section, $payload, $inline);
        if ($outputPath) { file_put_contents($outputPath, $html); }
        else             { header('Content-Type: text/html; charset=UTF-8'); echo $html; }
    }

    // =========================================================================
    // Full page
    // =========================================================================

    private function renderFullPage(array $payload): string
    {
        $patients   = $payload['patients']       ?? [];
        $bySite     = $payload['by_site']        ?? [];
        $byArm      = $payload['by_arm']         ?? [];
        $bySiteSex  = $payload['by_site_sex']    ?? [];
        $trajectory = $payload['trajectory']     ?? [];
        $regain     = $payload['regain_by_site'] ?? [];
        $siteLabels = $payload['site_labels']    ?? [];
        $period     = $payload['period']         ?? [];

        $periodLabel = $this->periodLabel($period);
        $siteOrder   = $this->siteOrder($bySite, $siteLabels);

        [$cssTag, $jsTag] = $this->buildAssetTags();

        // Build JS globals
        $siteCodes  = $siteOrder;
        $siteLabArr = array_map(fn($c) => $siteLabels[$c] ?? $c, $siteOrder);

        // Remap trajectory keys from site codes to display labels
        $trajJs = [];
        foreach ($trajectory as $day => $siteWts) {
            foreach ($siteWts as $code => $mean) {
                $lbl = ($code === 'All') ? 'All' : ($siteLabels[$code] ?? $code);
                $trajJs[$day][$lbl] = $mean;
            }
        }

        // Velocity box per site
        $velBox = [];
        foreach ($siteOrder as $code) {
            $s = $bySite[$code] ?? [];
            $velBox[] = [
                'label'  => $siteLabels[$code] ?? $code,
                'min'    => $s['min_velocity']    ?? null,
                'median' => $s['median_velocity'] ?? null,
                'max'    => $s['max_velocity']    ?? null,
                'mean'   => $s['mean_velocity']   ?? null,
                'std'    => $s['std_velocity']    ?? null,
            ];
        }

        // Velocity histogram patient list
        $velHist = [];
        foreach ($patients as $p) {
            if ($p['velocity'] !== null) {
                $velHist[] = ['velocity' => $p['velocity'], 'arm' => $p['study_arm']];
            }
        }

        // Regain chart rows
        $regainJs = [];
        foreach ($siteOrder as $code) {
            $r = $regain[$code] ?? null;
            if ($r) $regainJs[] = array_merge(['site' => $siteLabels[$code] ?? $code], $r);
        }
        if (isset($regain['Total'])) {
            $regainJs[] = array_merge(['site' => 'Total'], $regain['Total']);
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Weight Analysis &mdash; Emollient</title>
<?= $cssTag ?>
</head>
<body>
<div class="report-container">

  <?= $this->toolbar ?>

  <div class="report-header">
    <div class="report-title">Weight Analysis &mdash; Emollient Trial</div>
    <div class="report-period"><?= htmlspecialchars($periodLabel) ?></div>
  </div>

  <div class="wt-section-block" id="section-summary">
    <div class="wt-section-header"><div class="wt-section-title">Summary</div></div>
    <?= $this->renderSummaryCards($bySite['Total'] ?? []) ?>
  </div>

  <div class="wt-section-block" id="section-by-site">
    <div class="wt-section-header"><div class="wt-section-title">Hospital-wise Summary</div></div>
    <?= $this->renderSiteTable($bySite, $siteOrder, $siteLabels) ?>
  </div>

  <div class="wt-section-block" id="section-by-arm">
    <div class="wt-section-header"><div class="wt-section-title">Study Arm Comparison</div></div>
    <?= $this->renderArmTable($byArm) ?>
  </div>

  <div class="wt-section-block" id="section-by-sex">
    <div class="wt-section-header"><div class="wt-section-title">Site &times; Sex Breakdown</div></div>
    <?= $this->renderSiteSexTable($bySiteSex, $siteOrder, $siteLabels) ?>
  </div>

  <div class="wt-section-block" id="section-trajectory">
    <div class="wt-section-header"><div class="wt-section-title">Mean Weight Trajectory</div></div>
    <div class="chart-wrap-tall"><canvas id="chartTrajectory"></canvas></div>
    <p class="wt-footnote">Mean weight in grams per site. Day 0&nbsp;=&nbsp;admission &middot; Days 1&ndash;28&nbsp;=&nbsp;daily weights &middot; Dis&nbsp;=&nbsp;discharge. Dashed line&nbsp;=&nbsp;all-sites mean.</p>
    <div class="traj-legend">
      <?php foreach ($siteOrder as $i => $code): ?>
      <span>
        <span class="traj-legend-dot" style="background:<?= self::SITE_COLORS[$i % 8] ?>"></span>
        <?= htmlspecialchars($siteLabels[$code] ?? $code) ?>
      </span>
      <?php endforeach; ?>
      <span><span class="traj-legend-dot" style="background:#1a2c3d;opacity:.5"></span>All (mean)</span>
    </div>
  </div>

  <div class="wt-section-block" id="section-velocity">
    <div class="wt-section-header">
      <div class="wt-section-title">
        Growth Velocity (g/kg/day)
        <span class="vel-ref">Healthy preterm range: 15&ndash;20 g/kg/day</span>
      </div>
    </div>
    <div class="chart-wrap-pair">
      <div><canvas id="chartVelocityBox"></canvas></div>
      <div><canvas id="chartVelocityHist"></canvas></div>
    </div>
    <p class="wt-footnote">Left: spread per site &mdash; mean&thinsp;&plusmn;&thinsp;1&thinsp;SD box, min/max range, median line. Green shading&nbsp;=&nbsp;healthy range 15&ndash;20&thinsp;g/kg/day. Right: distribution by study arm.</p>
  </div>

  <div class="wt-section-block" id="section-regain">
    <div class="wt-section-header"><div class="wt-section-title">Birth Weight Regain by Site</div></div>
    <div class="chart-wrap-mid"><canvas id="chartRegain"></canvas></div>
    <p class="wt-footnote">Cumulative percentage of babies (with known birth weight) who first regained birth weight by each threshold. Values shown inside segments &ge;&thinsp;5%.</p>
  </div>

  <div class="wt-section-block" id="section-patients">
    <div class="wt-section-header"><div class="wt-section-title">Per-Patient Detail</div></div>
    <?= $this->renderFilterBar($siteOrder, $siteLabels) ?>
    <?= $this->renderPatientTable($patients, $siteLabels) ?>
  </div>

</div>

<script>
window.WT_SITE_CODES  = <?= json_encode($siteCodes, JSON_UNESCAPED_UNICODE) ?>;
window.WT_SITE_LABELS = <?= json_encode($siteLabArr, JSON_UNESCAPED_UNICODE) ?>;
window.WT_TRAJECTORY  = <?= json_encode($trajJs) ?>;
window.WT_VEL_BOX     = <?= json_encode($velBox) ?>;
window.WT_VEL_HIST    = <?= json_encode($velHist) ?>;
window.WT_REGAIN      = <?= json_encode($regainJs) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>if (window.ChartDataLabels) Chart.register(ChartDataLabels);</script>
<?= $jsTag ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Section page
    // =========================================================================

    private function renderSectionPage(string $section, array $payload, bool $inline): string
    {
        $patients   = $payload['patients']    ?? [];
        $bySite     = $payload['by_site']     ?? [];
        $byArm      = $payload['by_arm']      ?? [];
        $bySiteSex  = $payload['by_site_sex'] ?? [];
        $siteLabels = $payload['site_labels'] ?? [];
        $siteOrder  = $this->siteOrder($bySite, $siteLabels);

        $reportCss = $this->readFile(self::PATH_CSS_BASE) . "\n" . $this->readFile(self::PATH_CSS_WT);

        if ($inline) {
            $uiCssTag = '<style>' . $this->readFile(self::PATH_CSS_SEC) . '</style>';
            $uiJsTag  = '<script>' . $this->readFile(self::PATH_JS_SEC) . '</script>';
        } else {
            $uiCssTag = "<link rel='stylesheet' href='/assets/css/section-export.css'>";
            $uiJsTag  = "<script src='/assets/js/section-export.js'></script>";
        }

        $title = match ($section) {
            'summary'  => 'Weight Summary',
            'by-site'  => 'Weight — Hospital Summary',
            'by-arm'   => 'Weight — Arm Comparison',
            'by-sex'   => 'Weight — Site x Sex',
            'patients' => 'Weight — Per-Patient Detail',
            default    => 'Weight Analysis',
        };

        $content = match ($section) {
            'summary'  => $this->renderSummaryCards($bySite['Total'] ?? []),
            'by-site'  => $this->renderSiteTable($bySite, $siteOrder, $siteLabels),
            'by-arm'   => $this->renderArmTable($byArm),
            'by-sex'   => $this->renderSiteSexTable($bySiteSex, $siteOrder, $siteLabels),
            'patients' => $this->renderFilterBar($siteOrder, $siteLabels)
                        . $this->renderPatientTable($patients, $siteLabels),
            default    => '<p>Chart sections must be exported from the full HTML view.</p>',
        };

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Emollient &mdash; <?= htmlspecialchars($title) ?></title>
<style><?= $reportCss ?></style>
<?= $uiCssTag ?>
</head>
<body>
<div id="export-toolbar">
  <span class="section-title"><?= htmlspecialchars($title) ?></span>
  <span class="sep">|</span>
  <div class="colour-row">
    <label>Hdr <input type="color" id="hdr-color" value="#2e4057"></label>
    <label>Rows <input type="color" id="sec-color" value="#f0f4f8"></label>
  </div>
  <span class="sep">|</span>
  <button class="exp-btn" onclick="window.print()">&#128438; Print</button>
  <button class="exp-btn" onclick="copyImage()">&#128247; Copy image</button>
  <button class="exp-btn" onclick="downloadHtml()">&#8659; HTML</button>
  <button class="exp-btn" onclick="copyTable()">&#128203; Copy table</button>
</div>
<div id="page-body">
  <div id="section-wrap"><?= $content ?></div>
</div>
<script src="/assets/js/html2canvas.min.js"></script>
<script>
window.SECTION_HAS_COLUMNS = false;
window.SECTION_COL_DEFS    = [];
window.SECTION_NAME        = <?= json_encode($section) ?>;
</script>
<?= $uiJsTag ?>
<?php if ($section === 'patients'): ?>
<script src="/assets/js/weight_report.js"></script>
<?php endif; ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Sub-renderers
    // =========================================================================

    private function renderSummaryCards(array $t): string
    {
        ob_start(); ?>
<div class="wt-cards">
  <div class="wt-card">
    <div class="wt-card-label">Patients</div>
    <div class="wt-card-value"><?= $t['n'] ?? '&mdash;' ?></div>
    <div class="wt-card-sub">with weight data</div>
  </div>
  <div class="wt-card accent-blue">
    <div class="wt-card-label">Mean birth weight</div>
    <div class="wt-card-value"><?= $t['mean_birth_wt'] !== null ? $t['mean_birth_wt'].' g' : '&mdash;' ?></div>
  </div>
  <div class="wt-card accent-blue">
    <div class="wt-card-label">Mean admission weight</div>
    <div class="wt-card-value"><?= $t['mean_admission_wt'] !== null ? $t['mean_admission_wt'].' g' : '&mdash;' ?></div>
  </div>
  <div class="wt-card accent-blue">
    <div class="wt-card-label">Mean discharge weight</div>
    <div class="wt-card-value"><?= $t['mean_discharge_wt'] !== null ? $t['mean_discharge_wt'].' g' : '&mdash;' ?></div>
  </div>
  <div class="wt-card accent-green">
    <div class="wt-card-label">Mean weight gain</div>
    <div class="wt-card-value"><?= $t['mean_gain_g'] !== null ? $t['mean_gain_g'].' g' : '&mdash;' ?></div>
    <div class="wt-card-sub"><?= $t['pct_positive_gain'] !== null ? $t['pct_positive_gain'].'% gained weight' : '' ?></div>
  </div>
  <div class="wt-card accent-amber">
    <div class="wt-card-label">Mean growth velocity</div>
    <div class="wt-card-value"><?= $t['mean_velocity'] !== null ? $t['mean_velocity'].' g/kg/d' : '&mdash;' ?></div>
    <div class="wt-card-sub">Healthy preterm: 15&ndash;20 g/kg/day</div>
  </div>
  <div class="wt-card">
    <div class="wt-card-label">Regained birth wt by Day 28</div>
    <div class="wt-card-value"><?= $t['pct_regained_by_28'] !== null ? $t['pct_regained_by_28'].'%' : '&mdash;' ?></div>
    <div class="wt-card-sub">N=<?= $t['n_regain_bw'] ?? '&mdash;' ?> with known birth wt</div>
  </div>
</div>
        <?php return ob_get_clean();
    }

    private function velBadge(?float $vel): string
    {
        if ($vel === null) return '&mdash;';
        if ($vel >= 15 && $vel <= 20) return "<span class='vel-badge vel-normal' style='color:#1a5ea8'>{$vel}</span>";
        if ($vel > 20)                return "<span class='vel-badge vel-high'   style='color:#2e7d52'>{$vel}</span>";
        return                               "<span class='vel-badge vel-low'    style='color:#c2185b'>{$vel}</span>";
    }

    private function renderSiteTable(array $bySite, array $siteOrder, array $siteLabels): string
    {
        $rows = array_merge($siteOrder, ['Total']);
        ob_start(); ?>
<div style="overflow-x:auto">
<table class="wt-table report-table">
<thead>
<tr>
  <th>Hospital</th><th class="num">N</th>
  <th class="num">Birth wt<br><span style="font-size:10px;font-weight:400">mean g</span></th>
  <th class="num">Adm wt<br><span style="font-size:10px;font-weight:400">mean g</span></th>
  <th class="num">Dis wt<br><span style="font-size:10px;font-weight:400">mean g</span></th>
  <th class="num">Gain<br><span style="font-size:10px;font-weight:400">mean g</span></th>
  <th class="num">% gain</th>
  <th class="num">Velocity<br><span style="font-size:10px;font-weight:400">mean</span></th>
  <th class="num">Velocity<br><span style="font-size:10px;font-weight:400">median</span></th>
  <th class="num">Velocity<br><span style="font-size:10px;font-weight:400">SD</span></th>
  <th class="num">Regained BW<br><span style="font-size:10px;font-weight:400">% by D28</span></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $code):
    $s = $bySite[$code] ?? []; $isT = ($code === 'Total');
    $lbl = $isT ? '<strong>Total</strong>' : htmlspecialchars($siteLabels[$code] ?? $code);
    if ($isT) echo '<tfoot>'; ?>
<tr>
  <td class="site-col"><?= $lbl ?></td>
  <td class="num"><?= $s['n'] ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_birth_wt']     ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_admission_wt']  ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_discharge_wt']  ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_gain_g']        ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_pct_change'] !== null ? $s['mean_pct_change'].'%' : '&mdash;' ?></td>
  <td class="num"><?= $this->velBadge($s['mean_velocity']   ?? null) ?></td>
  <td class="num"><?= $s['median_velocity']    ?? '&mdash;' ?></td>
  <td class="num"><?= $s['std_velocity']       ?? '&mdash;' ?></td>
  <td class="num"><?= $s['pct_regained_by_28'] !== null ? $s['pct_regained_by_28'].'%' : '&mdash;' ?></td>
</tr>
<?php if ($isT) echo '</tfoot>'; endforeach; ?>
</tbody>
</table>
</div>
<p class="wt-footnote">
  Velocity badge:
  <span class="vel-badge vel-high"   style="color:#2e7d52">&gt;&thinsp;20</span> above target &ensp;
  <span class="vel-badge vel-normal" style="color:#1a5ea8">15&ndash;20</span> healthy range &ensp;
  <span class="vel-badge vel-low"    style="color:#c2185b">&lt;&thinsp;15</span> below target &ensp; (g/kg/day)
</p>
        <?php return ob_get_clean();
    }

    private function renderArmTable(array $byArm): string
    {
        $arms = array_filter(array_keys($byArm), fn($k) => $k !== 'Total');
        $rows = array_merge(array_values($arms), ['Total']);
        ob_start(); ?>
<div style="overflow-x:auto">
<table class="wt-table report-table">
<thead>
<tr>
  <th>Study arm</th><th class="num">N</th>
  <th class="num">Mean birth wt (g)</th>
  <th class="num">Mean adm wt (g)</th>
  <th class="num">Mean dis wt (g)</th>
  <th class="num">Mean gain (g)</th>
  <th class="num">% +ve gain</th>
  <th class="num">Mean velocity</th>
  <th class="num">Median velocity</th>
  <th class="num">% regained BW D28</th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $arm):
    $s = $byArm[$arm] ?? []; $isT = ($arm === 'Total');
    if ($isT) echo '<tfoot>'; ?>
<tr>
  <td class="site-col"><?= $isT ? '<strong>Total</strong>' : htmlspecialchars($arm) ?></td>
  <td class="num"><?= $s['n']                ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_birth_wt']    ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_admission_wt']?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_discharge_wt']?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_gain_g']      ?? '&mdash;' ?></td>
  <td class="num"><?= $s['pct_positive_gain'] !== null ? $s['pct_positive_gain'].'%' : '&mdash;' ?></td>
  <td class="num"><?= $this->velBadge($s['mean_velocity'] ?? null) ?></td>
  <td class="num"><?= $s['median_velocity']  ?? '&mdash;' ?></td>
  <td class="num"><?= $s['pct_regained_by_28'] !== null ? $s['pct_regained_by_28'].'%' : '&mdash;' ?></td>
</tr>
<?php if ($isT) echo '</tfoot>'; endforeach; ?>
</tbody>
</table>
</div>
        <?php return ob_get_clean();
    }

    private function renderSiteSexTable(array $bySiteSex, array $siteOrder, array $siteLabels): string
    {
        $sites = array_merge($siteOrder, ['Total']);
        ob_start(); ?>
<div style="overflow-x:auto">
<table class="wt-table report-table">
<thead>
<tr>
  <th>Hospital / Sex</th><th class="num">N</th>
  <th class="num">Mean adm wt (g)</th>
  <th class="num">Mean dis wt (g)</th>
  <th class="num">Mean gain (g)</th>
  <th class="num">Mean velocity</th>
  <th class="num">% regained BW D28</th>
</tr>
</thead>
<tbody>
<?php foreach ($sites as $code):
    $sexMap = $bySiteSex[$code] ?? []; $isT = ($code === 'Total');
    $lbl = $isT ? '<strong>Total</strong>' : htmlspecialchars($siteLabels[$code] ?? $code);
    if ($isT) echo '<tfoot>'; ?>
<tr><td class="site-col" colspan="7"><?= $lbl ?></td></tr>
<?php foreach (['M' => 'Male', 'F' => 'Female'] as $sc => $sl):
    $s = $sexMap[$sc] ?? null; if (!$s) continue; ?>
<tr class="sex-row">
  <td>&ensp;<?= $sl ?></td>
  <td class="num"><?= $s['n']                ?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_admission_wt']?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_discharge_wt']?? '&mdash;' ?></td>
  <td class="num"><?= $s['mean_gain_g']      ?? '&mdash;' ?></td>
  <td class="num"><?= $this->velBadge($s['mean_velocity'] ?? null) ?></td>
  <td class="num"><?= $s['pct_regained_by_28'] !== null ? $s['pct_regained_by_28'].'%' : '&mdash;' ?></td>
</tr>
<?php endforeach; if ($isT) echo '</tfoot>'; endforeach; ?>
</tbody>
</table>
</div>
        <?php return ob_get_clean();
    }

    private function renderFilterBar(array $siteOrder, array $siteLabels): string
    {
        ob_start(); ?>
<div class="wt-filter-row">
  <label>Site
    <select id="wtFilterSite" onchange="applyWtFilters()">
      <option value="">All sites</option>
      <?php foreach ($siteOrder as $code): ?>
      <option value="<?= htmlspecialchars(strtolower($code)) ?>"><?= htmlspecialchars($siteLabels[$code] ?? $code) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Arm
    <select id="wtFilterArm" onchange="applyWtFilters()">
      <option value="">All</option><option>Intervention</option><option>Control</option>
    </select>
  </label>
  <label>Sex
    <select id="wtFilterSex" onchange="applyWtFilters()">
      <option value="">All</option>
      <option value="m">Male</option>
      <option value="f">Female</option>
    </select>
  </label>
  <label>Search ID <input type="text" id="wtFilterSearch" placeholder="e.g. REG-001" oninput="applyWtFilters()"></label>
  <span id="wtFilterCount"></span>
</div>
        <?php return ob_get_clean();
    }

    private function renderPatientTable(array $patients, array $siteLabels): string
    {
        ob_start(); ?>
<div style="overflow-x:auto">
<table class="wt-table report-table">
<thead>
<tr>
  <th onclick="sortWtTable(0)">Record ID</th>
  <th onclick="sortWtTable(1)">Site</th>
  <th onclick="sortWtTable(2)">Arm</th>
  <th onclick="sortWtTable(3)">Sex</th>
  <th class="num" onclick="sortWtTable(4)">Birth wt (g)</th>
  <th class="num" onclick="sortWtTable(5)">Adm wt (g)</th>
  <th class="num" onclick="sortWtTable(6)">Dis wt (g)</th>
  <th class="num" onclick="sortWtTable(7)">Gain (g)</th>
  <th class="num" onclick="sortWtTable(8)">% change</th>
  <th class="num" onclick="sortWtTable(9)">Velocity<br><span style="font-size:10px;font-weight:400">g/kg/d</span></th>
  <th class="num" onclick="sortWtTable(10)">Days to regain BW</th>
</tr>
</thead>
<tbody id="wtPatientBody">
<?php foreach ($patients as $id => $p):
    $site = $p['site'] ?? ''; $arm = $p['study_arm'] ?? ''; $sex = $p['sex'] ?? '';
    $dtr = $p['days_to_regain_bw'];
    if ($dtr === null)     $dtrCell = '&mdash;';
    elseif ($dtr <= 7)     $dtrCell = "<span class='reg-badge reg-7' style='color:#2e7d52'>Day {$dtr}</span>";
    elseif ($dtr <= 14)    $dtrCell = "<span class='reg-badge reg-14' style='color:#1a5ea8'>Day {$dtr}</span>";
    elseif ($dtr <= 21)    $dtrCell = "<span class='reg-badge reg-21' style='color:#e65100'>Day {$dtr}</span>";
    else                   $dtrCell = "<span class='reg-badge reg-28' style='color:#c2185b'>Day {$dtr}</span>";
?>
<tr data-site="<?= htmlspecialchars(strtolower($site)) ?>"
    data-arm="<?= htmlspecialchars(strtolower($arm)) ?>"
    data-sex="<?= htmlspecialchars(strtolower($sex)) ?>"
    data-id="<?= htmlspecialchars(strtolower((string)$id)) ?>">
  <td><?= htmlspecialchars((string)$id) ?></td>
  <td><?= htmlspecialchars($siteLabels[$site] ?? $site) ?></td>
  <td><?= htmlspecialchars($arm) ?></td>
  <td><?= $sex === 'M' ? 'Male' : ($sex === 'F' ? 'Female' : htmlspecialchars($sex)) ?></td>
  <td class="num"><?= $p['birth_wt']     !== null ? $p['birth_wt']     : '&mdash;' ?></td>
  <td class="num"><?= $p['admission_wt'] !== null ? $p['admission_wt'] : '&mdash;' ?></td>
  <td class="num"><?= $p['discharge_wt'] !== null ? $p['discharge_wt'] : '&mdash;' ?></td>
  <td class="num"><?= $p['total_gain_g'] !== null ? $p['total_gain_g'] : '&mdash;' ?></td>
  <td class="num"><?= $p['pct_change']   !== null ? $p['pct_change'].'%' : '&mdash;' ?></td>
  <td class="num"><?= $this->velBadge($p['velocity']) ?></td>
  <td class="num"><?= $dtrCell ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
        <?php return ob_get_clean();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildAssetTags(): array
    {
        if ($this->inline) {
            $css = $this->readFile(self::PATH_CSS_BASE) . "\n" . $this->readFile(self::PATH_CSS_WT);
            $js  = $this->readFile(self::PATH_JS_WT);
            return ["<style>\n{$css}\n</style>", "<script>\n{$js}\n</script>"];
        }
        return [
            "<link rel='stylesheet' href='{$this->cssPath}'>\n<link rel='stylesheet' href='/assets/css/weight_report.css'>",
            "<script src='/assets/js/weight_report.js'></script>",
        ];
    }

    private function siteOrder(array $bySite, array $siteLabels): array
    {
        $all = array_filter(array_keys($bySite), fn($k) => $k !== 'Total');
        $ordered = [];
        foreach (array_keys($siteLabels) as $code) {
            if (in_array($code, $all, true)) $ordered[] = $code;
        }
        foreach ($all as $code) {
            if (!in_array($code, $ordered, true)) $ordered[] = $code;
        }
        return $ordered;
    }

    private function periodLabel(array $period): string
    {
        $from = $period['date_from'] ?? null; $to = $period['date_to'] ?? null;
        if ($from && $to)  return "Period: {$from} to {$to}";
        if ($to)           return "Period: up to {$to}";
        if ($from)         return "Period: from {$from}";
        return 'Period: All dates';
    }

    private function readFile(string $rel): string
    {
        $abs = realpath(__DIR__ . $rel);
        return ($abs && file_exists($abs)) ? file_get_contents($abs) : '';
    }
}

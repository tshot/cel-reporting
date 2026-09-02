<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * LengthOfStayHtmlExporter
 *
 * Renders the Length of Stay report as a 7-section interactive HTML page:
 *
 *   Section 1 — Summary cards        (headline KPIs)
 *   Section 2 — Site summary table   (hospital-wise stats, two stat sets)
 *   Section 3 — Arm summary table    (Intervention / Control stats)
 *   Section 4 — Stacked bar chart    (LOS bands by site)
 *   Section 5 — Box plot             (LOS spread per site)
 *   Section 6 — LOS histogram        (frequency distribution)
 *   Section 7 — Per-patient table    (all babies, sortable, filterable)
 *
 * External assets used:
 *   CSS  /assets/css/emollient_eligible.css  — shared report base styles
 *        /assets/css/los_report.css          — LOS-specific styles
 *   JS   /assets/js/los_report.js            — charts, filter, sort
 *        /assets/js/html2canvas.min.js       — section PNG export
 *        /assets/js/section-export.js        — section toolbar
 *
 * When $inline = true (CLI / download), all CSS and JS are inlined so the
 * file is self-contained. Chart.js is always loaded from CDN (too large to
 * inline). Pattern matches EligibilityHtmlExporter exactly.
 *
 * Two stat sets are shown throughout:
 *   Blue  = including Day-28 cases (LOS counted as 28)
 *   Green = excluding Day-28 cases (discharged babies only)
 */
class LengthOfStayHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    // Paths relative to __DIR__ = projects/Emollient/Export/
    private const PATH_CSS_BASE    = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';
    private const PATH_CSS_LOS     = '/../../../reporting-engine/public/assets/css/los_report.css';
    private const PATH_JS_LOS      = '/../../../reporting-engine/public/assets/js/los_report.js';
    private const PATH_JS_SECTION  = '/../../../reporting-engine/public/assets/js/section-export.js';
    private const PATH_CSS_SECTION = '/../../../reporting-engine/public/assets/css/section-export.css';

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '/assets/css/emollient_eligible.css',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->cssPath = $cssPath;
        $this->toolbar = $toolbar;
    }

    // =========================================================================
    // ExporterInterface
    // =========================================================================

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->renderFullPage($payload);

        if ($outputPath) {
            file_put_contents($outputPath, $html);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->renderSectionPage($section, $payload, $inline);

        if ($outputPath) {
            file_put_contents($outputPath, $html);
        } else {
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }

    // =========================================================================
    // Full-page render
    // =========================================================================

    private function renderFullPage(array $payload): string
    {
        $patients     = $payload['patients']     ?? [];
        $bySite       = $payload['by_site']      ?? [];
        $byArm        = $payload['by_arm']       ?? [];
        $distribution = $payload['distribution'] ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $period       = $payload['period']       ?? [];

        $periodLabel = $this->periodLabel($period);
        $siteOrder   = $this->siteOrder($bySite, $siteLabels);

        // ── Build Chart.js data (PHP → JSON globals) ──────────────────────
        $chartSiteLabels = [];
        $chartDist       = [];
        $chartBoxData    = [];
        $histBuckets     = array_fill(0, 28, 0);   // index 0 = day 1

        foreach ($siteOrder as $code) {
            $label             = $siteLabels[$code] ?? $code;
            $chartSiteLabels[] = $label;
            $chartDist[]       = $distribution[$code]
                                 ?? ['lt7' => 0, 'w7_14' => 0, 'w15_27' => 0, 'day28' => 0];
            $s                 = $bySite[$code] ?? [];
            $chartBoxData[]    = [
                'label'  => $label,
                'min'    => $s['min_excl']    ?? null,
                'median' => $s['median_excl'] ?? null,
                'max'    => $s['max_excl']    ?? null,
                'mean'   => $s['mean_excl']   ?? null,
                'std'    => $s['std_excl']    ?? null,
            ];
        }

        foreach ($patients as $p) {
            if ($p['los_days'] !== null && $p['in_hosp_day28'] !== true) {
                $idx              = max(0, min(27, (int)$p['los_days'] - 1));
                $histBuckets[$idx]++;
            }
        }

        // ── CSS / JS tags ──────────────────────────────────────────────────
        [$cssTag, $losJsTag] = $this->buildAssetTags();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Length of Stay &mdash; Emollient</title>
<?= $cssTag ?>
</head>
<body>

<div class="report-container">

  <?= $this->toolbar ?>

  <div class="report-header">
    <div class="report-title">Length of Stay &mdash; Emollient Trial</div>
    <div class="report-period"><?= htmlspecialchars($periodLabel) ?></div>
  </div>

  <!-- ── Section 1: Summary cards ─────────────────────────────────────── -->
  <div class="section-block" id="section-summary">
    <div class="section-header">
      <div class="section-title">Summary</div>
    </div>
    <?= $this->renderSummaryCards($bySite) ?>
  </div>

  <!-- ── Section 2: Site summary table ───────────────────────────────── -->
  <div class="section-block" id="section-by-site">
    <div class="section-header">
      <div class="section-title">Hospital-wise Summary</div>
    </div>
    <?= $this->renderSiteTable($bySite, $siteOrder, $siteLabels) ?>
  </div>

  <!-- ── Section 3: Arm summary table ────────────────────────────────── -->
  <div class="section-block" id="section-by-arm">
    <div class="section-header">
      <div class="section-title">Study Arm Summary</div>
    </div>
    <?= $this->renderArmTable($byArm) ?>
  </div>

  <!-- ── Section 4: Stacked bar chart ────────────────────────────────── -->
  <div class="section-block" id="section-stacked-bar">
    <div class="section-header">
      <div class="section-title">LOS Distribution by Site</div>
    </div>
    <div class="chart-wrap"><canvas id="chartStackedBar"></canvas></div>
    <div class="chart-legend">
      <span><span class="chart-legend-dot" style="background:#4ade80"></span>&lt;&nbsp;7&nbsp;days</span>
      <span><span class="chart-legend-dot" style="background:#60a5fa"></span>7&ndash;14&nbsp;days</span>
      <span><span class="chart-legend-dot" style="background:#fb923c"></span>15&ndash;27&nbsp;days</span>
      <span><span class="chart-legend-dot" style="background:#f87171"></span>Day&nbsp;28&nbsp;(still in hospital)</span>
    </div>
  </div>

  <!-- ── Section 5: Box plot ──────────────────────────────────────────── -->
  <div class="section-block" id="section-box-plot">
    <div class="section-header">
      <div class="section-title">LOS Spread by Site</div>
    </div>
    <div class="chart-wrap"><canvas id="chartBoxPlot"></canvas></div>
    <p class="chart-footnote">Median line &middot; Mean &plusmn; 1&thinsp;SD box &middot; min/max range. Excludes Day-28 cases.</p>
  </div>

  <!-- ── Section 6: Histogram ─────────────────────────────────────────── -->
  <div class="section-block" id="section-histogram">
    <div class="section-header">
      <div class="section-title">LOS Frequency Distribution</div>
    </div>
    <div class="chart-wrap"><canvas id="chartHistogram"></canvas></div>
    <p class="chart-footnote">All discharged patients (excludes Day-28 cases). Each bar = one day.</p>
  </div>

  <!-- ── Section 7: Per-patient table ─────────────────────────────────── -->
  <div class="section-block" id="section-patients">
    <div class="section-header">
      <div class="section-title">Per-Patient Detail</div>
    </div>
    <?= $this->renderFilterBar($siteOrder, $siteLabels) ?>
    <?= $this->renderPatientTable($patients, $siteLabels) ?>
  </div>

</div><!-- /report-container -->

<!-- Chart.js data globals — always inline (dynamic per request) -->
<script>
window.LOS_SITE_LABELS  = <?= json_encode($chartSiteLabels, JSON_UNESCAPED_UNICODE) ?>;
window.LOS_CHART_DIST   = <?= json_encode($chartDist) ?>;
window.LOS_CHART_BOX    = <?= json_encode($chartBoxData) ?>;
window.LOS_HIST_BUCKETS = <?= json_encode(array_values($histBuckets)) ?>;
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<?= $losJsTag ?>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Section export page
    // =========================================================================

    private function renderSectionPage(string $section, array $payload, bool $inline): string
    {
        $patients   = $payload['patients']    ?? [];
        $bySite     = $payload['by_site']     ?? [];
        $byArm      = $payload['by_arm']      ?? [];
        $siteLabels = $payload['site_labels'] ?? [];
        $siteOrder  = $this->siteOrder($bySite, $siteLabels);

        // Report CSS — always inlined in section pages (needed for table styles)
        $reportCss = $this->readFile(self::PATH_CSS_BASE) . "\n"
                   . $this->readFile(self::PATH_CSS_LOS);

        // UI CSS / JS — inline for download, external for browser view
        if ($inline) {
            $uiCssTag = '<style>' . $this->readFile(self::PATH_CSS_SECTION) . '</style>';
            $uiJsTag  = '<script>' . $this->readFile(self::PATH_JS_SECTION) . '</script>';
        } else {
            $uiCssTag = "<link rel='stylesheet' href='/assets/css/section-export.css'>";
            $uiJsTag  = "<script src='/assets/js/section-export.js'></script>";
        }

        $title = match ($section) {
            'summary'  => 'LOS Summary',
            'by-site'  => 'LOS — Hospital Summary',
            'by-arm'   => 'LOS — Study Arm Summary',
            'patients' => 'LOS — Per-Patient Detail',
            default    => 'Length of Stay',
        };

        $hasColumns = in_array($section, ['by-site', 'by-arm', 'patients'], true);

        $content = match ($section) {
            'summary'  => $this->renderSummaryCards($bySite),
            'by-site'  => $this->renderSiteTable($bySite, $siteOrder, $siteLabels),
            'by-arm'   => $this->renderArmTable($byArm),
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
    <label title="Table header background">Hdr <input type="color" id="hdr-color" value="#2e4057"></label>
    <label title="Row background">Rows <input type="color" id="sec-color" value="#f0f4f8"></label>
  </div>
  <span class="sep">|</span>
  <button class="exp-btn" onclick="window.print()">&#128438; Print / PDF</button>
  <button class="exp-btn" onclick="copyImage()">&#128247; Copy image</button>
  <button class="exp-btn" onclick="downloadHtml()">&#8659; HTML</button>
  <button class="exp-btn" onclick="copyTable()">&#128203; Copy table</button>
</div>

<div id="page-body">
  <div id="section-wrap">
    <?= $content ?>
  </div>
</div>

<script src="/assets/js/html2canvas.min.js"></script>
<script>
window.SECTION_HAS_COLUMNS = <?= $hasColumns ? 'true' : 'false' ?>;
window.SECTION_COL_DEFS    = [];
window.SECTION_NAME        = <?= json_encode($section) ?>;
</script>
<?= $uiJsTag ?>
<?php if ($section === 'patients'): ?>
<script src="/assets/js/los_report.js"></script>
<?php endif; ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Sub-renderers
    // =========================================================================

    private function renderSummaryCards(array $bySite): string
    {
        $t = $bySite['Total'] ?? [];
        ob_start();
        ?>
<div class="los-cards">
  <div class="los-card">
    <div class="los-card-label">Patients with LOS data</div>
    <div class="los-card-value"><?= $t['count'] ?? '&mdash;' ?></div>
    <div class="los-card-sub"><?= (int)($t['count_missing'] ?? 0) ?> missing discharge data</div>
  </div>
  <div class="los-card accent">
    <div class="los-card-label">Mean LOS (incl. Day&nbsp;28)</div>
    <div class="los-card-value"><?= $t['mean_incl'] !== null ? $t['mean_incl'] . ' d' : '&mdash;' ?></div>
    <div class="los-card-sub">all enrolled babies</div>
  </div>
  <div class="los-card accent">
    <div class="los-card-label">Median LOS (incl. Day&nbsp;28)</div>
    <div class="los-card-value"><?= $t['median_incl'] !== null ? $t['median_incl'] . ' d' : '&mdash;' ?></div>
    <div class="los-card-sub">all enrolled babies</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">Mean LOS (discharged)</div>
    <div class="los-card-value"><?= $t['mean_excl'] !== null ? $t['mean_excl'] . ' d' : '&mdash;' ?></div>
    <div class="los-card-sub">excl. Day-28 cases</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">Median LOS (discharged)</div>
    <div class="los-card-value"><?= $t['median_excl'] !== null ? $t['median_excl'] . ' d' : '&mdash;' ?></div>
    <div class="los-card-sub">excl. Day-28 cases</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">Still in hospital (Day&nbsp;28)</div>
    <div class="los-card-value"><?= $t['count_day28'] ?? '&mdash;' ?></div>
    <div class="los-card-sub"><?= $t['pct_day28'] !== null ? $t['pct_day28'] . '% of total' : '' ?></div>
  </div>
</div>
        <?php
        return ob_get_clean();
    }

    private function renderSiteTable(array $bySite, array $siteOrder, array $siteLabels): string
    {
        $rows = array_merge($siteOrder, ['Total']);
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="los-table report-table">
<thead>
<tr>
  <th>Hospital</th>
  <th class="num">N</th>
  <th class="num">Day&nbsp;28<br><span class="stat-note">N&thinsp;(%)</span></th>
  <th class="num">Missing</th>
  <th class="num">Mean<br><span class="stat-note">incl&thinsp;/&thinsp;excl</span></th>
  <th class="num">Median<br><span class="stat-note">incl&thinsp;/&thinsp;excl</span></th>
  <th class="num">SD<br><span class="stat-note">incl&thinsp;/&thinsp;excl</span></th>
  <th class="num">Min&ndash;Max<br><span class="stat-note">excl Day-28</span></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $code):
    $s       = $bySite[$code] ?? [];
    $isTotal = ($code === 'Total');
    $label   = $isTotal
        ? '<strong>Total</strong>'
        : htmlspecialchars($siteLabels[$code] ?? $code);
    if ($isTotal) echo '<tfoot>';
?>
<tr>
  <td class="site-col"><?= $label ?></td>
  <td class="num"><?= $s['count'] ?? '&mdash;' ?></td>
  <td class="num">
    <?= $s['count_day28'] ?? '&mdash;' ?>
    <?php if (($s['pct_day28'] ?? null) !== null): ?>
      <span class="sub-val"><?= $s['pct_day28'] ?>%</span>
    <?php endif; ?>
  </td>
  <td class="num"><?= $s['count_missing'] ?? '&mdash;' ?></td>
  <td class="num">
    <span class="incl-val"><?= $s['mean_incl'] ?? '&mdash;' ?></span>
    <span class="excl-val" style="color:#2e7d52"><?= $s['mean_excl'] ?? '&mdash;' ?></span>
  </td>
  <td class="num">
    <span class="incl-val"><?= $s['median_incl'] ?? '&mdash;' ?></span>
    <span class="excl-val" style="color:#2e7d52"><?= $s['median_excl'] ?? '&mdash;' ?></span>
  </td>
  <td class="num">
    <span class="incl-val"><?= $s['std_incl'] ?? '&mdash;' ?></span>
    <span class="excl-val" style="color:#2e7d52"><?= $s['std_excl'] ?? '&mdash;' ?></span>
  </td>
  <td class="num">
    <?php if (($s['min_excl'] ?? null) !== null): ?>
      <?= $s['min_excl'] ?>&ndash;<?= $s['max_excl'] ?>
    <?php else: ?>&mdash;<?php endif; ?>
  </td>
</tr>
<?php if ($isTotal) echo '</tfoot>'; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="stat-legend-note">
  <span class="incl-val">Blue</span> = including Day-28 cases (LOS counted as 28).&ensp;
  <span class="excl-val">Green</span> = discharged babies only.
</p>
        <?php
        return ob_get_clean();
    }

    private function renderArmTable(array $byArm): string
    {
        $arms = array_filter(array_keys($byArm), fn($k) => $k !== 'Total');
        $rows = array_merge(array_values($arms), ['Total']);
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="los-table report-table">
<thead>
<tr>
  <th>Study arm</th>
  <th class="num">N</th>
  <th class="num">Day&nbsp;28<br><span class="stat-note">N&thinsp;(%)</span></th>
  <th class="num">Mean<br><span class="stat-note">incl&thinsp;/&thinsp;excl</span></th>
  <th class="num">Median<br><span class="stat-note">incl&thinsp;/&thinsp;excl</span></th>
  <th class="num">SD<br><span class="stat-note">incl&thinsp;/&thinsp;excl</span></th>
  <th class="num">Min&ndash;Max<br><span class="stat-note">excl Day-28</span></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $arm):
    $s       = $byArm[$arm] ?? [];
    $isTotal = ($arm === 'Total');
    if ($isTotal) echo '<tfoot>';
?>
<tr>
  <td class="site-col">
    <?= $isTotal ? '<strong>Total</strong>' : htmlspecialchars($arm) ?>
  </td>
  <td class="num"><?= $s['count'] ?? '&mdash;' ?></td>
  <td class="num">
    <?= $s['count_day28'] ?? '&mdash;' ?>
    <?php if (($s['pct_day28'] ?? null) !== null): ?>
      <span class="sub-val"><?= $s['pct_day28'] ?>%</span>
    <?php endif; ?>
  </td>
  <td class="num">
    <span class="incl-val"><?= $s['mean_incl'] ?? '&mdash;' ?></span>
    <span class="excl-val" style="color:#2e7d52"><?= $s['mean_excl'] ?? '&mdash;' ?></span>
  </td>
  <td class="num">
    <span class="incl-val"><?= $s['median_incl'] ?? '&mdash;' ?></span>
    <span class="excl-val" style="color:#2e7d52"><?= $s['median_excl'] ?? '&mdash;' ?></span>
  </td>
  <td class="num">
    <span class="incl-val"><?= $s['std_incl'] ?? '&mdash;' ?></span>
    <span class="excl-val" style="color:#2e7d52"><?= $s['std_excl'] ?? '&mdash;' ?></span>
  </td>
  <td class="num">
    <?php if (($s['min_excl'] ?? null) !== null): ?>
      <?= $s['min_excl'] ?>&ndash;<?= $s['max_excl'] ?>
    <?php else: ?>&mdash;<?php endif; ?>
  </td>
</tr>
<?php if ($isTotal) echo '</tfoot>'; ?>
<?php endforeach; ?>
</tbody>
</table>
</div>
        <?php
        return ob_get_clean();
    }

    private function renderFilterBar(array $siteOrder, array $siteLabels): string
    {
        ob_start();
        ?>
<div class="filter-row">
  <label>Site
    <select id="filterSite" onchange="applyLosFilters()">
      <option value="">All sites</option>
      <?php foreach ($siteOrder as $code): ?>
      <option value="<?= htmlspecialchars(strtolower($code)) ?>">
        <?= htmlspecialchars($siteLabels[$code] ?? $code) ?>
      </option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Study arm
    <select id="filterArm" onchange="applyLosFilters()">
      <option value="">All arms</option>
      <option>Intervention</option>
      <option>Control</option>
    </select>
  </label>
  <label>LOS status
    <select id="filterStatus" onchange="applyLosFilters()">
      <option value="">All</option>
      <option value="day28">Day 28 (in hospital)</option>
      <option value="discharged">Discharged</option>
      <option value="missing">Missing data</option>
    </select>
  </label>
  <label>Search record ID
    <input type="text" id="filterSearch" placeholder="e.g. REG-001" oninput="applyLosFilters()">
  </label>
  <span id="filterCount"></span>
</div>
        <?php
        return ob_get_clean();
    }

    private function renderPatientTable(array $patients, array $siteLabels): string
    {
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="los-table report-table" id="patientTable">
<thead>
<tr>
  <th onclick="sortLosTable(0)">Record ID</th>
  <th onclick="sortLosTable(1)">Site</th>
  <th onclick="sortLosTable(2)">Study Arm</th>
  <th onclick="sortLosTable(3)">Admission Date</th>
  <th onclick="sortLosTable(4)">Discharge Date</th>
  <th class="num" onclick="sortLosTable(5)">LOS (days)</th>
  <th>Status</th>
</tr>
</thead>
<tbody id="patientTableBody">
<?php foreach ($patients as $id => $p):
    $site = $p['site']      ?? '';
    $arm  = $p['study_arm'] ?? '';
    $los  = $p['los_days'];
    $inH  = $p['in_hosp_day28'];

    if ($los === null)     $status = 'missing';
    elseif ($inH === true) $status = 'day28';
    else                   $status = 'discharged';

    $badge = match ($status) {
        'day28'      => '<span class="los-badge badge-28">Day&nbsp;28</span>',
        'missing'    => '<span class="los-badge badge-null">Missing</span>',
        'discharged' => match (true) {
            $los < 7   => '<span class="los-badge badge-lt7">&lt;&thinsp;7&thinsp;d</span>',
            $los <= 14 => '<span class="los-badge badge-7_14">7&ndash;14&thinsp;d</span>',
            default    => '<span class="los-badge badge-15_27">15&ndash;27&thinsp;d</span>',
        },
    };

    $disCell = ($status === 'day28')
        ? 'In hospital'
        : htmlspecialchars($p['discharge_date'] ?? '');
?>
<tr data-site="<?= htmlspecialchars(strtolower($site)) ?>"
    data-arm="<?= htmlspecialchars(strtolower($arm)) ?>"
    data-status="<?= $status ?>"
    data-id="<?= htmlspecialchars(strtolower((string)$id)) ?>">
  <td><?= htmlspecialchars((string)$id) ?></td>
  <td><?= htmlspecialchars($siteLabels[$site] ?? $site) ?></td>
  <td><?= htmlspecialchars($arm) ?></td>
  <td><?= htmlspecialchars($p['admission_date'] ?? '') ?></td>
  <td><?= $disCell ?></td>
  <td class="num"><?= $los !== null ? $los : '&mdash;' ?></td>
  <td><?= $badge ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Asset tag builders
    // =========================================================================

    /**
     * Returns [cssTag, losJsTag].
     *   inline mode  → CSS inlined in <style>, JS inlined in <script>
     *   web mode     → <link> and <script src="..."> references
     */
    private function buildAssetTags(): array
    {
        if ($this->inline) {
            $baseCss = $this->readFile(self::PATH_CSS_BASE);
            $losCss  = $this->readFile(self::PATH_CSS_LOS);
            $losJs   = $this->readFile(self::PATH_JS_LOS);
            $cssTag  = "<style>\n{$baseCss}\n{$losCss}\n</style>";
            $jsTag   = "<script>\n{$losJs}\n</script>";
        } else {
            $cssTag = "<link rel='stylesheet' href='{$this->cssPath}'>\n"
                    . "<link rel='stylesheet' href='/assets/css/los_report.css'>";
            $jsTag  = "<script src='/assets/js/los_report.js'></script>";
        }

        return [$cssTag, $jsTag];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function siteOrder(array $bySite, array $siteLabels): array
    {
        $all     = array_filter(array_keys($bySite), fn($k) => $k !== 'Total');
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
        $from = $period['date_from'] ?? null;
        $to   = $period['date_to']   ?? null;
        if ($from && $to)  return "Period: {$from} to {$to}";
        if ($to)           return "Period: up to {$to}";
        if ($from)         return "Period: from {$from}";
        return 'Period: All dates';
    }

    /**
     * Read a file relative to __DIR__.
     * Returns empty string if the file doesn't exist rather than throwing.
     */
    private function readFile(string $relativePath): string
    {
        $abs = realpath(__DIR__ . $relativePath);
        return ($abs && file_exists($abs)) ? file_get_contents($abs) : '';
    }
}

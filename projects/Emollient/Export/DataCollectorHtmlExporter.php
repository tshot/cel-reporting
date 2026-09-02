<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * DataCollectorHtmlExporter
 *
 * Renders the Data Collector Performance Report HTML shell.
 *
 * Responsibilities (PHP only):
 *   - Output HTML skeleton (controls, section headings, table/canvas placeholders)
 *   - Inject PHP data as window.DC_* globals for dc_report.js to consume
 *   - Link Chart.js and dc_report.js
 *
 * All chart/table rendering logic lives in:
 *   /assets/js/dc_report.js
 */
class DataCollectorHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $toolbar;

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '/assets/css/emollient_eligible.css',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->toolbar = $toolbar;
    }

    // =========================================================================
    // ExporterInterface
    // =========================================================================

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
    // Render — HTML skeleton + data injection only
    // =========================================================================

    private function render(array $payload): string
    {
        $byDc       = $payload['by_dc']       ?? [];
        $metricMeta = $payload['metric_meta'] ?? [];
        $dcList     = $payload['dc_list']     ?? [];
        $wtHist     = $payload['wt_hist']     ?? [];
        $wtBins     = $payload['wt_bins']     ?? [];
        $siteLabels = $payload['site_labels'] ?? [];
        $period     = $payload['period']      ?? [];

        $periodLabel = $this->periodLabel($period);

        // CSS
        $css = $this->inline
            ? '<style>' . $this->readFile('css/emollient_eligible.css')
              . "\n" . $this->readFile('css/dc_report.css') . '</style>'
            : "<link rel='stylesheet' href='/assets/css/emollient_eligible.css'>\n"
            . "<link rel='stylesheet' href='/assets/css/dc_report.css'>";

        // JS — inline for download/PDF, external src for browser
        $chartJsCdn = 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js';
        $js = $this->inline
            ? "<script>\n" . $this->readFile('js/dc_report.js') . "\n</script>"
            : "<script src='{$chartJsCdn}'></script>\n"
            . "<script src='/assets/js/dc_report.js'></script>";

        // Site filter options
        $siteOptions = '<option value="">All sites</option>';
        foreach ($siteLabels as $code => $lbl) {
            $siteOptions .= '<option value="' . htmlspecialchars($code) . '">'
                          . htmlspecialchars($lbl) . '</option>';
        }

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Data Collector Performance — PreScreening</title>
<?= $css ?>
<script src="<?= $chartJsCdn ?>"></script>
</head>
<body>
<div class="report-container">
  <?= $this->toolbar ?>

  <div class="report-header">
    <div class="report-title">Data Collector Performance Report — PreScreening</div>
    <div class="report-period"><?= htmlspecialchars($periodLabel) ?></div>
  </div>

  <!-- Controls -->
  <div class="dc-controls">
    <div class="dc-ctrl-group">
      <label class="dc-ctrl-label">Site</label>
      <select id="siteFilter" onchange="dcRefresh()"><?= $siteOptions ?></select>
    </div>
    <div class="dc-ctrl-group">
      <label class="dc-ctrl-label">Date from</label>
      <input type="date" id="dateFrom" value="<?= htmlspecialchars($period['date_from'] ?? '') ?>"
             onchange="dcApplyDateFilter()">
    </div>
    <div class="dc-ctrl-group">
      <label class="dc-ctrl-label">Date to</label>
      <input type="date" id="dateTo" value="<?= htmlspecialchars($period['date_to'] ?? '') ?>"
             onchange="dcApplyDateFilter()">
    </div>
    <div class="dc-ctrl-group">
      <label class="dc-ctrl-label">Chart size</label>
      <select id="chartSize" onchange="dcSetChartSize(this.value)">
        <option value="dc-chart-sm">Small</option>
        <option value="dc-chart-md" selected>Medium</option>
        <option value="dc-chart-lg">Large</option>
        <option value="dc-chart-xl">Extra large</option>
        <option value="dc-chart-full">Full page</option>
      </select>
    </div>
    <div class="dc-ctrl-group">
      <button class="dc-btn" onclick="dcDownloadCsv()">&#8659; CSV</button>
    </div>
  </div>

  <!-- Section 1: Exclusion table -->
  <div class="dc-section">
    <h3 class="dc-section-title">PreScreening Exclusions by Data Collector</h3>
    <div class="dc-table-wrap">
      <table class="dc-table">
        <thead id="excl-thead"></thead>
        <tbody id="excl-tbody"></tbody>
      </table>
    </div>
  </div>

  <!-- Section 2: Exclusion bar chart -->
  <div class="dc-section">
    <h3 class="dc-section-title">Exclusion Comparison across Data Collectors</h3>
    <p class="dc-section-hint">
      One group per exclusion criterion. Each bar = one data collector.
      Hover for % of N.
    </p>
    <div class="dc-chart-wrap dc-chart-md">
      <canvas id="excl-chart"></canvas>
    </div>
  </div>

  <!-- Section 3: Birth weight histogram -->
  <div class="dc-section">
    <h3 class="dc-section-title">NICU Admission Weight Distribution by Data Collector</h3>
    <p class="dc-section-hint" id="wt-bin-hint"></p>
    <div class="dc-chart-wrap dc-chart-md">
      <canvas id="wt-chart"></canvas>
    </div>
  </div>

  <!-- Section 4: Field value frequency -->
  <div class="dc-section">
    <h3 class="dc-section-title">Field Value Distribution by Data Collector</h3>
    <p class="dc-section-hint">
      Shows how often each value appears per field per DC.
      Large deviations from the Total row indicate potential data quality issues.
      <strong>(blank)</strong> = field left empty.
    </p>
    <div class="dc-ctrl-group" style="margin-bottom:12px">
      <label class="dc-ctrl-label">Field</label>
      <select id="freqFieldSelector" onchange="dcRefresh()"></select>
    </div>
    <div class="dc-table-wrap">
      <table class="dc-table">
        <thead id="freq-thead"></thead>
        <tbody id="freq-tbody"></tbody>
      </table>
    </div>
    <div class="dc-chart-wrap dc-chart-md" style="margin-top:16px">
      <canvas id="freq-chart"></canvas>
    </div>
  </div>

  <!-- Section 5: Completion rate -->
  <div class="dc-section">
    <h3 class="dc-section-title">Field Completion Rate by Data Collector</h3>
    <p class="dc-section-hint">
      % of records where each expected field is filled (non-blank).
      Low completion by one DC relative to peers suggests systematic skipping.
    </p>
    <div class="dc-table-wrap">
      <table class="dc-table">
        <thead id="comp-thead"></thead>
        <tbody id="comp-tbody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Data injected for dc_report.js -->
<script>
window.DC_BY_DC       = <?= json_encode($byDc,       JSON_UNESCAPED_UNICODE) ?>;
window.DC_METRIC_META = <?= json_encode($metricMeta, JSON_UNESCAPED_UNICODE) ?>;
window.DC_LIST        = <?= json_encode($dcList,     JSON_UNESCAPED_UNICODE) ?>;
window.DC_WT_HIST     = <?= json_encode($wtHist,     JSON_UNESCAPED_UNICODE) ?>;
window.DC_WT_BINS     = <?= json_encode($wtBins,     JSON_UNESCAPED_UNICODE) ?>;
window.DC_SITE_LABELS = <?= json_encode($siteLabels, JSON_UNESCAPED_UNICODE) ?>;
window.DC_WT_BIN       = <?= (int)($payload['wt_bin'] ?? 50) ?>;
window.DC_FIELD_FREQ   = <?= json_encode($payload['field_freq']   ?? [], JSON_UNESCAPED_UNICODE) ?>;
window.DC_FREQ_FIELDS  = <?= json_encode($payload['freq_fields']  ?? [], JSON_UNESCAPED_UNICODE) ?>;
window.DC_COMPLETION   = <?= json_encode($payload['completion']   ?? [], JSON_UNESCAPED_UNICODE) ?>;
window.DC_EXP_FIELDS   = <?= json_encode($payload['expected_fields'] ?? [], JSON_UNESCAPED_UNICODE) ?>;
</script>
<?= $js ?>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function periodLabel(array $period): string
    {
        $from = $period['date_from'] ?? null;
        $to   = $period['date_to']   ?? null;
        if ($from && $to)  return "Period: {$from} to {$to}";
        if ($to)           return "Period: up to {$to}";
        if ($from)         return "Period: from {$from}";
        return 'All dates';
    }

    private function readFile(string $relativePath): string
    {
        $abs = realpath(
            __DIR__ . '/../../../reporting-engine/public/assets/' . $relativePath
        );
        return ($abs && file_exists($abs)) ? file_get_contents($abs) : '';
    }
}

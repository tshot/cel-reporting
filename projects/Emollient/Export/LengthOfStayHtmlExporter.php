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
 * Reads LengthOfStayAggregator v2. LOS is real for every baby whose outcome
 * is los_computed, so a single set of statistics is shown. The first section
 * is the enrolment audit: every enrolled baby appears in exactly one outcome
 * column, and each row is checked to add up to the number enrolled.
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
        $histBuckets     = [];   // filled below: index = LOS in days

        foreach ($siteOrder as $code) {
            $label             = $siteLabels[$code] ?? $code;
            $chartSiteLabels[] = $label;
            $chartDist[]       = $distribution[$code]
                                 ?? ['lt7' => 0, 'w7_14' => 0, 'w15_27' => 0, 'day28' => 0];
            $s                 = $bySite[$code] ?? [];
            $chartBoxData[]    = [
                'label'  => $label,
                'min'    => $s['min']    ?? null,
                'median' => $s['median'] ?? null,
                'max'    => $s['max']    ?? null,
                'mean'   => $s['mean']   ?? null,
                'std'    => $s['std']    ?? null,
            ];
        }

        // One bucket per day, 0 (same-day) to the longest stay. The v1 page
        // capped this at 28, which piled every long post-28 stay into one bar.
        $maxLos = 0;
        foreach ($patients as $p) {
            if ($p['los_days'] !== null) $maxLos = max($maxLos, (int)$p['los_days']);
        }
        $histBuckets = array_fill(0, $maxLos + 1, 0);
        foreach ($patients as $p) {
            if ($p['los_days'] !== null) $histBuckets[(int)$p['los_days']]++;
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

  <!-- ── Section 0: Enrolment audit ───────────────────────────────────── -->
  <div class="section-block" id="section-audit">
    <div class="section-header">
      <div class="section-title">Enrolment Audit &mdash; What Happened to Every Enrolled Baby</div>
    </div>
    <?= $this->renderAuditSection($payload, $siteOrder, $siteLabels) ?>
  </div>

  <!-- ── Section 1: Summary cards ─────────────────────────────────────── -->
  <div class="section-block" id="section-summary">
    <div class="section-header">
      <div class="section-title">Summary</div>
    </div>
    <?= $this->renderSummaryCards($bySite, $payload['audit_by_site']['Total'] ?? []) ?>
  </div>

  <!-- ── Section 2: Site summary table ───────────────────────────────── -->
  <div class="section-block" id="section-by-site">
    <div class="section-header">
      <div class="section-title">Length of Stay by Hospital</div>
    </div>
    <?= $this->renderSiteTable($bySite, $siteOrder, $siteLabels) ?>
  </div>

  <!-- ── Section 3: Arm summary table ────────────────────────────────── -->
  <div class="section-block" id="section-by-arm">
    <div class="section-header">
      <div class="section-title">Length of Stay by Study Arm</div>
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
      <span><span class="chart-legend-dot" style="background:#f87171"></span>28&nbsp;days&nbsp;or&nbsp;more</span>
    </div>
  </div>

  <!-- ── Section 5: Box plot ──────────────────────────────────────────── -->
  <div class="section-block" id="section-box-plot">
    <div class="section-header">
      <div class="section-title">LOS Spread by Site</div>
    </div>
    <div class="chart-wrap"><canvas id="chartBoxPlot"></canvas></div>
    <p class="chart-footnote">Median line &middot; Mean &plusmn; 1&thinsp;SD box &middot; min/max range. Babies with LOS computed only.</p>
  </div>

  <!-- ── Section 6: Histogram ─────────────────────────────────────────── -->
  <div class="section-block" id="section-histogram">
    <div class="section-header">
      <div class="section-title">LOS Frequency Distribution</div>
    </div>
    <div class="chart-wrap"><canvas id="chartHistogram"></canvas></div>
    <p class="chart-footnote">Babies with LOS computed. One bar per day of stay, from 0 (same-day discharge) to the longest recorded, coloured by band.</p>
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
            'audit'    => 'LOS — Enrolment Audit',
            'by-site'  => 'LOS — Hospital Summary',
            'by-arm'   => 'LOS — Study Arm Summary',
            'patients' => 'LOS — Per-Patient Detail',
            default    => 'Length of Stay',
        };

        $hasColumns = in_array($section, ['audit', 'by-site', 'by-arm', 'patients'], true);

        $content = match ($section) {
            'summary'  => $this->renderSummaryCards($bySite, $payload['audit_by_site']['Total'] ?? []),
            'audit'    => $this->renderAuditSection($payload, $siteOrder, $siteLabels),
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

    private function renderSummaryCards(array $bySite, array $a = []): string
    {
        $t        = $bySite['Total'] ?? [];
        $enrolled = (int)($a['enrolled'] ?? 0);
        $n        = (int)($t['count'] ?? 0);
        $pending  = (int)(($a['still_in_study'] ?? 0) + ($a['regular_overdue'] ?? 0)
                  + ($a['awaiting_post28'] ?? 0));
        $excluded = 0;
        foreach (['withdrawn', 'protocol_deviation', 'death', 'lama', 'abscond', 'dopr', 'referral'] as $k) {
            $excluded += (int)($a[$k] ?? 0);
        }
        $issues = (int)($a['data_issue'] ?? 0);
        $pct    = $enrolled > 0 ? round($n / $enrolled * 100, 1) . '% of enrolled' : '';
        $d      = fn($v) => $v !== null ? $v . '&nbsp;d' : '&mdash;';
        ob_start();
        ?>
<div class="los-cards">
  <div class="los-card">
    <div class="los-card-label">Enrolled</div>
    <div class="los-card-value"><?= $enrolled ?></div>
    <div class="los-card-sub">babies in this report</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">LOS computed</div>
    <div class="los-card-value"><?= $n ?></div>
    <div class="los-card-sub"><?= $pct ?><?= !empty($t['count_other']) ? ' &middot; ' . (int)$t['count_other'] . ' Other' : '' ?></div>
  </div>
  <div class="los-card accent">
    <div class="los-card-label">Mean LOS</div>
    <div class="los-card-value"><?= $d($t['mean'] ?? null) ?></div>
    <div class="los-card-sub">planned discharges</div>
  </div>
  <div class="los-card accent">
    <div class="los-card-label">Median LOS</div>
    <div class="los-card-value"><?= $d($t['median'] ?? null) ?></div>
    <div class="los-card-sub">planned discharges</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">Discharge not yet recorded</div>
    <div class="los-card-value"><?= $pending ?></div>
    <div class="los-card-sub">in study, form overdue, or awaiting post-28</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">Excluded</div>
    <div class="los-card-value"><?= $excluded ?></div>
    <div class="los-card-sub">withdrawal, deviation or discharge type</div>
  </div>
  <div class="los-card">
    <div class="los-card-label">Data issues</div>
    <div class="los-card-value"><?= $issues ?></div>
    <div class="los-card-sub">see the audit section</div>
  </div>
</div>
        <?php
        return ob_get_clean();
    }

    private function renderSiteTable(array $bySite, array $siteOrder, array $siteLabels): string
    {
        $rows = array_merge($siteOrder, isset($bySite['Total']) ? ['Total'] : []);
        return $this->renderStatsTable($bySite, $rows, $siteLabels, 'Site');
    }

    private function renderArmTable(array $byArm): string
    {
        $arms = array_values(array_filter(array_keys($byArm), fn($k) => $k !== 'Total'));
        return $this->renderStatsTable($byArm, array_merge($arms, ['Total']), [], 'Study arm');
    }

    private function renderStatsTable(array $tbl, array $rows, array $names, string $groupHeader): string
    {
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="los-table report-table">
<thead>
<tr>
  <th><?= htmlspecialchars($groupHeader) ?></th>
  <th class="num">N<br><span class="stat-note">LOS computed</span></th>
  <th class="num">Mean</th>
  <th class="num">Median</th>
  <th class="num">SD</th>
  <th class="num">Min&ndash;Max</th>
  <th class="num">Other<br><span class="stat-note">TYP_OTH</span></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $key):
    $s = $tbl[$key] ?? null;
    if ($s === null) continue;
    $isTotal = ($key === 'Total');
    $name    = $isTotal ? '<strong>Total</strong>'
             : htmlspecialchars($key === '' ? 'Not recorded' : ($names[$key] ?? $key));
?>
<tr<?= $isTotal ? ' class="total-row"' : '' ?>>
  <td class="site-col"><?= $name ?></td>
  <td class="num"><?= (int)($s['count'] ?? 0) ?></td>
  <td class="num"><?= $s['mean']   ?? '&mdash;' ?></td>
  <td class="num"><?= $s['median'] ?? '&mdash;' ?></td>
  <td class="num"><?= $s['std']    ?? '&mdash;' ?></td>
  <td class="num"><?= ($s['min'] ?? null) !== null ? $s['min'] . '&ndash;' . $s['max'] : '&mdash;' ?></td>
  <td class="num"><?= (int)($s['count_other'] ?? 0) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p class="stat-legend-note">Babies with LOS computed only &mdash; planned discharges
(TYP_FP, TYP_OTH) with valid dates. LOS is in completed days from hospital admission.</p>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Enrolment audit
    // =========================================================================

    private function renderAuditSection(array $payload, array $siteOrder, array $siteLabels): string
    {
        $bySite  = $payload['audit_by_site']     ?? [];
        $byArm   = $payload['audit_by_arm']      ?? [];
        $labels  = $payload['outcome_labels']    ?? [];
        $issues  = $payload['data_issues']       ?? [];
        $iLabels = $payload['data_issue_labels'] ?? [];

        $siteRows = array_values(array_filter($siteOrder, fn($c) => isset($bySite[$c])));
        if (isset($bySite['Total'])) $siteRows[] = 'Total';

        ob_start();
        ?>
<p class="stat-legend-note">Every enrolled baby is counted in exactly one outcome column, so
each row must add up to <strong>Enrolled</strong>. The last column confirms it does.
Click any number to list those babies in the Per-Patient Detail table. The order in which outcomes are assigned is set out in the Length of Stay computation document.</p>
<?= $this->renderAuditTable($bySite, $siteRows, $siteLabels, $labels, 'Site') ?>
<h4 style="margin:1.4em 0 .5em">By study arm</h4>
<?= $this->renderAuditTable($byArm, array_keys($byArm), [], $labels, 'Study arm', 'arm') ?>
<?php if ($issues): ?>
<h4 style="margin:1.4em 0 .5em">Data issues by reason</h4>
<?= $this->renderIssueTable($issues, $iLabels, $siteOrder, $siteLabels) ?>
<?php endif; ?>
        <?php
        return ob_get_clean();
    }

    private function renderAuditTable(array $tbl, array $rows, array $names, array $labels, string $groupHeader, string $dim = 'site'): string
    {
        $groups = [
            ['Excluded &mdash;<br>study status',  ['withdrawn', 'protocol_deviation']],
            ['Discharge not<br>yet recorded',      ['still_in_study', 'regular_overdue', 'awaiting_post28']],
            ['Excluded &mdash;<br>discharge type', ['death', 'lama', 'abscond', 'dopr', 'referral']],
        ];
        $single = ['data_issue', 'los_computed'];
        $short  = [
            'withdrawn' => 'With-<br>drawn', 'protocol_deviation' => 'Protocol<br>deviation',
            'still_in_study' => 'Still in<br>study', 'regular_overdue' => 'Regular<br>form<br>overdue',
            'awaiting_post28' => 'Awaiting<br>post-28', 'death' => 'Death', 'lama' => 'LAMA',
            'abscond' => 'Abscond', 'dopr' => 'DOPR', 'referral' => 'Referral',
            'data_issue' => 'Data<br>issue', 'los_computed' => 'LOS<br>computed',
        ];
        $cols = [];
        foreach ($groups as $g) foreach ($g[1] as $k) $cols[] = $k;
        foreach ($single as $k) $cols[] = $k;
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="los-table report-table">
<thead>
<tr>
  <th rowspan="2"><?= htmlspecialchars($groupHeader) ?></th>
  <th rowspan="2" class="num">Enrolled</th>
<?php foreach ($groups as $g): ?>
  <th colspan="<?= count($g[1]) ?>" class="num"><?= $g[0] ?></th>
<?php endforeach; ?>
<?php foreach ($single as $k): ?>
  <th rowspan="2" class="num" title="<?= htmlspecialchars($labels[$k] ?? $k) ?>"><?= $short[$k] ?></th>
<?php endforeach; ?>
  <th rowspan="2" class="num">Adds<br>up</th>
</tr>
<tr>
<?php foreach ($groups as $g): foreach ($g[1] as $k): ?>
  <th class="num" title="<?= htmlspecialchars($labels[$k] ?? $k) ?>"><?= $short[$k] ?></th>
<?php endforeach; endforeach; ?>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $key):
    $r = $tbl[$key] ?? null;
    if ($r === null) continue;
    $isTotal = ($key === 'Total');
    $name    = $isTotal ? '<strong>Total</strong>'
             : htmlspecialchars($key === '' ? 'Not recorded' : ($names[$key] ?? $key));
?>
<tr<?= $isTotal ? ' class="total-row"' : '' ?>>
  <td class="site-col"><?= $name ?></td>
  <td class="num"><strong><?= $this->auditLink((int)($r['enrolled'] ?? 0), '', (string)$key, $dim) ?></strong></td>
<?php foreach ($cols as $k): ?>
  <td class="num"><?= $this->auditLink((int)($r[$k] ?? 0), $k, (string)$key, $dim) ?></td>
<?php endforeach; ?>
  <td class="num"><?= !empty($r['reconciles']) ? '&#10003;' : '<strong style="color:#b91c1c">&#10007;&nbsp;no</strong>' ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
        <?php
        return ob_get_clean();
    }

    /**
     * A count in the audit table, linked to the per-patient table filtered to
     * exactly those babies. Zero, and a blank ('Not recorded') group, are left
     * as plain text: there is nothing to list, or no filter that can express it.
     */
    private function auditLink(int $n, string $outcome, string $key, string $dim): string
    {
        if ($n === 0 || $key === '') return (string)$n;
        $site = ($dim === 'site' && $key !== 'Total') ? strtolower($key) : '';
        $arm  = ($dim === 'arm'  && $key !== 'Total') ? strtolower($key) : '';
        $js   = sprintf('return window.losFilterTo ? losFilterTo(%s, %s, %s) : true;',
                        json_encode($outcome), json_encode($site), json_encode($arm));
        return '<a href="#section-patients" style="color:inherit;text-decoration:underline dotted"'
             . ' title="List these babies" onclick="' . htmlspecialchars($js) . '">' . $n . '</a>';
    }

    private function renderIssueTable(array $issues, array $iLabels, array $siteOrder, array $siteLabels): string
    {
        $sites = array_values(array_filter($siteOrder, function ($c) use ($issues) {
            foreach ($issues as $bySite) {
                if (!empty($bySite[$c])) return true;
            }
            return false;
        }));
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="los-table report-table">
<thead>
<tr>
  <th>Reason</th>
<?php foreach ($sites as $c): ?>
  <th class="num"><?= htmlspecialchars($siteLabels[$c] ?? $c) ?></th>
<?php endforeach; ?>
  <th class="num">Total</th>
</tr>
</thead>
<tbody>
<?php foreach ($issues as $reason => $bySite): ?>
<tr>
  <td><?= htmlspecialchars($iLabels[$reason] ?? $reason) ?></td>
<?php foreach ($sites as $c): ?>
  <td class="num"><?= (int)($bySite[$c] ?? 0) ?></td>
<?php endforeach; ?>
  <td class="num"><strong><?= (int)($bySite['Total'] ?? 0) ?></strong></td>
</tr>
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
  <label>Outcome
    <select id="filterStatus" onchange="applyLosFilters()">
      <option value="">All</option>
      <option value="los_computed">LOS computed</option>
      <option value="still_in_study">Still in study</option>
      <option value="regular_overdue">Regular discharge form overdue</option>
      <option value="awaiting_post28">Awaiting post-28 discharge</option>
      <option value="withdrawn">Withdrawn</option>
      <option value="protocol_deviation">Protocol deviation</option>
      <option value="death">Death</option>
      <option value="lama">LAMA</option>
      <option value="abscond">Abscond</option>
      <option value="dopr">DOPR</option>
      <option value="referral">Referral</option>
      <option value="data_issue">Data issue</option>
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
  <th onclick="sortLosTable(3)">Admission</th>
  <th onclick="sortLosTable(4)">Discharge</th>
  <th onclick="sortLosTable(5)">Discharge type</th>
  <th class="num" onclick="sortLosTable(6)">LOS (days)</th>
  <th onclick="sortLosTable(7)">Outcome</th>
</tr>
</thead>
<tbody id="patientTableBody">
<?php foreach ($patients as $id => $p):
    $site    = $p['site']      ?? '';
    $arm     = $p['study_arm'] ?? '';
    $los     = $p['los_days']  ?? null;
    $outcome = $p['outcome']   ?? '';
    $detail  = $p['detail']    ?? '';

    if ($outcome === 'los_computed' && $los !== null) {
        $badge = match (true) {
            $los < 7   => '<span class="los-badge badge-lt7">&lt;&thinsp;7&thinsp;d</span>',
            $los <= 14 => '<span class="los-badge badge-7_14">7&ndash;14&thinsp;d</span>',
            $los <= 27 => '<span class="los-badge badge-15_27">15&ndash;27&thinsp;d</span>',
            default    => '<span class="los-badge badge-28">28+&thinsp;d</span>',
        };
    } elseif ($outcome === 'data_issue') {
        $badge = '<span class="los-badge badge-null" style="background:#fde2e2;color:#991b1b">Data issue</span>';
    } else {
        $badge = '<span class="los-badge badge-null">' . htmlspecialchars($p['outcome_label'] ?? $outcome) . '</span>';
    }

    $dis     = (string)($p['discharge_date'] ?? '');
    $src     = (string)($p['discharge_source'] ?? '');
    $disCell = $dis === '' ? '&mdash;'
             : htmlspecialchars($dis) . ($src !== '' ? '<br><span class="stat-note">' . htmlspecialchars($src) . '</span>' : '');
?>
<tr data-site="<?= htmlspecialchars(strtolower($site)) ?>"
    data-arm="<?= htmlspecialchars(strtolower($arm)) ?>"
    data-status="<?= htmlspecialchars($outcome) ?>"
    data-id="<?= htmlspecialchars(strtolower((string)$id)) ?>">
  <td><?= htmlspecialchars((string)$id) ?></td>
  <td><?= htmlspecialchars($siteLabels[$site] ?? $site) ?></td>
  <td><?= htmlspecialchars($arm) ?></td>
  <td><?= htmlspecialchars((string)($p['admission_date'] ?? '')) ?></td>
  <td><?= $disCell ?></td>
  <td><?= htmlspecialchars((string)($p['discharge_type'] ?? '')) ?></td>
  <td class="num"><?= $los !== null ? (int)$los : '&mdash;' ?></td>
  <td><?= $badge ?><?php if ($outcome === 'data_issue' && $detail !== ''): ?><br><span class="stat-note"><?= htmlspecialchars($detail) ?></span><?php endif; ?></td>
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

<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * EmolliationCoverageDashboardHtmlExporter
 *
 * Dashboard variant of EmolliationCoverageHtmlExporter. Same coverage tables,
 * plus a third section — "No-Emolliation Reasons" — that breaks down every
 * Not-Given session by master reason and sub-reason, per site and grand total.
 *
 * Sections:
 *   summary  — coverage summary by site
 *   reasons  — No-Emolliation reason breakdown (NEW)
 *   babies   — per-baby detail (adds a "Reasons" column)
 *
 * Reason counts reconcile to Not Given (blank master => "Reason not recorded").
 */
class EmolliationCoverageDashboardHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    private const PATH_CSS_BASE = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';
    private const PATH_JS       = '/../../../reporting-engine/public/assets/js/emol_coverage.js';
    private const PATH_CSS_COV  = '/../../../reporting-engine/public/assets/css/emol_coverage.css';
    private const PATH_JS_SEC   = '/../../../reporting-engine/public/assets/js/section-export.js';
    private const PATH_CSS_SEC  = '/../../../reporting-engine/public/assets/css/section-export.css';

    /**
     * Display labels for reason codes. Codes not listed fall back to the raw
     * code (or verbatim free text). Extend as the data dictionary evolves.
     */
    private const MASTER_LABELS = [
        'MD'         => 'Medical / Doctor denied',
        'SAE'        => 'Serious Adverse Event',
        'OTH_NOEMOL' => 'Other',
        '__NONE__'   => 'Reason not recorded',
    ];

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '/assets/css/emollient_eligible.css',
        string $toolbar = ''
    )
    {
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
        if ($outputPath) { file_put_contents($outputPath, $html); }
        else             { header('Content-Type: text/html; charset=UTF-8'); echo $html; }
    }

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void
    {
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
        $bySite      = $payload['by_site']      ?? [];
        $babies      = $payload['babies']       ?? [];
        $siteLabels  = $payload['site_labels']  ?? [];
        $period      = $payload['period']       ?? [];
        $reasonTally = $payload['reason_tally'] ?? [];

        $periodLabel = $this->periodLabel($period);
        $siteOrder   = $this->siteOrder($bySite, $siteLabels);

        [$cssTag, $jsTag] = $this->buildAssetTags();
        $covCssTag = $this->inline
            ? '<style>' . $this->readFile(self::PATH_CSS_COV) . '</style>'
            : "<link rel='stylesheet' href='/assets/css/emol_coverage.css'>";

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Emolliation Coverage for Dashboard &mdash; Emollient</title>
<?= $cssTag ?>
<?= $covCssTag ?>
</head>
<body>
<div class="report-container">

  <?= $this->toolbar ?>

  <div class="report-header">
    <div class="report-title">Emolliation Coverage for Dashboard &mdash; Emollient Trial</div>
    <div class="report-period"><?= htmlspecialchars($periodLabel) ?></div>
  </div>

  <!-- Section 1: Summary Table -->
  <div class="cov-section" id="section-summary">
    <div class="cov-section-title">Coverage Summary by Site</div>
    <?= $this->renderSiteTable($bySite, $siteOrder, $siteLabels) ?>
  </div>

  <!-- Section 2: No-Emolliation Reasons (NEW) -->
  <div class="cov-section" id="section-reasons">
    <div class="cov-section-title">No-Emolliation Reasons</div>
    <?= $this->renderReasonTable($reasonTally, $siteOrder, $siteLabels, $bySite) ?>
  </div>

  <!-- Section 3: Per-baby detail -->
  <div class="cov-section" id="section-babies">
    <div class="cov-section-title">Per-Baby Detail</div>
    <?= $this->renderFilterBar($siteOrder, $siteLabels) ?>
    <?= $this->renderBabyTable($babies, $siteLabels) ?>
  </div>

</div>
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
        $bySite      = $payload['by_site']      ?? [];
        $babies      = $payload['babies']       ?? [];
        $siteLabels  = $payload['site_labels']  ?? [];
        $reasonTally = $payload['reason_tally'] ?? [];
        $siteOrder   = $this->siteOrder($bySite, $siteLabels);

        $reportCss = $this->readFile(self::PATH_CSS_BASE)
                   . "\n" . $this->readFile(self::PATH_CSS_COV);
        if ($inline)
        {
            $uiCssTag = '<style>' . $this->readFile(self::PATH_CSS_SEC) . '</style>';
            $uiJsTag  = '<script>' . $this->readFile(self::PATH_JS_SEC) . '</script>';
        }
        else
        {
            $uiCssTag = "<link rel='stylesheet' href='/assets/css/section-export.css'>";
            $uiJsTag  = "<script src='/assets/js/section-export.js'></script>";
        }

        $title   = match($section)
        {
            'summary' => 'Emolliation Coverage Summary',
            'reasons' => 'No-Emolliation Reasons',
            'babies'  => 'Emolliation Coverage — Per-Baby Detail',
            default   => 'Emolliation Coverage for Dashboard',
        };
        $content = match($section)
        {
            'summary' => $this->renderSiteTable($bySite, $siteOrder, $siteLabels),
            'reasons' => $this->renderReasonTable($reasonTally, $siteOrder, $siteLabels, $bySite),
            'babies'  => $this->renderFilterBar($siteOrder, $siteLabels)
                       . $this->renderBabyTable($babies, $siteLabels),
            default   => '<p>Unknown section.</p>',
        };

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Emollient &mdash; <?= htmlspecialchars($title) ?></title>
<style><?= $reportCss ?></style><?= $uiCssTag ?></head>
<body>
<div id="export-toolbar">
  <span class="section-title"><?= htmlspecialchars($title) ?></span>
  <span class="sep">|</span>
  <button class="exp-btn" onclick="window.print()">&#128438; Print</button>
  <button class="exp-btn" onclick="copyImage()">&#128247; Copy image</button>
  <button class="exp-btn" onclick="downloadHtml()">&#8659; HTML</button>
  <button class="exp-btn" onclick="copyTable()">&#128203; Copy table</button>
</div>
<div id="page-body"><div id="section-wrap"><?= $content ?></div></div>
<script src="/assets/js/html2canvas.min.js"></script>
<script>window.SECTION_HAS_COLUMNS=false;window.SECTION_COL_DEFS=[];window.SECTION_NAME=<?= json_encode($section) ?>;</script>
<?= $uiJsTag ?>
<?php if ($section === 'babies'): ?>
<script src="/assets/js/emol_coverage.js"></script>
<?php endif; ?>
</body></html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Sub-renderers
    // =========================================================================

    private function renderSiteTable(array $bySite, array $siteOrder, array $siteLabels): string
    {
        $rows = array_merge($siteOrder, ['Total']);
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="cov-table report-table">
<thead>
<tr>
  <th>Site</th>
  <th class="num">Enrolled<br><span style="font-weight:400;font-size:10px">Intervention arm</span></th>
  <th class="pct-cell">Received Full Intervention</th>
  <th class="pct-cell">Stopped<br><span style="font-weight:400;font-size:10px">before Day&nbsp;28</span></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $code):
    $s      = $bySite[$code] ?? [];
    $isT    = ($code === 'Total');
    $label  = $isT ? '<strong>Total</strong>' : htmlspecialchars($siteLabels[$code] ?? $code);
?>
<tr>
  <td class="site-col"><?= $label ?></td>
  <td class="num"><?= $s['enrolled'] ?? '&mdash;' ?></td>
  <td class="pct-cell">
    <?php $pf = $s['pct_full'] ?? null; if ($pf !== null): ?>
    <div class="pct-bar-wrap">
      <div class="pct-bar-bg"><div class="pct-bar-fill green" style="width:<?= min(100, $pf) ?>%"></div></div>
      <span class="pct-label green"><?= $pf ?>%</span>
    </div>
    <div style="font-size:11px;color:#8892a4;margin-top:2px"><?= $s['full_interv'] ?? 0 ?> of <?= $s['enrolled'] ?? 0 ?></div>
    <?php else: ?>&mdash;<?php endif; ?>
  </td>
  <td class="pct-cell">
    <?php $ps = $s['pct_stopped'] ?? null; if ($ps !== null): ?>
    <div class="pct-bar-wrap">
      <div class="pct-bar-bg"><div class="pct-bar-fill red" style="width:<?= min(100, $ps) ?>%"></div></div>
      <span class="pct-label red"><?= $ps ?>%</span>
    </div>
    <div style="font-size:11px;color:#8892a4;margin-top:2px"><?= $s['stopped'] ?? 0 ?> of <?= $s['enrolled'] ?? 0 ?></div>
    <?php else: ?>&mdash;<?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<p style="font-size:11px;color:#8892a4;margin-top:8px">
  Full Intervention = all <code>daily_interventionemolliation_form</code> sessions have <code>int_emoliate_baby&nbsp;=&nbsp;Y</code>.
  Day&nbsp;0 counts as a single session (done if the Day&nbsp;0 module is complete or a Day&nbsp;0 daily form&nbsp;=&nbsp;Y).
  Stopped = any stop event (discharge&nbsp;/&nbsp;protocol deviation&nbsp;/&nbsp;withdrawal&nbsp;/&nbsp;SAE) before Day&nbsp;28.
</p>
        <?php
        return ob_get_clean();
    }

    /**
     * No-Emolliation reason breakdown: one block per site (+ Total), each a
     * master-reason table with sub-reasons indented underneath. Counts tie to
     * the site's Not Given total.
     */
    private function renderReasonTable(array $reasonTally, array $siteOrder, array $siteLabels, array $bySite): string
    {
        $order = array_merge($siteOrder, ['Total']);
        ob_start();
        ?>
<div class="cov-reason-wrap">
<?php foreach ($order as $code):
    $tally   = $reasonTally[$code] ?? [];
    $isT     = ($code === 'Total');
    $label   = $isT ? 'Total (all sites)' : ($siteLabels[$code] ?? $code);
    $notGiven = $bySite[$code]['not_given'] ?? null;
    if (empty($tally) && !$notGiven) continue;

    // Sort master reasons by count desc
    uasort($tally, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
    $sumReasons = array_sum(array_map(fn($r) => $r['count'] ?? 0, $tally));
?>
<div class="reason-block" data-site="<?= htmlspecialchars(strtolower($code)) ?>" style="margin-bottom:18px">
  <div style="font-weight:700;margin-bottom:4px"><?= htmlspecialchars($label) ?>
    <span style="font-weight:400;font-size:11px;color:#8892a4">
      &mdash; <?= $sumReasons ?> not-given session<?= $sumReasons === 1 ? '' : 's' ?>
      <?php if ($notGiven !== null && $notGiven !== $sumReasons): ?>
        <span style="color:#c8102e">(Not Given total: <?= $notGiven ?>)</span>
      <?php endif; ?>
    </span>
  </div>
  <table class="cov-table report-table" style="max-width:640px">
  <thead><tr><th>Reason</th><th class="num">Count</th></tr></thead>
  <tbody>
  <?php foreach ($tally as $masterCode => $r):
      $masterLabel = self::MASTER_LABELS[$masterCode] ?? ($r['label'] ?? $masterCode);
  ?>
  <tr style="font-weight:600">
    <td><?= htmlspecialchars($masterLabel) ?></td>
    <td class="num"><?= $r['count'] ?></td>
  </tr>
  <?php
      $sub = $r['sub'] ?? [];
      arsort($sub);
      foreach ($sub as $subLabel => $c):
  ?>
  <tr>
    <td style="padding-left:26px;color:#5a6474">&#8627; <?= htmlspecialchars((string)$subLabel) ?></td>
    <td class="num" style="color:#5a6474"><?= $c ?></td>
  </tr>
  <?php endforeach; ?>
  <?php endforeach; ?>
  </tbody>
  </table>
</div>
<?php endforeach; ?>
</div>
<p style="font-size:11px;color:#8892a4;margin-top:4px">
  Reasons come from <code>int_no_emol_reason</code> on each <code>int_emoliate_baby&nbsp;=&nbsp;N</code> session,
  with sub-lists <code>int_doctor_deny_reason_list</code> (MD), <code>int_sae_reasons</code> (SAE),
  and free-text <code>int_other_no_emoll_reason</code> (Other). Counts reconcile to Not&nbsp;Given.
</p>
        <?php
        return ob_get_clean();
    }

    private function renderFilterBar(array $siteOrder, array $siteLabels): string
    {
        ob_start();
        ?>
<div class="cov-filter-row">
  <label>Site
    <select id="covFilterSite" onchange="applyCovFilters()">
      <option value="">All sites</option>
      <?php foreach ($siteOrder as $code): ?>
      <option value="<?= htmlspecialchars(strtolower($code)) ?>"><?= htmlspecialchars($siteLabels[$code] ?? $code) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Full intervention
    <select id="covFilterFull" onchange="applyCovFilters()">
      <option value="">All</option><option value="yes">Yes</option><option value="no">No</option>
    </select>
  </label>
  <label>Stopped
    <select id="covFilterStopped" onchange="applyCovFilters()">
      <option value="">All</option><option value="yes">Yes</option><option value="no">No</option>
    </select>
  </label>
  <label>Search record ID
    <input type="text" id="covFilterSearch" placeholder="e.g. REG-001" oninput="applyCovFilters()">
  </label>
  <span id="covFilterCount"></span>
</div>
        <?php
        return ob_get_clean();
    }

    private function renderBabyTable(array $babies, array $siteLabels): string
    {
        ob_start();
        ?>
<div style="overflow-x:auto">
<table class="cov-detail-table report-table">
<thead>
<tr>
  <th onclick="sortCovTable(0)">Record ID</th>
  <th onclick="sortCovTable(1)">Site</th>
  <th onclick="sortCovTable(2)" style="text-align:center">Full Intervention</th>
  <th onclick="sortCovTable(3)" style="text-align:center">Stopped</th>
  <th onclick="sortCovTable(4)">Stop Reason</th>
  <th class="num" onclick="sortCovTable(5)">Sessions<br><span style="font-weight:400;font-size:10px">Due</span></th>
  <th class="num" onclick="sortCovTable(6)">Sessions Attempted<br><span style="font-weight:400;font-size:10px">Forms filled (Y/N)</span></th>
  <th class="num" onclick="sortCovTable(7)">Given<br><span style="font-weight:400;font-size:10px">Forms with Y</span></th>
  <th class="num" onclick="sortCovTable(8)">Not Given</th>
  <th class="num" onclick="sortCovTable(9)">% Given<br><span style="font-weight:400;font-size:10px">of Attempted</span></th>
  <th class="num" onclick="sortCovTable(10)">% Given<br><span style="font-weight:400;font-size:10px">of Due</span></th>
  <th>No-Emolliation Reasons</th>
</tr>
</thead>
<tbody id="covBabyBody">
<?php
$gtBabies = count($babies); $gtDue=0; $gtTotal=0; $gtGiven=0; $gtNotGiven=0;
foreach ($babies as $b) {
    $gtDue      += $b['sessions_due']       ?? 0;
    $gtTotal    += $b['sessions_attempted'];
    $gtGiven    += $b['sessions_given'];
    $gtNotGiven += $b['sessions_not_given'];
}
$gtPct    = $gtTotal > 0 ? round($gtGiven / $gtTotal * 100, 1) : null;
$gtPctDue = $gtDue   > 0 ? round($gtGiven / $gtDue   * 100, 1) : null;
?>
<?php foreach ($babies as $id => $b):
    $site   = $b['site'] ?? '';
    $full   = $b['full_interv'];
    $stop   = $b['stopped'];
    $reason = $b['stop_reason'] ?? '';
    $due    = $b['sessions_due']       ?? null;
    $total  = $b['sessions_attempted'];
    $given  = $b['sessions_given'];
    $notG   = $b['sessions_not_given'];
    $pctG   = $total > 0 ? round($given / $total * 100, 1) : null;
    $pctDue = $due   > 0 ? round($given / $due   * 100, 1) : null;

    $fullBadge = $full ? "<span class='badge badge-yes'>Yes</span>" : "<span class='badge badge-no'>No</span>";
    $stopBadge = $stop ? "<span class='badge badge-stop'>Yes</span>" : "<span class='badge badge-none'>No</span>";
    $reasonLabel = match($reason) {
        'discharge'  => 'Discharge',
        'deviation'  => 'Protocol Deviation',
        'withdrawal' => 'Withdrawal',
        'sae'        => 'SAE',
        default      => '&mdash;',
    };
    $reasonCell = $this->babyReasonSummary($b['no_emol_reasons'] ?? []);
?>
<tr data-site="<?= htmlspecialchars(strtolower($site)) ?>"
    data-full="<?= $full ? 'yes' : 'no' ?>"
    data-stopped="<?= $stop ? 'yes' : 'no' ?>"
    data-id="<?= htmlspecialchars(strtolower((string)$id)) ?>">
  <td><?= htmlspecialchars((string)$id) ?></td>
  <td><?= htmlspecialchars($siteLabels[$site] ?? $site) ?></td>
  <td style="text-align:center"><?= $fullBadge ?></td>
  <td style="text-align:center"><?= $stopBadge ?></td>
  <td><?= $reasonLabel ?></td>
  <td class="num" data-due="<?= $due ?? '' ?>"><?= $due ?? '&mdash;' ?></td>
  <td class="num"><?= $total ?></td>
  <td class="num"><?= $given ?></td>
  <td class="num"><?= $notG ?: '&mdash;' ?></td>
  <td class="num"><?= $pctG !== null ? $pctG . '%' : '&mdash;' ?></td>
  <td class="num"><?= $pctDue !== null ? $pctDue . '%' : '&mdash;' ?></td>
  <td style="font-size:11px;color:#5a6474"><?= $reasonCell ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr id="covGtRow" style="background:#f0f4f8;font-weight:700;border-top:2px solid #c5d3e0">
  <td colspan="5" style="padding:8px 10px">Grand Total
    <span style="font-weight:400;font-size:11px;color:#5a6474;margin-left:8px"><?= $gtBabies ?> babies</span>
  </td>
  <td class="num" id="gt-due"><?= $gtDue ?: '&mdash;' ?></td>
  <td class="num" id="gt-total"><?= $gtTotal ?></td>
  <td class="num" id="gt-given"><?= $gtGiven ?></td>
  <td class="num" id="gt-not-given"><?= $gtNotGiven ?: '&mdash;' ?></td>
  <td class="num" id="gt-pct"><?= $gtPct !== null ? $gtPct . '%' : '&mdash;' ?></td>
  <td class="num" id="gt-pct-due"><?= $gtPctDue !== null ? $gtPctDue . '%' : '&mdash;' ?></td>
  <td></td>
</tr>
</tfoot>
</table>
</div>
        <?php
        return ob_get_clean();
    }

    /** Compact "Master ›Sub × n" summary for a baby's Not-Given reasons. */
    private function babyReasonSummary(array $reasons): string
    {
        if (empty($reasons)) return '&mdash;';
        $counts = [];
        foreach ($reasons as $r) {
            $master = self::MASTER_LABELS[$r['master'] ?: '__NONE__']
                ?? ($r['master_label'] ?? $r['master']);
            $key = $master . (!empty($r['sub']) ? ' › ' . $r['sub'] : '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        arsort($counts);
        $parts = [];
        foreach ($counts as $label => $n) {
            $parts[] = htmlspecialchars($label) . ($n > 1 ? " &times;{$n}" : '');
        }
        return implode('<br>', $parts);
    }

    // =========================================================================
    // Asset helpers
    // =========================================================================

    private function buildAssetTags(): array
    {
        if ($this->inline)
        {
            $css = $this->readFile(self::PATH_CSS_BASE);
            $js  = $this->readFile(self::PATH_JS);
            return ["<style>\n{$css}\n</style>", "<script>\n{$js}\n</script>"];
        }
        return [
            "<link rel='stylesheet' href='{$this->cssPath}'>",
            "<script src='/assets/js/emol_coverage.js'></script>",
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
        $from = $period['date_from'] ?? null;
        $to   = $period['date_to']   ?? null;
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

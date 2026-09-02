<?php

namespace CEL\Shared\Domain\Export;

/**
 * MonthlyDashboardHtmlExporter
 *
 * Renders the MonthlyDashboard aggregator output as an HTML table.
 *
 * Receives the full ReportFacade payload:
 *   [
 *     'SITE_A'      => [ 'enrolled_month' => int, ... ],
 *     'TOTAL'       => [ ... ],
 *     'period'      => [ 'date_from' => string|null, 'date_to' => string|null ],
 *     'site_labels' => [ 'SITE_A' => 'Full Site Name', ... ],
 *   ]
 */
class MonthlyDashboardHtmlExporter implements ExporterInterface
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

        // Extract injected meta keys — everything else is a site row
        $period     = $payload['period']      ?? [];
        $siteLabels = $payload['site_labels'] ?? [];
        $sites      = array_filter(
            $payload,
            fn($k) => !in_array($k, ['period', 'site_labels', 'label_map'], true),
            ARRAY_FILTER_USE_KEY
        );

        $html = $this->render($sites, $period, $siteLabels);

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

    private function render(array $sites, array $period = [], array $siteLabels = []): string
    {
        $currentDate = (new \DateTime())->format('Y-m-d H:i');

        $dateFrom = $period['date_from'] ?? null;
        $dateTo   = $period['date_to']   ?? null;

        // ── Period label and column header ────────────────────────────────
        // Single month (date_from and date_to are in the same month):
        //   Caption: "February 2026"
        //   Column:  "Feb 2026"
        // Multi-month or only one date set:
        //   Caption: "2026-01-01 to 2026-02-28"
        //   Column:  "In Period"

        $periodCaption   = '';
        $thisMonthHeader = 'In Period';

        if ($dateFrom && $dateTo)
        {
            try
            {
                $dtFrom = new \DateTime($dateFrom);
                $dtTo   = new \DateTime($dateTo);

                $sameMonth = $dtFrom->format('Y-m') === $dtTo->format('Y-m');

                if ($sameMonth)
                {
                    $periodCaption   = $dtFrom->format('F Y');
                    $thisMonthHeader = $dtFrom->format('M Y');
                }
                else
                {
                    $periodCaption   = $dtFrom->format('d M Y') . ' – ' . $dtTo->format('d M Y');
                    $thisMonthHeader = $dtFrom->format('d M') . '–' . $dtTo->format('d M Y');
                }
            }
            catch (\Exception $e) {}
        }
        elseif ($dateTo)
        {
            try
            {
                $dt            = new \DateTime($dateTo);
                $periodCaption = 'up to ' . $dt->format('F Y');
                $thisMonthHeader = $dt->format('M Y');
            }
            catch (\Exception $e) {}
        }

        $titleSuffix = $periodCaption ? " — {$periodCaption}" : '';

        // Separate TOTAL row from site rows
        $total    = $sites['TOTAL'] ?? null;
        $siteRows = array_filter(
            $sites,
            fn($k) => $k !== 'TOTAL' && !empty($k),
            ARRAY_FILTER_USE_KEY
        );

        $html  = $this->htmlHead();
        $html .= $this->toolbar;

        $html .= "<table class='report-table compact'>";
        $html .= "<caption>Monthly Enrollment Dashboard{$titleSuffix}"
               . "<span class='report-date'>Report generated: {$currentDate}</span></caption>";

        $html .= "<thead>
            <tr>
                <th rowspan='2'>Site</th>
                <th colspan='2'>Enrolled</th>
                <th colspan='3'>LAMA / Ref / Abs</th>
                <th colspan='3'>SAE / Death</th>
            </tr>
            <tr>
                <th>" . htmlspecialchars($thisMonthHeader) . "</th>
                <th>Cumulative</th>
                <th>" . htmlspecialchars($thisMonthHeader) . "</th>
                <th>Cumulative</th>
                <th>% of Enrolled</th>
                <th>" . htmlspecialchars($thisMonthHeader) . "</th>
                <th>Cumulative</th>
                <th>% of Enrolled</th>
            </tr>
        </thead><tbody>";

        // Site rows
        foreach ($siteRows as $site => $d)
        {
            $display = $siteLabels[$site] ?? $site;
            $html .= "<tr>"
                   . "<td>" . htmlspecialchars($display) . "</td>"
                   . "<td>" . ($d['enrolled_month']      ?? 0) . "</td>"
                   . "<td>" . ($d['enrolled_cumulative'] ?? 0) . "</td>"
                   . "<td>" . ($d['lama_month']          ?? 0) . "</td>"
                   . "<td>" . ($d['lama_cumulative']     ?? 0) . "</td>"
                   . "<td>" . ($d['lama_percent']        ?? 0) . "%</td>"
                   . "<td>" . ($d['sae_month']           ?? 0) . "</td>"
                   . "<td>" . ($d['sae_cumulative']      ?? 0) . "</td>"
                   . "<td>" . ($d['sae_percent']         ?? 0) . "%</td>"
                   . "</tr>";
        }

        // TOTAL row
        if ($total)
        {
            $html .= "<tr class='total-row'>"
                   . "<td><strong>TOTAL</strong></td>"
                   . "<td><strong>" . ($total['enrolled_month']      ?? 0) . "</strong></td>"
                   . "<td><strong>" . ($total['enrolled_cumulative'] ?? 0) . "</strong></td>"
                   . "<td><strong>" . ($total['lama_month']          ?? 0) . "</strong></td>"
                   . "<td><strong>" . ($total['lama_cumulative']     ?? 0) . "</strong></td>"
                   . "<td><strong>" . ($total['lama_percent']        ?? 0) . "%</strong></td>"
                   . "<td><strong>" . ($total['sae_month']           ?? 0) . "</strong></td>"
                   . "<td><strong>" . ($total['sae_cumulative']      ?? 0) . "</strong></td>"
                   . "<td><strong>" . ($total['sae_percent']         ?? 0) . "%</strong></td>"
                   . "</tr>";
        }

        $html .= "</tbody></table>";

        // ── Enrolment vs Target section ──────────────────────────────────────
        $html .= $this->renderTargetSection($siteRows, $siteLabels, $periodCaption);

        $html .= "</body></html>";

        return $html;
    }

    /**
     * Charts + table comparing enrolment to target — both a period view and a
     * cumulative view. $siteRows is the per-site array (TOTAL separated by caller).
     */
    private function renderTargetSection(array $siteRows, array $siteLabels, string $periodCaption): string
    {
        $rows = [];
        foreach ($siteRows as $code => $d)
        {
            if (($d['target_monthly'] ?? 0) <= 0) continue;
            $rows[] = [
                'label'       => $siteLabels[$code] ?? $code,
                'enrolled'    => (int)($d['enrolled_month']      ?? 0),
                'target'      => (float)($d['target_period']     ?? 0),
                'pct'         => $d['target_pct']     ?? null,
                'gap'         => $d['target_gap']     ?? 0,
                'cum'         => (int)($d['enrolled_cumulative'] ?? 0),
                'cum_target'  => (float)($d['target_cumulative'] ?? 0),
                'cum_pct'     => $d['target_cum_pct'] ?? null,
                'cum_gap'     => $d['target_cum_gap'] ?? 0,
                'first_coll'  => $d['first_collection'] ?? null,
                'site_start'  => $d['site_start']       ?? null,
            ];
        }
        if (!$rows) return '';

        $labels   = array_map(fn($r) => $r['label'],      $rows);
        $enrolled = array_map(fn($r) => $r['enrolled'],   $rows);
        $targets  = array_map(fn($r) => $r['target'],     $rows);
        $pcts     = array_map(fn($r) => $r['pct'],        $rows);
        $cum      = array_map(fn($r) => $r['cum'],        $rows);
        $cumTgt   = array_map(fn($r) => $r['cum_target'], $rows);
        $cumPcts  = array_map(fn($r) => $r['cum_pct'],    $rows);

        $jsLabels   = json_encode($labels, JSON_UNESCAPED_UNICODE);
        $num = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.');

        // Combined table: period + cumulative + both dates.
        $rowsHtml = '';
        foreach ($rows as $r)
        {
            $cls    = $r['pct']     === null ? '' : ($r['pct']     >= 100 ? 'ok' : ($r['pct']     >= 70 ? 'warn' : 'bad'));
            $cumCls = $r['cum_pct'] === null ? '' : ($r['cum_pct'] >= 100 ? 'ok' : ($r['cum_pct'] >= 70 ? 'warn' : 'bad'));
            $pctTxt    = $r['pct']     === null ? '—' : $r['pct'] . '%';
            $cumPctTxt = $r['cum_pct'] === null ? '—' : $r['cum_pct'] . '%';
            $gapTxt    = ($r['gap']     > 0 ? '+' : '') . $num($r['gap']);
            $cumGapTxt = ($r['cum_gap'] > 0 ? '+' : '') . $num($r['cum_gap']);

            $rowsHtml .= "<tr>"
                . "<td style='text-align:left'>" . htmlspecialchars($r['label']) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['first_coll'] ?? '—')) . "</td>"
                . "<td>" . htmlspecialchars((string)($r['site_start'] ?? '—')) . "</td>"
                . "<td>" . $r['enrolled'] . "</td>"
                . "<td>" . $num($r['target']) . "</td>"
                . "<td class='{$cls}'>" . $pctTxt . "</td>"
                . "<td>" . $gapTxt . "</td>"
                . "<td>" . $r['cum'] . "</td>"
                . "<td>" . $num($r['cum_target']) . "</td>"
                . "<td class='{$cumCls}'>" . $cumPctTxt . "</td>"
                . "<td>" . $cumGapTxt . "</td>"
                . "</tr>";
        }

        $cap = $periodCaption ? " ({$periodCaption})" : '';

        return "
<h2 style='width:92%;margin:28px auto 8px;'>Enrolment vs Target{$cap}</h2>
<p style='width:92%;margin:0 auto 12px;color:#6b7280;font-size:13px'>
  Monthly targets scaled to the period (left chart) and accumulated from each site's
  first data-collection date (right chart). Attainment colours:
  <span style='color:#15803d'>&#9632; &ge;100%</span> &nbsp;
  <span style='color:#b45309'>&#9632; 70–99%</span> &nbsp;
  <span style='color:#b91c1c'>&#9632; &lt;70%</span>
</p>

<div style='width:92%;margin:0 auto 8px;display:flex;gap:2%;flex-wrap:wrap'>
  <div style='flex:1;min-width:320px'>
    <h4 style='text-align:center;margin:4px 0;color:#374151'>This Period</h4>
    <canvas id='targetBarPeriod' height='150'></canvas>
  </div>
  <div style='flex:1;min-width:320px'>
    <h4 style='text-align:center;margin:4px 0;color:#374151'>Cumulative (from first data collection)</h4>
    <canvas id='targetBarCum' height='150'></canvas>
  </div>
</div>

<table class='report-table compact' style='width:92%;margin:12px auto 28px;'>
  <thead>
    <tr>
      <th rowspan='2' style='text-align:left'>Site</th>
      <th rowspan='2'>First Data<br>Collection</th>
      <th rowspan='2'>Site Start</th>
      <th colspan='4'>This Period</th>
      <th colspan='4'>Cumulative</th>
    </tr>
    <tr>
      <th>Enrolled</th><th>Target</th><th>Attained</th><th>Gap</th>
      <th>Enrolled</th><th>Target</th><th>Attained</th><th>Gap</th>
    </tr>
  </thead>
  <tbody>{$rowsHtml}</tbody>
</table>

<style>
  .report-table td.ok   { background:#f0fdf4; color:#15803d; font-weight:600; }
  .report-table td.warn { background:#fffbeb; color:#b45309; font-weight:600; }
  .report-table td.bad  { background:#fef2f2; color:#b91c1c; font-weight:600; }
</style>

<script src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js'></script>
<script>
(function(){
  var labels = {$jsLabels};
  function colour(p){ if(p===null) return '#9ca3af'; if(p>=100) return '#16a34a'; if(p>=70) return '#d97706'; return '#dc2626'; }
  function mk(canvasId, enrolled, targets, pcts, enrolledLabel){
    new Chart(document.getElementById(canvasId).getContext('2d'), {
      type:'bar',
      data:{ labels:labels, datasets:[
        { label:enrolledLabel, data:enrolled, backgroundColor:pcts.map(colour), order:2 },
        { label:'Target', data:targets, type:'line', borderColor:'#111827', borderWidth:2,
          pointRadius:0, borderDash:[6,4], fill:false, order:1 }
      ]},
      options:{ responsive:true,
        plugins:{ legend:{position:'bottom'},
          tooltip:{ callbacks:{ afterBody:function(items){ var i=items[0].dataIndex;
            return 'Attained: ' + (pcts[i]===null?'—':pcts[i]+'%'); } } } },
        scales:{ x:{ticks:{autoSkip:false,maxRotation:40,minRotation:0,font:{size:10}}},
                 y:{beginAtZero:true,ticks:{precision:0}} } }
    });
  }
  mk('targetBarPeriod', " . json_encode($enrolled) . ", " . json_encode($targets) . ", " . json_encode($pcts) . ", 'Enrolled (period)');
  mk('targetBarCum',    " . json_encode($cum) . ", " . json_encode($cumTgt) . ", " . json_encode($cumPcts) . ", 'Enrolled (cumulative)');
})();
</script>
";
    }

    private function htmlHead(): string
    {
        if ($this->inline)
        {
            // Walk up from shared-lib/src/Domain/Export/ to find the CSS
            $cssFile  = realpath(__DIR__ . '/../../../../reporting-engine/public/assets/css/emollient_eligible.css');
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
<title>Monthly Enrollment Dashboard</title>
{$styleTag}
<style>
    .report-table { width: 90%; }
    .report-table thead th { text-align: center; }
    .report-table tbody td:first-child { width: 16%; }
    .report-table tbody td:not(:first-child) { width: 9%; text-align: center; }
</style>
</head>
<body>";
    }
}

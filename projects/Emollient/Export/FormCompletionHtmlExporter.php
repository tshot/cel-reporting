<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * FormCompletionHtmlExporter
 *
 * Three-page HTML report for form completion status:
 *
 *  Page 1 — Per-participant detail
 *  Page 2 — Site summary
 *  Page 3 — Day-level frequency
 *
 * Section export (exportSection):
 *   'participants'  — Page 1 standalone
 *   'site-summary'  — Page 2 standalone
 *   'day-frequency' — Page 3 standalone
 */
class FormCompletionHtmlExporter implements ExporterInterface
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
        $html    = $this->render($payload);

        if ($outputPath)
            file_put_contents($outputPath, $html);
        else
        {
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }

    // ── Section export ────────────────────────────────────────────────────────

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool   $inline      = false,
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
        $participants   = $payload['participants']    ?? [];
        $siteSummary    = $payload['site_summary']    ?? [];
        $dayFrequency   = $payload['day_frequency']   ?? [];
        $formName       = $payload['form_name']       ?? '';
        $maxDays        = $payload['max_days']        ?? 28;
        $sessionsPerDay = $payload['sessions_per_day'] ?? 1;
        $siteLabels     = $payload['site_labels']     ?? [];
        $period         = $payload['period']          ?? [];
        $hasBlocked     = $payload['has_blocked']     ?? false;
        $hasPartial     = $payload['has_partial']     ?? false;

        $periodLabel = $this->periodLabel($period);
        $currentDate = (new \DateTime())->format('Y-m-d H:i');
        $formTitle   = ucwords(str_replace('_', ' ', $formName));
        if ($sessionsPerDay > 1) $formTitle .= " ({$sessionsPerDay}x/day)";

        switch ($section) {
            case 'participants':
                $title   = "{$formTitle} — Participant Detail";
                $content = $this->renderPage1($participants, $siteLabels, $periodLabel, $currentDate, $formTitle, $hasBlocked, $hasPartial, $sessionsPerDay);
                $colDefs = [];
                break;
            case 'site-summary':
                $title   = "{$formTitle} — Site Summary";
                $content = $this->renderPage2($siteSummary, $siteLabels, $periodLabel, $currentDate, $formTitle, $hasBlocked, $hasPartial);
                $colDefs = [];
                break;
            case 'day-frequency':
                $title   = "{$formTitle} — Day-level Completion Frequency";
                $content = $this->renderPage3($dayFrequency, $maxDays, $periodLabel, $currentDate, $formTitle, $hasBlocked, $hasPartial);
                $colDefs = [];
                break;
            default:
                $title   = 'Unknown section';
                $content = '<p>Unknown section: ' . htmlspecialchars($section) . '</p>';
                $colDefs = [];
        }

        // ── CSS ───────────────────────────────────────────────────────────────
        $cssFile   = realpath(__DIR__ . '/../../../reporting-engine/public/assets/css/emollient_eligible.css');
        $reportCss = ($cssFile && file_exists($cssFile)) ? file_get_contents($cssFile) : '';

        if ($inline) {
            $uiCssFile = realpath(__DIR__ . '/../../../reporting-engine/public/assets/css/section-export.css');
            $uiJsFile  = realpath(__DIR__ . '/../../../reporting-engine/public/assets/js/section-export.js');
            $uiCss     = ($uiCssFile && file_exists($uiCssFile)) ? file_get_contents($uiCssFile) : '';
            $uiJs      = ($uiJsFile  && file_exists($uiJsFile))  ? file_get_contents($uiJsFile)  : '';
            $uiCssTag  = "<style>\n{$uiCss}\n</style>";
            $uiJsTag   = "<script>\n{$uiJs}\n</script>";
        } else {
            $uiCssTag = "<link rel='stylesheet' href='/assets/css/section-export.css'>";
            $uiJsTag  = "<script src='/assets/js/section-export.js'></script>";
        }

        $colDefsJson = json_encode($colDefs);

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
window.SECTION_HAS_COLUMNS = false;
window.SECTION_COL_DEFS    = <?= $colDefsJson ?>;
window.SECTION_NAME        = <?= json_encode($section) ?>;
</script>
<?= $uiJsTag ?>
</body>
</html>
<?php
        return ob_get_clean();
    }

    // ── Full report render ────────────────────────────────────────────────────

    private function render(array $payload): string
    {
        $participants   = $payload['participants']    ?? [];
        $siteSummary    = $payload['site_summary']    ?? [];
        $dayFrequency   = $payload['day_frequency']   ?? [];
        $formName       = $payload['form_name']       ?? 'daily_clinical_monitoring';
        $maxDays        = $payload['max_days']        ?? 28;
        $sessionsPerDay = $payload['sessions_per_day'] ?? 1;
        $siteLabels     = $payload['site_labels']     ?? [];
        $period         = $payload['period']          ?? [];
        $hasBlocked     = $payload['has_blocked']     ?? false;
        $hasPartial     = $payload['has_partial']     ?? false;

        $currentDate = (new \DateTime())->format('Y-m-d H:i');
        $periodLabel = $this->periodLabel($period);

        $formTitle = ucwords(str_replace('_', ' ', $formName));
        if ($sessionsPerDay > 1)
            $formTitle .= " ({$sessionsPerDay}x/day)";
        $title = "{$formTitle} — Completion Report";

        $html  = $this->htmlHead($title);
        $html .= $this->toolbar;

        $html .= $this->renderPage1($participants, $siteLabels, $periodLabel, $currentDate, $formTitle, $hasBlocked, $hasPartial, $sessionsPerDay);
        $html .= "<div class='section-gap'></div>";
        $html .= $this->renderPage2($siteSummary, $siteLabels, $periodLabel, $currentDate, $formTitle, $hasBlocked, $hasPartial);
        $html .= "<div class='section-gap'></div>";
        $html .= $this->renderPage3($dayFrequency, $maxDays, $periodLabel, $currentDate, $formTitle, $hasBlocked, $hasPartial);

        $html .= '</body></html>';
        return $html;
    }

    // ── Page 1: Per-participant detail ────────────────────────────────────────

    private function renderPage1(
        array  $participants,
        array  $siteLabels,
        string $periodLabel,
        string $currentDate,
        string $formTitle,
        bool   $hasBlocked    = false,
        bool   $hasPartial    = false,
        int    $sessionsPerDay = 1
    ): string {
        $rows = '';
        foreach ($participants as $p)
        {
            $site          = $siteLabels[$p['site']] ?? $p['site'];
            $pct       = $p['pct']        !== null ? $p['pct']        . '%' : '—';
            $pctActual = $p['pct_actual'] !== null ? $p['pct_actual'] . '%' : '—';
            $ragCls    = $this->ragClass($p['pct']);
            $ragActual = $p['pct_actual'] !== null && $p['pct_actual'] > 100
                ? 'style=\'text-align:center;font-weight:bold;color:#1565c0\''
                : "style='text-align:center;font-weight:bold'";
            $endLabel      = $this->endReasonLabel($p['end_reason']);
            $enrollDate    = $p['enrollment_date'] ?? null;
            $stopDate      = $p['stop_date']       ?? null;

            // Missing cell: for multi-session forms show incomplete instance refs (D1#2...),
            // for single-session forms show missing day numbers.
            if ($sessionsPerDay > 1) {
                $incomplete = $p['incomplete_instances'] ?? [];
                $missing = !empty($incomplete)
                    ? '<span style="font-size:11px;color:#c62828">' . implode(', ', $incomplete) . '</span>'
                    : '<span style="color:#2e7d32">—</span>';
            } else {
                $missing = !empty($p['missing_days'])
                    ? implode(', ', $p['missing_days'])
                    : '<span style="color:#2e7d32">—</span>';
            }

            $partialCell = $hasPartial
                ? "<td style='text-align:center;color:#f57f17'>" . ($p['partial'] ?: '—') . "</td>"
                : '';
            // Parent-not-done days: list the actual day numbers (matches
            // the "Missing Days" cell style) so the site can act on them.
            $blockedCell = $hasBlocked
                ? "<td style='font-size:12px;color:#777'>"
                  . (!empty($p['blocked_days'])
                        ? implode(', ', $p['blocked_days'])
                        : '<span style="color:#2e7d32">—</span>')
                  . "</td>"
                : '';

            $rows .= "<tr>"
                   . "<td class='nowrap'>{$p['record_id']}</td>"
                   . "<td>" . htmlspecialchars($site) . "</td>"
                   . "<td class='nowrap'>" . htmlspecialchars($p['arm']) . "</td>"
                   . "<td class='nowrap'>{$p['dob']}</td>"
                   . "<td class='nowrap' style='color:#555'>{$enrollDate}</td>"
                   . "<td style='text-align:center' class='nowrap'>" . ($p['end_day_display'] ?? $p['end_day']) . "</td>"
                   . "<td class='nowrap'>" . htmlspecialchars($endLabel) . "</td>"
                   . "<td class='nowrap' style='color:#555'>{$stopDate}</td>"
                   . "<td style='text-align:center' class='nowrap'>{$p['expected']}</td>"
                   . "<td style='text-align:center' class='nowrap'>{$p['completed']}</td>"
                   . $partialCell
                   . $blockedCell
                   . "<td class='{$ragCls} nowrap' style='text-align:center;font-weight:bold'>{$pct}</td>"
                   . "<td class='nowrap' {$ragActual}>{$pctActual}</td>"
                   . "<td style='font-size:12px;color:#c62828'>{$missing}</td>"
                   . "</tr>";
        }

        $partialHeader = $hasPartial ? "<th style='white-space:nowrap'>Partial<br><span style='font-weight:400;font-size:10px'>days</span></th>" : '';
        $blockedHeader = $hasBlocked ? "<th style='white-space:nowrap'>Parent<br><span style='font-weight:400;font-size:10px'>Not Done</span></th>" : '';
        $expectedLabel = $sessionsPerDay > 1 ? 'Expected<br><span style=\'font-weight:400;font-size:10px\'>sessions</span>' : 'Expected<br><span style=\'font-weight:400;font-size:10px\'>days</span>';
        $completedLabel = $sessionsPerDay > 1 ? 'Completed<br><span style=\'font-weight:400;font-size:10px\'>sessions</span>' : 'Completed<br><span style=\'font-weight:400;font-size:10px\'>days</span>';
        $missingLabel  = $sessionsPerDay > 1 ? 'Missing / Pending<br><span style=\'font-weight:400;font-size:10px\'>instances (DX#Y)</span>' : 'Missing Days';
        $count = count($participants);

        return "
<table class='report-table completion-table'>
  <caption>{$formTitle} — Participant Detail
    <span class='report-period'>{$periodLabel}</span>
    <span class='report-date'>N = {$count} &nbsp;|&nbsp; {$currentDate}</span>
  </caption>
  <thead>
    <tr>
      <th style='white-space:nowrap'>ID</th>
      <th style='white-space:nowrap'>Site</th>
      <th style='white-space:nowrap'>Arm</th>
      <th style='white-space:nowrap'>DOB</th>
      <th style='white-space:nowrap'>Enrolment<br><span style='font-weight:400;font-size:10px'>date</span></th>
      <th style='white-space:nowrap'>Days Due</th>
      <th style='white-space:nowrap'>End Reason</th>
      <th style='white-space:nowrap'>Stop Date</th>
      <th style='white-space:nowrap'>{$expectedLabel}</th>
      <th style='white-space:nowrap'>{$completedLabel}</th>
      {$partialHeader}
      {$blockedHeader}
      <th style='white-space:nowrap'>%<br><span style='font-weight:400;font-size:10px'>normalised</span></th>
      <th style='white-space:nowrap'>Actual&nbsp;%</th>
      <th style='white-space:nowrap'>{$missingLabel}</th>
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>";
    }

    // ── Page 2: Site summary ──────────────────────────────────────────────────

    private function renderPage2(
        array  $siteSummary,
        array  $siteLabels,
        string $periodLabel,
        string $currentDate,
        string $formTitle,
        bool   $hasBlocked = false,
        bool   $hasPartial = false
    ): string {
        $total   = $siteSummary['TOTAL'] ?? null;
        $sites   = array_filter($siteSummary, fn($k) => $k !== 'TOTAL', ARRAY_FILTER_USE_KEY);

        $reasons = ['day28' => 'Day 28', 'discharge' => 'Discharged',
                    'deviation' => 'Protocol Dev.', 'withdrawal' => 'Withdrawn',
                    'sae' => 'SAE', 'ongoing' => 'Ongoing'];

        $rows = '';
        foreach ($sites as $code => $s)
        {
            $site   = $siteLabels[$code] ?? $code;
            $pct       = $s['pct']        !== null ? $s['pct']        . '%' : '—';
            $pctActual = $s['pct_actual'] !== null ? $s['pct_actual'] . '%' : '—';
            $ragCls    = $this->ragClass($s['pct']);

            $reasonCells = '';
            foreach ($reasons as $key => $label)
                $reasonCells .= "<td style='text-align:center'>" . ($s['end_reasons'][$key] ?? 0) . "</td>";

            $blockedCell = $hasBlocked
                ? "<td style='text-align:center;color:#888'>" . ($s['blocked'] ?: '—') . "</td>"
                : '';
            $partialCell = $hasPartial
                ? "<td style='text-align:center;color:#f57f17'>" . ($s['partial'] ?: '—') . "</td>"
                : '';

            $rows .= "<tr>"
                   . "<td>" . htmlspecialchars($site) . "</td>"
                   . "<td style='text-align:center'>{$s['count']}</td>"
                   . "<td style='text-align:center'>{$s['expected']}</td>"
                   . "<td style='text-align:center'>{$s['completed']}</td>"
                   . $partialCell
                   . $blockedCell
                   . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pct}</td>"
                   . "<td style='text-align:center;font-weight:bold;color:#1565c0'>{$pctActual}</td>"
                   . $reasonCells
                   . "</tr>";
        }

        if ($total)
        {
            $pct       = $total['pct']        !== null ? $total['pct']        . '%' : '—';
            $pctActual = $total['pct_actual'] !== null ? $total['pct_actual'] . '%' : '—';
            $ragCls    = $this->ragClass($total['pct']);
            $reasonCells = '';
            foreach ($reasons as $key => $label)
                $reasonCells .= "<td style='text-align:center'><strong>" . ($total['end_reasons'][$key] ?? 0) . "</strong></td>";

            $blockedCell = $hasBlocked
                ? "<td style='text-align:center;color:#888'><strong>" . ($total['blocked'] ?: '—') . "</strong></td>"
                : '';
            $partialCell = $hasPartial
                ? "<td style='text-align:center;color:#f57f17'><strong>" . ($total['partial'] ?: '—') . "</strong></td>"
                : '';

            $rows .= "<tr class='total-row'>"
                   . "<td><strong>TOTAL</strong></td>"
                   . "<td style='text-align:center'><strong>{$total['count']}</strong></td>"
                   . "<td style='text-align:center'><strong>{$total['expected']}</strong></td>"
                   . "<td style='text-align:center'><strong>{$total['completed']}</strong></td>"
                   . $partialCell
                   . $blockedCell
                   . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pct}</td>"
                   . "<td style='text-align:center;font-weight:bold;color:#1565c0'>{$pctActual}</td>"
                   . $reasonCells
                   . "</tr>";
        }

        $blockedHeader  = $hasBlocked ? "<th style='white-space:nowrap'>Parent<br><span style='font-weight:400;font-size:10px'>Not Done</span></th>" : '';
        $partialHeader  = $hasPartial ? "<th style='white-space:nowrap'>Partial</th>" : '';
        $reasonHeaders  = '';
        foreach ($reasons as $label)
            $reasonHeaders .= "<th style='white-space:nowrap'>{$label}</th>";

        $legend  = "<p class='rag-legend'>";
        $legend .= "<span class='rag-green'>&#9632;</span> &ge;90%&nbsp;&nbsp;";
        $legend .= "<span class='rag-amber'>&#9632;</span> 75&ndash;89%&nbsp;&nbsp;";
        $legend .= "<span class='rag-red'>&#9632;</span> &lt;75%";
        if ($hasPartial)
            $legend .= "&nbsp;&nbsp;<span style='color:#f57f17'>&#9632;</span> Partial (some sessions complete)";
        $legend .= "</p>";

        return "
<table class='report-table completion-table'>
  <caption>{$formTitle} — Site Summary
    <span class='report-period'>{$periodLabel}</span>
    <span class='report-date'>{$currentDate}</span>
  </caption>
  <thead>
    <tr>
      <th style='white-space:nowrap'>Site</th>
      <th style='white-space:nowrap'>Participants</th>
      <th style='white-space:nowrap'>Expected</th>
      <th style='white-space:nowrap'>Completed</th>
      {$partialHeader}
      {$blockedHeader}
      <th style='white-space:nowrap'>%<br><span style='font-weight:400;font-size:10px'>normalised</span></th>
      <th style='white-space:nowrap'>Actual&nbsp;%</th>
      {$reasonHeaders}
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>
{$legend}";
    }

    // ── Page 3: Day-level frequency ───────────────────────────────────────────

    private function renderPage3(
        array  $dayFrequency,
        int    $maxDays,
        string $periodLabel,
        string $currentDate,
        string $formTitle,
        bool   $hasBlocked = false,
        bool   $hasPartial = false
    ): string {
        $rows = '';
        foreach ($dayFrequency as $d => $f)
        {
            if ($f['due'] === 0 && $f['blocked'] === 0) continue;

            $pct       = $f['pct']        !== null ? $f['pct']        . '%' : '—';
            $pctActual = $f['pct_actual'] !== null ? $f['pct_actual'] . '%' : '—';
            $ragCls    = $this->ragClass($f['pct']);
            $missing  = $f['due'] - $f['completed'] - ($f['partial'] ?? 0);
            $barWidth  = $f['pct'] !== null ? (int)$f['pct'] : 0;
            $barColour = $barWidth >= 90 ? '#2e7d32' : ($barWidth >= 75 ? '#f57f17' : '#c62828');
            $bar = "<div style='background:#eee;border-radius:3px;height:10px;width:100%'>"
                 . "<div style='background:{$barColour};border-radius:3px;height:10px;width:{$barWidth}%'></div>"
                 . "</div>";

            $partialCell = $hasPartial
                ? "<td style='text-align:center;color:#f57f17'>" . ($f['partial'] ?? 0 ?: '—') . "</td>"
                : '';
            $blockedCell = $hasBlocked
                ? "<td style='text-align:center;color:#888'>" . ($f['blocked'] ?: '—') . "</td>"
                : '';

            $rows .= "<tr>"
                   . "<td style='text-align:center;white-space:nowrap'>Day {$d}</td>"
                   . "<td style='text-align:center'>{$f['due']}</td>"
                   . "<td style='text-align:center'>{$f['completed']}</td>"
                   . "<td style='text-align:center;color:#c62828'>{$missing}</td>"
                   . $partialCell
                   . $blockedCell
                   . "<td class='{$ragCls}' style='text-align:center;font-weight:bold;white-space:nowrap'>{$pct}</td>"
                   . "<td style='text-align:center;font-weight:bold;white-space:nowrap;color:#1565c0'>{$pctActual}</td>"
                   . "<td style='padding:4px 8px'>{$bar}</td>"
                   . "</tr>";
        }

        $partialHeader = $hasPartial ? "<th style='white-space:nowrap'>Partial</th>" : '';
        $blockedHeader = $hasBlocked ? "<th style='white-space:nowrap'>Parent<br><span style='font-weight:400;font-size:10px'>Not Done</span></th>" : '';

        return "
<table class='report-table completion-table'>
  <caption>{$formTitle} — Day-level Completion Frequency
    <span class='report-period'>{$periodLabel}</span>
    <span class='report-date'>{$currentDate}</span>
  </caption>
  <thead>
    <tr>
      <th style='white-space:nowrap'>Day</th>
      <th style='white-space:nowrap'>Due</th>
      <th style='white-space:nowrap'>Completed</th>
      <th style='white-space:nowrap'>Missing</th>
      {$partialHeader}
      {$blockedHeader}
      <th style='white-space:nowrap'>%<br><span style='font-weight:400;font-size:10px'>normalised</span></th>
      <th style='white-space:nowrap'>Actual&nbsp;%</th>
      <th style='width:30%;white-space:nowrap'>Completion</th>
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>";
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function ragClass(?float $pct): string
    {
        if ($pct === null)  return '';
        if ($pct >= 90)     return 'rag-green';
        if ($pct >= 75)     return 'rag-amber';
        return 'rag-red';
    }

    private function endReasonLabel(string $reason): string
    {
        return match($reason)
        {
            'day28'      => 'Day 28 complete',
            'discharge'  => 'Discharged',
            'deviation'  => 'Protocol deviation',
            'withdrawal' => 'Withdrawn',
            'sae'        => 'SAE',
            'ongoing'    => 'Ongoing',
            default      => $reason,
        };
    }

    private function periodLabel(array $period): string
    {
        $from = $period['date_from'] ?? null;
        $to   = $period['date_to']   ?? null;
        if ($from && $to)   return "Period: {$from} to {$to}";
        if ($to)            return "Period: up to {$to}";
        if ($from)          return "Period: from {$from}";
        return '';
    }

    private function htmlHead(string $title): string
    {
        if ($this->inline)
        {
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
<title>" . htmlspecialchars($title) . "</title>
{$styleTag}
</head>
<body>";
    }
}

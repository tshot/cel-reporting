<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * OneTimeFormCompletionHtmlExporter
 *
 * Two-page HTML report for forms that are filled exactly once per participant.
 *
 *  Page 1 — Per-participant detail
 *           One row per enrolled participant: record ID, site, arm, status
 *           (Complete / Missing / Not Due) and reason if not due.
 *
 *  Page 2 — Site summary
 *           Count and % of complete/missing per site with RAG colour coding.
 */
class OneTimeFormCompletionHtmlExporter implements ExporterInterface
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
        else {
            header('Content-Type: text/html; charset=UTF-8');
            echo $html;
        }
    }

    private function render(array $payload): string
    {
        $participants = $payload['participants'] ?? [];
        $siteSummary  = $payload['site_summary'] ?? [];
        $formName     = $payload['form_name']    ?? '';
        $siteLabels   = $payload['site_labels']  ?? [];
        $period       = $payload['period']       ?? [];

        $currentDate = (new \DateTime())->format('Y-m-d H:i');
        $periodLabel = $this->periodLabel($period);
        $formTitle   = ucwords(str_replace('_', ' ', $formName));
        $title       = "{$formTitle} — Completion Report";

        $html  = $this->htmlHead($title);
        $html .= $this->toolbar;
        $alwaysDue = (bool)($payload['always_due'] ?? false);
        $html .= $this->renderPage1($participants, $siteLabels, $periodLabel, $currentDate, $formTitle, $alwaysDue);
        $html .= "<div class='section-gap'></div>";
        $html .= $this->renderPage2($siteSummary, $siteLabels, $periodLabel, $currentDate, $formTitle);
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
        bool   $alwaysDue = false
    ): string {
        $rows  = '';
        $count = count($participants);

        foreach ($participants as $p) {
            $site      = htmlspecialchars($siteLabels[$p['site']] ?? $p['site']);
            $arm       = htmlspecialchars($p['arm'] ?? '—');
            $status    = $p['status'];
            $notDueWhy = $this->notDueReason($p);

            [$statusLabel, $statusClass] = match($status) {
                'complete' => ['Complete',  'rag-green'],
                'missing'  => ['Missing',   'rag-red'],
                'not_due'  => ['Not Due',   ''],
                default    => [$status,     ''],
            };

            $enrDate   = htmlspecialchars($p['enr_date']     ?? '');
            $closeReas = htmlspecialchars($p['close_reason'] ?? '');

            // For always_due: show "Discharged" tag on missing rows + reason on not_due rows.
            // For standard:   show single "Reason if Not Due" cell.
            if ($alwaysDue) {
                $reasonCell = ($status === 'not_due')
                    ? "<td style='font-size:12px;color:#888'>{$notDueWhy}</td>"
                    : "<td style='font-size:12px;color:#888'>{$closeReas}</td>";
            } else {
                $reasonCell = "<td style='font-size:12px;color:#888'>{$notDueWhy}</td>";
            }

            $rows .= "<tr>"
                   . "<td>{$p['record_id']}</td>"
                   . "<td>{$site}</td>"
                   . "<td>{$arm}</td>"
                   . "<td class='nowrap' style='color:#555;font-size:12px'>" . htmlspecialchars($p['dob'] ?? '') . "</td>"
                   . "<td style='text-align:center;color:#555;font-size:12px'>" . ($p['age_days'] ?? '') . "</td>"
                   . ($alwaysDue ? "<td class='nowrap' style='color:#555;font-size:12px'>{$enrDate}</td>" : '')
                   . "<td class='{$statusClass}' style='text-align:center;font-weight:bold'>{$statusLabel}</td>"
                   . $reasonCell
                   . "</tr>";
        }

        return "
<table class='report-table completion-table'>
  <caption>{$formTitle} — Participant Detail
    <span class='report-period'>{$periodLabel}</span>
    <span class='report-date'>N = {$count} &nbsp;|&nbsp; {$currentDate}</span>
  </caption>
  <thead>
    <tr>
      <th>ID</th>
      <th>Site</th>
      <th>Arm</th>
      <th>DOB</th>
      <th title='Age in days'>Age</th>
      " . ($alwaysDue ? "<th>Enrolled</th>" : '') . "
      <th>Status</th>
      <th>" . ($alwaysDue ? 'Reason / Case Status' : 'Reason if Not Due') . "</th>
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
        string $formTitle
    ): string {
        $total = $siteSummary['TOTAL'] ?? null;
        $sites = array_filter($siteSummary, fn($k) => $k !== 'TOTAL', ARRAY_FILTER_USE_KEY);

        $rows = '';
        foreach ($sites as $code => $s) {
            $site   = htmlspecialchars($siteLabels[$code] ?? $code);
            $pct    = $s['pct'] !== null ? $s['pct'] . '%' : '—';
            $ragCls = $this->ragClass($s['pct']);

            $rows .= "<tr>"
                   . "<td>{$site}</td>"
                   . "<td style='text-align:center'>{$s['count']}</td>"
                   . "<td style='text-align:center;color:#2e7d32'>{$s['complete']}</td>"
                   . "<td style='text-align:center;color:#c62828'>{$s['missing']}</td>"
                   . "<td style='text-align:center;color:#c62828;font-weight:bold'>" . ($s['pending'] ?? 0) . "</td>"
                   . "<td style='text-align:center;color:#888'>{$s['not_due']}</td>"
                   . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pct}</td>"
                   . "</tr>";
        }

        if ($total) {
            $pct    = $total['pct'] !== null ? $total['pct'] . '%' : '—';
            $ragCls = $this->ragClass($total['pct']);

            $rows .= "<tr class='total-row'>"
                   . "<td><strong>TOTAL</strong></td>"
                   . "<td style='text-align:center'><strong>{$total['count']}</strong></td>"
                   . "<td style='text-align:center;color:#2e7d32'><strong>{$total['complete']}</strong></td>"
                   . "<td style='text-align:center;color:#c62828'><strong>{$total['missing']}</strong></td>"
                   . "<td style='text-align:center;color:#c62828'><strong>" . ($total['pending'] ?? 0) . "</strong></td>"
                   . "<td style='text-align:center;color:#888'><strong>{$total['not_due']}</strong></td>"
                   . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pct}</td>"
                   . "</tr>";
        }

        $legend  = "<p class='rag-legend'>";
        $legend .= "<span class='rag-green'>&#9632;</span> &ge;90%&nbsp;&nbsp;";
        $legend .= "<span class='rag-amber'>&#9632;</span> 75&ndash;89%&nbsp;&nbsp;";
        $legend .= "<span class='rag-red'>&#9632;</span> &lt;75%";
        $legend .= "</p>";

        return "
<table class='report-table completion-table'>
  <caption>{$formTitle} — Site Summary
    <span class='report-period'>{$periodLabel}</span>
    <span class='report-date'>{$currentDate}</span>
  </caption>
  <thead>
    <tr>
      <th>Site</th>
      <th>Participants</th>
      <th>Complete</th>
      <th>Missing</th>
      <th title='Missing and past the age at which the form becomes due'>Pending</th>
      <th>Not Due</th>
      <th>% Complete</th>
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>
{$legend}";
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function notDueReason(array $p): string
    {
        if ($p['status'] !== 'not_due') return '';
        $reasons = [];
        if (!empty($p['discharged'])) $reasons[] = 'Discharged';
        if (!empty($p['pd']))         $reasons[] = 'Protocol deviation';
        if (!empty($p['sw']))         $reasons[] = 'Withdrawn';
        return implode(', ', $reasons) ?: 'Not consented';
    }

    private function ragClass(?float $pct): string
    {
        if ($pct === null) return '';
        if ($pct >= 90)   return 'rag-green';
        if ($pct >= 75)   return 'rag-amber';
        return 'rag-red';
    }

    private function periodLabel(array $period): string
    {
        $from = $period['date_from'] ?? null;
        $to   = $period['date_to']   ?? null;
        if ($from && $to) return "Period: {$from} to {$to}";
        if ($to)          return "Period: up to {$to}";
        if ($from)        return "Period: from {$from}";
        return '';
    }

    private function htmlHead(string $title): string
    {
        if ($this->inline) {
            $cssFile  = realpath(__DIR__ . '/../../../reporting-engine/public/assets/css/emollient_eligible.css');
            $css      = ($cssFile && file_exists($cssFile)) ? file_get_contents($cssFile) : '';
            $styleTag = "<style>\n{$css}\n</style>";
        } else {
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

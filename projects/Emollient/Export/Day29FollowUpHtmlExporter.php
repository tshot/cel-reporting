<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * Day29FollowUpHtmlExporter
 *
 * Two sections:
 *   Section 1 — Site summary  (completed / pending / not-due counts and %)
 *   Section 2 — Per-baby detail table with filter
 */
class Day29FollowUpHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    private const PATH_CSS = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->cssPath = $cssPath ?: '/assets/css/emollient_eligible.css';
        $this->toolbar = $toolbar;
    }

    // =========================================================================
    // ExporterInterface
    // =========================================================================

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->render($payload);
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
        $this->export($data, $outputPath);
    }

    // =========================================================================
    // Rendering
    // =========================================================================

    private function render(array $payload): string
    {
        $html  = $this->buildHead();
        $html .= "<body>\n";
        if ($this->toolbar) $html .= $this->toolbar;
        $html .= $this->renderSummaryTable($payload);
        $html .= "<div class='section-gap'></div>";
        $html .= $this->renderBabyTable($payload);
        $html .= "\n</body></html>";
        return $html;
    }

    // =========================================================================
    // Section 1 — Site summary
    // =========================================================================

    private function renderSummaryTable(array $payload): string
    {
        $bySite      = $payload['by_site']      ?? [];
        $siteLabels  = $payload['site_labels']  ?? [];
        $periodLabel = $payload['period_label'] ?? '';
        $periodSpan  = $periodLabel
            ? "<span class='report-period'>" . htmlspecialchars($periodLabel) . "</span>"
            : '';
        $date = date('d M Y');

        $bodyRows  = '';
        $footRow   = '';

        foreach ($bySite as $code => $s) {
            $isTotal = ($code === 'Total');
            $label   = $isTotal
                ? "<strong>Total</strong>"
                : htmlspecialchars($siteLabels[$code] ?? $code);
            $pct     = $s['pct'] !== null ? $s['pct'] . '%' : '&mdash;';
            $ragCls  = $this->ragClass($s['pct']);

            $row = "<tr>"
                 . "<td class='site-col'>{$label}</td>"
                 . "<td style='text-align:center'>{$s['total']}</td>"
                 . "<td style='text-align:center;color:#2e7d32'><strong>{$s['completed']}</strong></td>"
                 . "<td style='text-align:center;color:#c62828'>{$s['due']}</td>"
                 . "<td style='text-align:center;color:#9e9e9e'>{$s['not_due']}</td>"
                 . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pct}</td>"
                 . "</tr>";

            if ($isTotal) $footRow   = $row;
            else          $bodyRows .= $row;
        }

        $teleSection = $this->renderTeleSummary($payload);

        return "
<table class='report-table completion-table'>
  <caption>Day 29 Follow-up &mdash; Site Summary
    {$periodSpan}
    <span class='report-date'>{$date}</span>
  </caption>
  <thead>
    <tr>
      <th>Site</th>
      <th style='text-align:center'>Enrolled</th>
      <th style='text-align:center'>Completed</th>
      <th style='text-align:center'>Pending</th>
      <th style='text-align:center'>Not Due</th>
      <th style='text-align:center'>%<br><span style='font-weight:400;font-size:10px'>of due</span></th>
    </tr>
  </thead>
  <tbody>{$bodyRows}</tbody>
  <tfoot>{$footRow}</tfoot>
</table>
<p style='font-size:11px;color:#8892a4;margin-top:6px'>
  % = Completed / (Completed + Pending) &times; 100.
  Not Due = enrollment incomplete / age &lt; 29 days / protocol deviation / withdrawal / died.
</p>" . $teleSection;
    }

    // =========================================================================
    // Section 1b — Telephonic call summary
    // =========================================================================

    private function renderTeleSummary(array $payload): string
    {
        $bySite     = $payload['by_site']     ?? [];
        $siteLabels = $payload['site_labels'] ?? [];

        // Only render if any telephonic calls exist
        $totalTele = ($bySite['Total']['tele_total'] ?? 0);
        if ($totalTele === 0) return '';

        $bodyRows = '';
        $footRow  = '';
        foreach ($bySite as $code => $s) {
            $isTotal = ($code === 'Total');
            $label   = $isTotal
                ? "<strong>Total</strong>"
                : htmlspecialchars($siteLabels[$code] ?? $code);
            $pct     = ($s['tele_pct'] ?? null) !== null ? $s['tele_pct'] . '%' : '&mdash;';
            $ragCls  = $this->ragClass($s['tele_pct'] ?? null);

            $row = "<tr>"
                 . "<td class='site-col'>{$label}</td>"
                 . "<td style='text-align:center'>" . ($s['tele_total']    ?? 0) . "</td>"
                 . "<td style='text-align:center;color:#2e7d32'><strong>" . ($s['tele_done']     ?? 0) . "</strong></td>"
                 . "<td style='text-align:center;color:#c62828'>"          . ($s['tele_not_done'] ?? 0) . "</td>"
                 . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pct}</td>"
                 . "</tr>";

            if ($isTotal) $footRow   = $row;
            else          $bodyRows .= $row;
        }

        return "
<div class='section-gap'></div>
<table class='report-table completion-table'>
  <caption>Day 29 Follow-up &mdash; Telephonic Calls</caption>
  <thead>
    <tr>
      <th>Site</th>
      <th style='text-align:center'>Total calls</th>
      <th style='text-align:center'>Done</th>
      <th style='text-align:center'>Not Done</th>
      <th style='text-align:center'>%</th>
    </tr>
  </thead>
  <tbody>{$bodyRows}</tbody>
  <tfoot>{$footRow}</tfoot>
</table>";
    }

    // =========================================================================
    // Section 2 — Per-baby detail
    // =========================================================================

    private function renderBabyTable(array $payload): string
    {
        $participants = $payload['participants'] ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $periodLabel  = $payload['period_label'] ?? '';
        $periodSpan   = $periodLabel
            ? "<span class='report-period'>" . htmlspecialchars($periodLabel) . "</span>"
            : '';
        $count = count($participants);
        $date  = date('d M Y');

        $rows = '';
        foreach ($participants as $p) {
            $site   = htmlspecialchars($siteLabels[$p['site']] ?? $p['site']);
            $status = $p['status'];

            $statusBadge = match($status) {
                'completed' => "<span class='badge badge-yes'>Done</span>",
                'due'       => "<span class='badge badge-no'>Pending</span>",
                default     => "<span class='badge badge-none'>Not due</span>",
            };

            $reason  = $p['not_due_reason'] !== ''
                ? "<span style='font-size:11px;color:#8892a4'>"
                  . htmlspecialchars($p['not_due_reason']) . "</span>"
                : '&mdash;';
            $ageDays = $p['age_days'] !== null
                ? (string)$p['age_days']
                : '&mdash;';
            $ageDischarge = $p['age_discharge'] !== null && $p['age_discharge'] !== ''
                ? number_format((float)$p['age_discharge'], 2)
                : '&mdash;';

            $rows .= "<tr data-status='" . htmlspecialchars($status) . "'>"
                   . "<td>" . htmlspecialchars((string)$p['record_id']) . "</td>"
                   . "<td>{$site}</td>"
                   . "<td>" . htmlspecialchars($p['arm']) . "</td>"
                   . "<td class='nowrap'>" . htmlspecialchars($p['dob']) . "</td>"
                   . "<td style='text-align:center'>{$ageDays}</td>"
                   . "<td style='text-align:center'>{$ageDischarge}</td>"
                   . "<td style='text-align:center'>{$statusBadge}</td>"
                   . "<td style='text-align:center'>" . ($p['is_tele'] ? "<span class='badge badge-none'>TELE</span>" : '&mdash;') . "</td>"
                   . "<td style='text-align:center'>" . ($p['is_tele'] ? ($p['tele_done'] ? "<span class='badge badge-yes'>Done</span>" : "<span class='badge badge-no'>Not Done</span>") : '&mdash;') . "</td>"
                   . "<td>{$reason}</td>"
                   . "</tr>";
        }

        return "
<div style='margin-bottom:10px;display:flex;gap:12px;align-items:center;font-size:12px;'>
  <label>Filter:
    <select onchange=\"filterDay29(this.value)\"
            style='margin-left:6px;padding:3px 8px;border-radius:4px;border:1px solid #dde3ec;'>
      <option value=''>All</option>
      <option value='completed'>Completed</option>
      <option value='due'>Pending</option>
      <option value='not_due'>Not Due</option>
    </select>
  </label>
</div>
<table class='report-table completion-table'>
  <caption>Day 29 Follow-up &mdash; Per-Baby Detail
    {$periodSpan}
    <span class='report-date'>N = {$count} &nbsp;|&nbsp; {$date}</span>
  </caption>
  <thead>
    <tr>
      <th>ID</th>
      <th>Site</th>
      <th>Arm</th>
      <th>DOB</th>
      <th style='text-align:center'>Age<br><span style='font-weight:400;font-size:10px'>(days)</span></th>
      <th style='text-align:center'>Age at<br><span style='font-weight:400;font-size:10px'>discharge</span></th>
      <th style='text-align:center'>Status</th>
      <th style='text-align:center'>Follow-up<br><span style='font-weight:400;font-size:10px'>type</span></th>
      <th style='text-align:center'>Telephonic<br><span style='font-weight:400;font-size:10px'>call</span></th>
      <th>Reason not due</th>
    </tr>
  </thead>
  <tbody id='day29Body'>{$rows}</tbody>
</table>
<script>
function filterDay29(val) {
    document.querySelectorAll('#day29Body tr').forEach(function(row) {
        row.style.display = (!val || row.dataset.status === val) ? '' : 'none';
    });
}
</script>";
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function ragClass(?float $pct): string
    {
        if ($pct === null) return '';
        if ($pct >= 90)    return 'rag-green';
        if ($pct >= 70)    return 'rag-amber';
        return 'rag-red';
    }

    private function buildHead(): string
    {
        if ($this->inline) {
            $cssFile  = realpath(__DIR__ . self::PATH_CSS);
            $css      = ($cssFile && file_exists($cssFile)) ? file_get_contents($cssFile) : '';
            $styleTag = "<style>\n{$css}\n</style>";
        } else {
            $styleTag = "<link rel='stylesheet' href='{$this->cssPath}'>";
        }

        return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>"
             . "<title>Day 29 Follow-up Completion</title>\n"
             . $styleTag . "\n</head>\n";
    }
}

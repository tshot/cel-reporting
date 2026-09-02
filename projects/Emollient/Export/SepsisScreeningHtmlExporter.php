<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * SepsisScreeningHtmlExporter
 *
 * All styles in sepsis_screening.css, all JS in sepsis_screening.js.
 * No inline styles or script blocks in this file.
 *
 * Section 1 — Site summary: enrolled / due days / completed / % (RAG)
 * Section 2 — Per-baby detail: filterable by site and arm
 */
class SepsisScreeningHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $jsPath;
    private string $toolbar;

    private const PATH_CSS_BASE = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';
    private const PATH_CSS_SS   = '/../../../reporting-engine/public/assets/css/sepsis_screening.css';
    private const PATH_JS_SS    = '/../../../reporting-engine/public/assets/js/sepsis_screening.js';

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $jsPath  = '',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->cssPath = $cssPath ?: '/assets/css/emollient_eligible.css';
        $this->jsPath  = $jsPath  ?: '/assets/js/sepsis_screening.js';
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
    // Rendering
    // =========================================================================

    private function render(array $payload): string
    {
        $html  = $this->buildHead();
        $html .= "<body>\n";
        if ($this->toolbar) $html .= $this->toolbar;
        $html .= $this->renderSiteSummary($payload);
        $html .= "<div class='section-gap'></div>";
        $html .= $this->renderBabyTable($payload);
        $html .= "\n</body></html>";
        return $html;
    }

    // =========================================================================
    // Section 1 — Site summary
    // =========================================================================

    private function renderSiteSummary(array $payload): string
    {
        $bySite      = $payload['by_site']      ?? [];
        $siteLabels  = $payload['site_labels']  ?? [];
        $period      = $payload['period']       ?? [];
        $periodLabel = $this->periodLabel($period);
        $date        = date('d M Y');

        $bodyRows = '';
        $footRow  = '';

        foreach ($bySite as $code => $s) {
            $isTotal = ($code === 'Total');
            $label   = $isTotal
                ? "<strong>Total</strong>"
                : htmlspecialchars($siteLabels[$code] ?? $code);

            $pct    = $s['pct'] !== null ? $s['pct'] . '%' : '&mdash;';
            $ragCls = $this->ragClass($s['pct']);

            $row = "<tr>"
                 . "<td class='ss-td-site'>{$label}</td>"
                 . "<td class='ss-num'>{$s['enrolled']}</td>"
                 . "<td class='ss-num'>{$s['due']}</td>"
                 . "<td class='ss-num'>{$s['done']}</td>"
                 . "<td class='ss-num'>" . ($s['due'] - $s['done']) . "</td>"
                 . "<td class='ss-pct {$ragCls}'>{$pct}</td>"
                 . "</tr>";

            if ($isTotal) $footRow   = $row;
            else          $bodyRows .= $row;
        }

        return "
<table class='ss-table'>
  <caption>Neonatal Sepsis Screening &mdash; Site Summary
    <span class='report-period'>" . htmlspecialchars($periodLabel) . "</span>
    <span class='report-date'>{$date}</span>
  </caption>
  <thead>
    <tr>
      <th class='ss-th-site'>Site</th>
      <th>Enrolled</th>
      <th>Days Due</th>
      <th>Completed</th>
      <th>Missing</th>
      <th>%</th>
    </tr>
  </thead>
  <tbody>{$bodyRows}</tbody>
  <tfoot>{$footRow}</tfoot>
</table>
<p class='ss-note'>
  Due when: (dcm_vit_status = SEPSIS_VS_ALIVE AND dcm_baby_discharged is blank)
  OR dcm_baby_discharged = N. Days 1&ndash;28.
  % = Completed / Days Due &times; 100.
</p>
<p class='ss-legend'>
  <span class='rag-green'>&#9632;</span> &ge;90%&nbsp;&nbsp;
  <span class='rag-amber'>&#9632;</span> 70&ndash;89%&nbsp;&nbsp;
  <span class='rag-red'>&#9632;</span> &lt;70%
</p>";
    }

    // =========================================================================
    // Section 2 — Per-baby detail
    // =========================================================================

    private function renderBabyTable(array $payload): string
    {
        $participants = $payload['participants'] ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $period       = $payload['period']       ?? [];
        $periodLabel  = $this->periodLabel($period);
        $count        = count($participants);
        $date         = date('d M Y');

        // Build unique sites and arms for filter dropdowns
        $sites = [];
        $arms  = [];
        foreach ($participants as $p) {
            $sites[$p['site']] = $siteLabels[$p['site']] ?? $p['site'];
            if ($p['arm'] !== '') $arms[$p['arm']] = $p['arm'];
        }
        asort($sites);
        asort($arms);

        $siteOptions = "<option value=''>All Sites</option>";
        foreach ($sites as $code => $label)
            $siteOptions .= "<option value='" . htmlspecialchars($code) . "'>"
                          . htmlspecialchars($label) . "</option>";

        $armOptions = "<option value=''>All Arms</option>";
        foreach ($arms as $arm)
            $armOptions .= "<option value='" . htmlspecialchars($arm) . "'>"
                         . htmlspecialchars($arm) . "</option>";

        $rows = '';
        foreach ($participants as $p) {
            $site    = htmlspecialchars($siteLabels[$p['site']] ?? $p['site']);
            $pct     = $p['pct'] !== null ? $p['pct'] . '%' : '&mdash;';
            $ragCls  = $this->ragClass($p['pct']);
            $missing = !empty($p['missing'])
                ? htmlspecialchars(implode(', ', $p['missing']))
                : '&mdash;';

            $rows .= "<tr data-site='" . htmlspecialchars($p['site']) . "'"
                   . " data-arm='"     . htmlspecialchars($p['arm'])  . "'"
                   . " data-id='"      . htmlspecialchars((string)$p['record_id']) . "'>"
                   . "<td class='ss-td-left'>" . htmlspecialchars((string)$p['record_id']) . "</td>"
                   . "<td class='ss-td-left'>{$site}</td>"
                   . "<td>" . htmlspecialchars($p['arm']) . "</td>"
                   . "<td class='ss-num'>{$p['end_day']}</td>"
                   . "<td class='ss-num'>{$p['due']}</td>"
                   . "<td class='ss-num'>{$p['done']}</td>"
                   . "<td class='ss-num'>" . ($p['due'] - $p['done']) . "</td>"
                   . "<td class='ss-pct {$ragCls}'>{$pct}</td>"
                   . "<td class='ss-td-missing'>{$missing}</td>"
                   . "</tr>";
        }

        return "
<div class='ss-filter-bar'>
  <label>Site:
    <select id='ss-filter-site'>{$siteOptions}</select>
  </label>
  <label>Arm:
    <select id='ss-filter-arm'>{$armOptions}</select>
  </label>
  <label>ID:
    <input type='text' id='ss-filter-search' placeholder='Search ID&hellip;'>
  </label>
</div>
<table class='ss-detail-table'>
  <caption>Neonatal Sepsis Screening &mdash; Per-Baby Detail
    <span class='report-period'>" . htmlspecialchars($periodLabel) . "</span>
    <span class='report-date'>N = {$count} &nbsp;|&nbsp; {$date}</span>
  </caption>
  <thead>
    <tr>
      <th class='ss-th-left'>ID</th>
      <th class='ss-th-left'>Site</th>
      <th>Arm</th>
      <th>Up to Day</th>
      <th>Due</th>
      <th>Done</th>
      <th>Missing</th>
      <th>%</th>
      <th class='ss-th-left'>Missing Days</th>
    </tr>
  </thead>
  <tbody id='ss-detail-body'>{$rows}</tbody>
</table>";
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
            $base = realpath(__DIR__ . self::PATH_CSS_BASE);
            $ss   = realpath(__DIR__ . self::PATH_CSS_SS);
            $js   = realpath(__DIR__ . self::PATH_JS_SS);
            $css  = ($base && file_exists($base) ? file_get_contents($base) : '')
                  . "\n"
                  . ($ss   && file_exists($ss)   ? file_get_contents($ss)   : '');
            $jsInline = $js && file_exists($js) ? file_get_contents($js) : '';
            return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>"
                 . "<title>Neonatal Sepsis Screening Completion</title>\n"
                 . "<style>\n{$css}\n</style>\n"
                 . "<script>{$jsInline}</script>\n"
                 . "</head>\n";
        }

        return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>"
             . "<title>Neonatal Sepsis Screening Completion</title>\n"
             . "<link rel='stylesheet' href='{$this->cssPath}'>\n"
             . "<link rel='stylesheet' href='/assets/css/sepsis_screening.css'>\n"
             . "<script src='{$this->jsPath}' defer></script>\n"
             . "</head>\n";
    }
}

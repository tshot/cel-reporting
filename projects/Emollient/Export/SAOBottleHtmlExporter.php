<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * SAOBottleHtmlExporter
 *
 * Two sections:
 *   Section 1 — Site summary (bottle coverage + baby-level full supply %)
 *   Section 2 — Per-baby detail table
 */
class SAOBottleHtmlExporter implements ExporterInterface
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
        $periodLabel = $payload['period_label'] ?? '';
        $periodSpan  = $periodLabel
            ? "<span class='report-period'>" . htmlspecialchars($periodLabel) . "</span>"
            : '';
        $date = date('d M Y');

        $bodyRows = '';
        $footRow  = '';

        foreach ($bySite as $code => $s) {
            $isTotal = ($code === 'Total');
            $label   = $isTotal
                ? "<strong>Total</strong>"
                : htmlspecialchars($siteLabels[$code] ?? $code);

            $covPct      = $s['coverage_pct']    !== null ? $s['coverage_pct']    . '%' : '&mdash;';
            $babyPct     = $s['babies_full_pct'] !== null ? $s['babies_full_pct'] . '%' : '&mdash;';
            $covRag      = $this->ragClass($s['coverage_pct']    ?? null);
            $babyRag     = $this->ragClass($s['babies_full_pct'] ?? null);

            $row = "<tr>"
                 . "<td class='site-col'>{$label}</td>"
                 . "<td style='text-align:center'>{$s['babies_n']}</td>"
                 . "<td style='text-align:center'>{$s['bottles_required']}</td>"
                 . "<td style='text-align:center'>{$s['bottles_given']}</td>"
                 . "<td class='{$covRag}' style='text-align:center;font-weight:bold'>{$covPct}</td>"
                 . "<td style='text-align:center'>{$s['babies_full']}</td>"
                 . "<td class='{$babyRag}' style='text-align:center;font-weight:bold'>{$babyPct}</td>"
                 . "</tr>";

            if ($isTotal) $footRow   = $row;
            else          $bodyRows .= $row;
        }

        return "
<table class='report-table completion-table'>
  <caption>SAO Bottle Supply at Discharge &mdash; Site Summary
    {$periodSpan}
    <span class='report-date'>{$date}</span>
  </caption>
  <thead>
    <tr>
      <th rowspan='2'>Site</th>
      <th rowspan='2' style='text-align:center'>Babies<br><span style='font-weight:400;font-size:10px'>(N, bottles&nbsp;&gt;&nbsp;0)</span></th>
      <th colspan='3' style='text-align:center;border-bottom:1px solid #c5d3e0'>Bottle Coverage</th>
      <th colspan='2' style='text-align:center;border-bottom:1px solid #c5d3e0'>Fully Supplied</th>
    </tr>
    <tr>
      <th style='text-align:center'>Required</th>
      <th style='text-align:center'>Given</th>
      <th style='text-align:center'>%<br><span style='font-weight:400;font-size:10px'>given/required</span></th>
      <th style='text-align:center'>Babies</th>
      <th style='text-align:center'>%<br><span style='font-weight:400;font-size:10px'>babies fully supplied</span></th>
    </tr>
  </thead>
  <tbody>{$bodyRows}</tbody>
  <tfoot>{$footRow}</tfoot>
</table>
<p style='font-size:11px;color:#8892a4;margin-top:6px'>
  Bottle Coverage % = total bottles given / total bottles required &times; 100.<br>
  Fully Supplied % = babies where dis_bottles_given &ge; dis_bottles_required / N &times; 100.<br>
  N excludes babies where dis_bottles_required = 0 or blank.
</p>";
    }

    // =========================================================================
    // Section 2 — Per-baby detail
    // =========================================================================

    private function renderBabyTable(array $payload): string
    {
        $babies     = $payload['babies']      ?? [];
        $siteLabels = $payload['site_labels'] ?? [];
        $count      = count($babies);
        $date       = date('d M Y');

        $rows = '';
        foreach ($babies as $b) {
            $site     = htmlspecialchars($siteLabels[$b['site']] ?? $b['site']);
            $req      = $b['required'];
            $giv      = $b['given'];
            $full     = $b['fully_supplied'];
            $shortfall = max(0, $req - $giv);

            $supplyBadge = $full
                ? "<span class='badge badge-yes'>Full</span>"
                : "<span class='badge badge-no'>Short</span>";

            $shortfallCell = $shortfall > 0
                ? "<span style='color:#c62828;font-weight:600'>-{$shortfall}</span>"
                : "<span style='color:#2e7d32'>&mdash;</span>";

            $pctGiven = $req > 0 ? round($giv / $req * 100, 1) : 0;
            $ragCls   = $this->ragClass($pctGiven);

            $rows .= "<tr>"
                   . "<td>" . htmlspecialchars((string)$b['record_id']) . "</td>"
                   . "<td>{$site}</td>"
                   . "<td>" . htmlspecialchars($b['arm']) . "</td>"
                   . "<td style='text-align:center'>{$req}</td>"
                   . "<td style='text-align:center'>{$giv}</td>"
                   . "<td style='text-align:center'>{$shortfallCell}</td>"
                   . "<td class='{$ragCls}' style='text-align:center;font-weight:bold'>{$pctGiven}%</td>"
                   . "<td style='text-align:center'>{$supplyBadge}</td>"
                   . "</tr>";
        }

        return "
<table class='report-table completion-table'>
  <caption>SAO Bottle Supply &mdash; Per-Baby Detail
    <span class='report-date'>N = {$count} &nbsp;|&nbsp; {$date}</span>
  </caption>
  <thead>
    <tr>
      <th>ID</th>
      <th>Site</th>
      <th>Arm</th>
      <th style='text-align:center'>Required</th>
      <th style='text-align:center'>Given</th>
      <th style='text-align:center'>Shortfall</th>
      <th style='text-align:center'>% Given</th>
      <th style='text-align:center'>Status</th>
    </tr>
  </thead>
  <tbody>{$rows}</tbody>
</table>";
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
             . "<title>SAO Bottle Supply</title>\n"
             . $styleTag . "\n</head>\n";
    }
}

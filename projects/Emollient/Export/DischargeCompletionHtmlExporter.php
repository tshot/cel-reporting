<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * DischargeCompletionHtmlExporter
 *
 * All styles live in discharge_completion.css — no inline styles in this file.
 *
 * Section 1 — Site summary:
 *   N = enrolled babies
 *   Form completion count + %
 *   Discharge type breakdown (dynamic columns)
 *   Special types (TYP_FP, TYP_DEA, TYP_REF) count + % of N
 *
 * Section 2 — Per-baby detail
 */
class DischargeCompletionHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    private const PATH_CSS_BASE = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';
    private const PATH_CSS_DC   = '/../../../reporting-engine/public/assets/css/discharge_completion.css';

    private const TYPE_LABELS = [
        'TYP_FP'   => 'Planned Discharge',
        'TYP_DEA'  => 'Death',
        'TYP_REF'  => 'Referral',
        'TYP_LAMA' => 'LAMA',
        'TYP_ABS'  => 'Abscond',
        'TYP_DOPR' => 'DOPR',
    ];

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
        $bySite       = $payload['by_site']      ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $allTypes     = $payload['all_types']     ?? [];
        $specialTypes = $payload['special_types'] ?? [];
        $period      = $payload['period']      ?? [];
        $periodLabel = $this->periodLabel($period);
        $periodSpan  = "<span class='report-period'>" . htmlspecialchars($periodLabel) . "</span>";
        $date = date('d M Y');

        // Special types first, then alphabetical
        usort($allTypes, function($a, $b) use ($specialTypes) {
            $as = in_array($a, $specialTypes);
            $bs = in_array($b, $specialTypes);
            if ($as !== $bs) return $bs - $as;
            return strcmp($a, $b);
        });

        // Group header — colspan for type columns
        $typeCount   = count($allTypes);
        $typeHeaders = '';
        foreach ($allTypes as $type) {
            $label     = self::TYPE_LABELS[$type] ?? $type;
            $isSpec    = in_array($type, $specialTypes);
            $cls       = $isSpec ? 'dc-special-th' : '';
            $typeHeaders .= "<th class='{$cls}'>" . htmlspecialchars($label) . "</th>";
        }

        $bodyRows = '';
        $footRow  = '';

        foreach ($bySite as $code => $s) {
            $isTotal = ($code === 'Total');
            $label   = $isTotal
                ? "<strong>Total</strong>"
                : htmlspecialchars($siteLabels[$code] ?? $code);

            $formPct  = $s['form_pct']    !== null ? $s['form_pct']    . '%' : '&mdash;';
            $specPct  = $s['special_pct'] !== null ? $s['special_pct'] . '%' : '&mdash;';
            $formRag  = $this->ragClass($s['form_pct']    ?? null);
            // For special discharges lower = better — invert the RAG
            $specRag  = $this->ragClassInverse($s['special_pct'] ?? null);

            $typeCells = '';
            foreach ($allTypes as $type) {
                $cnt    = $s['type_counts'][$type] ?? 0;
                $isSpec = in_array($type, $specialTypes);
                $cls    = $isSpec ? 'dc-special-cell' : 'dc-num';
                $typeCells .= "<td class='{$cls}'>" . ($cnt ?: '&mdash;') . "</td>";
            }

            $row = "<tr>"
                 . "<td class='dc-td-site'>{$label}</td>"
                 . "<td class='dc-bold dc-num'>{$s['enrolled']}</td>"
                 . "<td class='dc-num'>{$s['form_complete']}</td>"
                 . "<td class='dc-num'>{$s['form_missing']}</td>"
                 . "<td class='dc-pct {$formRag}'>{$formPct}</td>"
                 . $typeCells
                 . "<td class='dc-special-cell dc-bold'>{$s['special_count']}</td>"
                 . "<td class='dc-pct {$specRag}'>{$specPct}</td>"
                 . "</tr>";

            if ($isTotal) $footRow   = $row;
            else          $bodyRows .= $row;
        }

        $specLabel = implode(' + ', array_map(
            fn($t) => self::TYPE_LABELS[$t] ?? $t,
            $specialTypes
        ));

        return "
<table class='dc-table'>
  <caption>Discharge Form Completion &mdash; Site Summary
    {$periodSpan}
    <span class='report-date'>{$date}</span>
  </caption>
  <thead>
    <tr>
      <th class='dc-th-site' rowspan='2'>Site</th>
      <th rowspan='2'>Enrolled<br><span style='font-weight:400;font-size:10px'>(N)</span></th>
      <th class='dc-group-header' colspan='3'>Form Completion</th>
      <th class='dc-group-header' colspan='{$typeCount}'>Discharge Type</th>
      <th class='dc-group-header dc-special-header' colspan='2'>Special Discharges</th>
    </tr>
    <tr>
      <th>Done</th>
      <th>Missing</th>
      <th>%</th>
      {$typeHeaders}
      <th class='dc-special-th'>Count</th>
      <th class='dc-special-th'>% of N</th>
    </tr>
  </thead>
  <tbody>{$bodyRows}</tbody>
  <tfoot>{$footRow}</tfoot>
</table>
<p class='dc-note'>
  N = enrolled babies (enr_consent_granted = Y).
  Special Discharges = {$specLabel}. % uses N as denominator.
</p>";
    }

    // =========================================================================
    // Section 2 — Per-baby detail
    // =========================================================================

    private function renderBabyTable(array $payload): string
    {
        $participants = $payload['participants'] ?? [];
        $siteLabels   = $payload['site_labels']  ?? [];
        $specialTypes = $payload['special_types'] ?? [];
        $count        = count($participants);
        $date         = date('d M Y');
        $period       = $payload['period']       ?? [];
        $periodSpan   = "<span class='report-period'>" . htmlspecialchars($this->periodLabel($period)) . "</span>";

        $rows = '';
        foreach ($participants as $p) {
            $site  = htmlspecialchars($siteLabels[$p['site']] ?? $p['site']);
            $dtype = $p['discharge_type'];
            $label = htmlspecialchars(self::TYPE_LABELS[$dtype] ?? ($dtype ?: '—'));

            $formBadge = $p['form_complete']
                ? "<span class='badge badge-yes'>Done</span>"
                : "<span class='badge badge-no'>Missing</span>";

            $typeCls = $p['is_special'] ? 'dc-type-special' : '';

            $rows .= "<tr>"
                   . "<td>" . htmlspecialchars((string)$p['record_id']) . "</td>"
                   . "<td>{$site}</td>"
                   . "<td>" . htmlspecialchars($p['arm']) . "</td>"
                   . "<td class='dc-num'>{$formBadge}</td>"
                   . "<td class='{$typeCls}'>{$label}</td>"
                   . "</tr>";
        }

        return "
<table class='dc-detail-table'>
  <caption>Discharge Form Completion &mdash; Per-Baby Detail
    {$periodSpan}
    <span class='report-date'>N = {$count} &nbsp;|&nbsp; {$date}</span>
  </caption>
  <thead>
    <tr>
      <th>ID</th>
      <th>Site</th>
      <th>Arm</th>
      <th class='dc-num'>Form</th>
      <th>Discharge Type</th>
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

    private function ragClassInverse(?float $pct): string
    {
        // Lower special discharge % is better
        if ($pct === null) return '';
        if ($pct <= 5)     return 'rag-green';
        if ($pct <= 15)    return 'rag-amber';
        return 'rag-red';
    }

    private function periodLabel(array $period): string
    {
        $from = $period['date_from'] ?? null;
        $to   = $period['date_to']   ?? null;
        if ($from && $to)  return "Period: {$from} to {$to}";
        if ($to)           return "Period: up to {$to}";
        if ($from)         return "Period: from {$from}";
        return 'All dates';
    }

    private function buildHead(): string
    {
        if ($this->inline) {
            $base = realpath(__DIR__ . self::PATH_CSS_BASE);
            $dc   = realpath(__DIR__ . self::PATH_CSS_DC);
            $css  = ($base && file_exists($base) ? file_get_contents($base) : '')
                  . "\n"
                  . ($dc   && file_exists($dc)   ? file_get_contents($dc)   : '');
            $styleTag = "<style>\n{$css}\n</style>";
        } else {
            $styleTag = "<link rel='stylesheet' href='{$this->cssPath}'>\n"
                      . "<link rel='stylesheet' href='/assets/css/discharge_completion.css'>";
        }

        return "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>"
             . "<title>Discharge Form Completion</title>\n"
             . $styleTag . "\n</head>\n";
    }
}

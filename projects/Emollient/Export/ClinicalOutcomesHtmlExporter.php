<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * ClinicalOutcomesHtmlExporter
 *
 * Renders all primary and secondary clinical outcomes as a single HTML report.
 *
 * Follows the same asset pattern as LengthOfStayHtmlExporter:
 *   - inline=false (web)  → <link> and <script src="..."> references
 *   - inline=true  (CLI)  → CSS and JS file contents inlined in <style>/<script>
 *
 * External assets:
 *   CSS  emollient_eligible.css  — shared base styles (tables, layout, header)
 *        clinical_outcomes.css  — report-specific styles (cards, badges)
 *
 * Sections:
 *   0 — Denominators (N1, N2, excluded audit)
 *   1 — P1: Pre-discharge mortality
 *   2 — S1: Neonatal mortality (Day 29)
 *   3 — S2: Suspected sepsis incidence
 *   4 — S3: Skin condition scores trajectory
 *   5 — S4: Duration of hospital stay
 *   6 — S5: Weight gain velocity (g/kg/day)
 *   7 — S6: Head circumference velocity (mm/week)
 *   8 — S7: MUAC velocity (mm/week)
 */
class ClinicalOutcomesHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $cssPath;
    private string $toolbar;

    // Paths relative to __DIR__ = projects/Emollient/Export/
    private const PATH_CSS_BASE = '/../../../reporting-engine/public/assets/css/emollient_eligible.css';
    private const PATH_CSS_CO   = '/../../../reporting-engine/public/assets/css/clinical_outcomes.css';

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
        $this->export($data, $outputPath);
    }

    // =========================================================================
    // Asset tags
    // =========================================================================

    /**
     * Returns the CSS <link>/<style> tag.
     * inline=true  → file contents embedded in <style> (for CLI/download)
     * inline=false → <link href="..."> references (for browser)
     */
    private function buildCssTags(): string
    {
        if ($this->inline) {
            $base = $this->readFile(self::PATH_CSS_BASE);
            $co   = $this->readFile(self::PATH_CSS_CO);
            return "<style>\n{$base}\n{$co}\n</style>";
        }

        return "<link rel=\"stylesheet\" href=\"{$this->cssPath}\">\n"
             . "<link rel=\"stylesheet\" href=\"/assets/css/clinical_outcomes.css\">";
    }

    private function readFile(string $relativePath): string
    {
        $abs = __DIR__ . $relativePath;
        if (!file_exists($abs)) {
            return "/* File not found: {$abs} */";
        }
        return file_get_contents($abs);
    }

    // =========================================================================
    // Full page render
    // =========================================================================

    private function renderFullPage(array $p): string
    {
        $den    = $p['denominators'] ?? [];
        $pri    = $p['primary']      ?? [];
        $sec    = $p['secondary']    ?? [];
        $period = $p['period']       ?? [];
        $labels = $p['site_labels']  ?? [];

        $dateFrom = htmlspecialchars($period['date_from'] ?? '—');
        $dateTo   = htmlspecialchars($period['date_to']   ?? '—');
        $cssTags  = $this->buildCssTags();

        ob_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Clinical Outcomes — Emollient Trial</title>
<?= $cssTags ?>
</head>
<body>

<div class="report-header">
  <h1>Clinical Outcomes — Emollient Trial</h1>
  <p>Period: <?= $dateFrom ?> to <?= $dateTo ?></p>
</div>

<?= $this->toolbar ?>

<?= $this->renderDenominators($den, $labels) ?>
<?= $this->renderOutcome('Primary Outcome — Pre-Discharge Mortality',
    'Numerator: dis_discharge_type = TYP_DEA',
    $pri['mortality'] ?? [], $labels) ?>
<?= $this->renderOutcome('Secondary Outcome 1 — Neonatal Mortality (Overall)',
    'Total deaths = pre-discharge deaths (dis_discharge_type = TYP_DEA) + deaths at 29-day follow-up (fu28_alive_day28 = ALV_N). A baby is counted once even if recorded in both.',
    $sec['neonatal_mortality'] ?? [], $labels) ?>
<?= $this->renderOutcome('Secondary Outcome 2 — Incidence of Suspected Sepsis',
    'Episode: clinical sign (high/low temp OR respiratory rate OR seizure) AND ≥2 lab criteria '
    . '(TLC &lt; 5000 OR ANC &lt; 1800 OR ESR &gt; 15 OR CRP &gt; 10). '
    . 'Baby counted once if ≥1 qualifying episode across Day 0–28.',
    $sec['sepsis'] ?? [], $labels) ?>
<?= $this->renderSkinScores($sec['skin_scores'] ?? []) ?>
<?= $this->renderVelocity('Secondary Outcome 4 — Duration of Hospital Stay',
    'LOS = age at discharge (dis_age_discharge) minus age at enrollment (enr_baby_age_days). Uses dis_post_28_age_discharge for post-28 discharges. Babies still in hospital at Day 28 counted as 28 days.',
    'days', $sec['los'] ?? [], $labels) ?>
<?= $this->renderVelocity('Secondary Outcome 5 — Weight Gain Velocity',
    'Formula: (Wdis − Wbirth) / ((Wbirth + Wdis) / 2) / n × 1000. Healthy preterm range: 15–20 g/kg/day.',
    'g/kg/day', $sec['weight_velocity'] ?? [], $labels) ?>
<?= $this->renderVelocity('Secondary Outcome 6 — Head Circumference Growth Velocity',
    'Formula: (HCdis − HCbirth) / n × 7. Measurements in mm.',
    'mm/week', $sec['hc_velocity'] ?? [], $labels) ?>
<?= $this->renderVelocity('Secondary Outcome 7 — MUAC Growth Velocity',
    'Formula: (MUACdis − MUACbirth) / n × 7. Measurements in mm.',
    'mm/week', $sec['muac_velocity'] ?? [], $labels) ?>

</body>
</html>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Section renderers
    // =========================================================================

    private function renderDenominators(array $den, array $labels): string
    {
        $n1     = $den['n1']       ?? [];
        $n2     = $den['n2']       ?? [];
        $excl   = $den['excluded'] ?? [];
        $n1Tot  = $n1['total']     ?? 0;
        $n2Tot  = $n2['total']     ?? 0;
        $exclN  = count($excl);
        $n1Arm  = $n1['by_arm']    ?? [];
        $n2Arm  = $n2['by_arm']    ?? [];

        ob_start(); ?>
<div class="co-section">
  <div class="co-section-title">Denominators</div>
  <div class="co-section-subtitle">
    <strong>N1</strong> = All babies with enr_study_arm filled on day0_arm_1 (total enrolled).<br>
    <strong>N2</strong> = N1 minus babies whose discharge type is LAMA (Left Against Medical Advice),
    Referral, Abscond, or DOPR — i.e. babies who did not complete the protocol.
    All outcome rates are shown against both denominators for transparency.
  </div>

  <div class="co-cards">
    <div class="co-card">
      <div class="co-val"><?= $n1Tot ?></div>
      <div class="co-lbl">N1 — Total Enrolled</div>
    </div>
    <div class="co-card green">
      <div class="co-val"><?= $n2Tot ?></div>
      <div class="co-lbl">N2 — Protocol Complete</div>
    </div>
    <div class="co-card amber">
      <div class="co-val"><?= $exclN ?></div>
      <div class="co-lbl">Excluded from N2</div>
    </div>
  </div>

  <div class="co-tbl-title">Denominator by Arm</div>
  <table>
    <tr><th>Arm</th><th>N1 (Total Enrolled)</th><th>N2 (Protocol Complete)</th><th>Excluded (N1−N2)</th></tr>
    <?php foreach (['Intervention', 'Control', 'Total'] as $arm):
      $a1 = $n1Arm[$arm] ?? 0;
      $a2 = $n2Arm[$arm] ?? 0;
    ?>
    <tr>
      <td class="label"><?= $this->armCell($arm) ?></td>
      <td><?= $a1 ?></td>
      <td><?= $a2 ?></td>
      <td><?= $a1 - $a2 ?></td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if (!empty($excl)): ?>
  <div class="co-tbl-title">Excluded Records — Audit Table</div>
  <div class="co-excluded-wrap">
    <table>
      <tr><th>Record ID</th><th>Discharge Type</th></tr>
      <?php foreach ($excl as $rid => $dtype): ?>
      <tr>
        <td><?= htmlspecialchars((string)$rid) ?></td>
        <td><?= htmlspecialchars($dtype) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php endif; ?>
</div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a binary outcome (mortality, sepsis) — OUTCOME_RESULT shape.
     */
    private function renderOutcome(
        string $title,
        string $subtitle,
        array  $result,
        array  $labels
    ): string {
        $byArm  = $result['by_arm']  ?? [];
        $bySite = $result['by_site'] ?? [];
        $tot    = $byArm['Total']    ?? [];

        ob_start(); ?>
<div class="co-section">
  <div class="co-section-title"><?= htmlspecialchars($title) ?></div>
  <div class="co-section-subtitle"><?= htmlspecialchars($subtitle) ?></div>

  <div class="co-cards">
    <div class="co-card">
      <div class="co-val"><?= $tot['events_n1'] ?? 0 ?></div>
      <div class="co-lbl">Events</div>
    </div>
    <div class="co-card">
      <div class="co-val"><?= isset($tot['rate_n1']) ? $tot['rate_n1'].'%' : '—' ?></div>
      <div class="co-lbl">Rate / N1 (<?= $tot['n1'] ?? 0 ?> enrolled)</div>
    </div>
    <div class="co-card green">
      <div class="co-val"><?= isset($tot['rate_n2']) ? $tot['rate_n2'].'%' : '—' ?></div>
      <div class="co-lbl">Rate / N2 (<?= $tot['n2'] ?? 0 ?> protocol-complete)</div>
    </div>
  </div>

  <div class="co-tbl-title">By Arm</div>
  <?= $this->outcomeTable($byArm, ['Intervention', 'Control', 'Total']) ?>

  <div class="co-tbl-title">By Site</div>
  <?= $this->outcomeSiteTable($bySite, $labels) ?>
</div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the skin scores section — S3.
     */
    private function renderSkinScores(array $result): string
    {
        $traj = $result['trajectory'] ?? [];
        $days = [0, 3, 7, 14, 28];

        ob_start(); ?>
<div class="co-section">
  <div class="co-section-title">Secondary Outcome 3 — Skin Condition Scores</div>
  <div class="co-section-subtitle">
    Mean skin condition score (scs_condition) per arm at timepoints Day 0, 3, 7, 14, 28.
    Baseline HC and MUAC from baseline_arm_1 (base_anthro_head_circumference_1/2, base_anthro_muac_circumference).
    Raw per-baby data available via CSV download for Latent Growth Modelling in R/SPSS.
    Model: Y<sub>it</sub> = β₀ + β₁(T<sub>it</sub>) + ε<sub>it</sub>
  </div>

  <div class="co-tbl-title">Mean Skin Score Trajectory</div>
  <table>
    <tr>
      <th>Arm</th>
      <?php foreach ($days as $d): ?>
      <th>Day <?= $d ?></th>
      <?php endforeach; ?>
    </tr>
    <?php foreach (['Intervention', 'Control', 'Total'] as $arm): ?>
    <tr>
      <td class="label"><?= $this->armCell($arm) ?></td>
      <?php foreach ($days as $d):
        $cell = $traj[$arm][$d] ?? null; ?>
      <td><?= $cell !== null
            ? htmlspecialchars($cell['mean']).' <small>(n='.$cell['n'].')</small>'
            : '—' ?></td>
      <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>
  </table>

  <p class="co-note">
    SD by arm and timepoint —
    <?php foreach (['Intervention', 'Control'] as $arm):
      $parts = [];
      foreach ($days as $d) {
          $cell   = $traj[$arm][$d] ?? null;
          $parts[] = 'Day '.$d.': '.($cell['sd'] ?? '—');
      }
      echo htmlspecialchars($arm).': '.implode(', ', $parts).'. ';
    endforeach; ?>
  </p>

  <a class="co-download-btn" href="?format=skin_csv">
    ⬇ Download Raw Scores for R/SPSS (CSV)
  </a>
</div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a continuous outcome (LOS, velocities) — VELOCITY_RESULT shape.
     */
    private function renderVelocity(
        string $title,
        string $subtitle,
        string $unit,
        array  $result,
        array  $labels
    ): string {
        $byArm  = $result['by_arm']  ?? [];
        $bySite = $result['by_site'] ?? [];
        $tot    = $byArm['Total']    ?? [];

        ob_start(); ?>
<div class="co-section">
  <div class="co-section-title"><?= htmlspecialchars($title) ?></div>
  <div class="co-section-subtitle"><?= htmlspecialchars($subtitle) ?></div>

  <div class="co-cards">
    <div class="co-card">
      <div class="co-val"><?= $tot['count'] ?? 0 ?></div>
      <div class="co-lbl">With data</div>
    </div>
    <div class="co-card amber">
      <div class="co-val"><?= $tot['missing'] ?? 0 ?></div>
      <div class="co-lbl">Missing</div>
    </div>
    <div class="co-card green">
      <div class="co-val"><?= $tot['mean'] ?? '—' ?></div>
      <div class="co-lbl">Mean (<?= htmlspecialchars($unit) ?>)</div>
    </div>
    <div class="co-card">
      <div class="co-val"><?= $tot['median'] ?? '—' ?></div>
      <div class="co-lbl">Median</div>
    </div>
  </div>

  <div class="co-tbl-title">By Arm</div>
  <?= $this->velocityTable($byArm, ['Intervention', 'Control', 'Total'], $unit) ?>

  <div class="co-tbl-title">By Site</div>
  <?= $this->velocitySiteTable($bySite, $labels, $unit) ?>
</div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Table helpers
    // =========================================================================

    private function outcomeTable(array $byArm, array $arms): string
    {
        ob_start(); ?>
<table>
  <tr>
    <th>Arm</th>
    <th>N1</th><th>Events (N1)</th><th>Rate / N1 (%)</th>
    <th>N2</th><th>Events (N2)</th><th>Rate / N2 (%)</th>
  </tr>
  <?php foreach ($arms as $arm):
    $c = $byArm[$arm] ?? [];
    $bold = $arm === 'Total' ? ' style="font-weight:bold;background:#f1f5f9"' : '';
  ?>
  <tr<?= $bold ?>>
    <td class="label"><?= $this->armCell($arm) ?></td>
    <td><?= $c['n1']        ?? 0 ?></td>
    <td><?= $c['events_n1'] ?? 0 ?></td>
    <td><?= isset($c['rate_n1']) ? $c['rate_n1'].'%' : '—' ?></td>
    <td><?= $c['n2']        ?? 0 ?></td>
    <td><?= $c['events_n2'] ?? 0 ?></td>
    <td><?= isset($c['rate_n2']) ? $c['rate_n2'].'%' : '—' ?></td>
  </tr>
  <?php endforeach; ?>
</table>
        <?php
        return ob_get_clean();
    }

    private function outcomeSiteTable(array $bySite, array $labels): string
    {
        ob_start(); ?>
<table>
  <tr>
    <th>Site</th>
    <th>N1</th><th>Events (N1)</th><th>Rate / N1 (%)</th>
    <th>N2</th><th>Events (N2)</th><th>Rate / N2 (%)</th>
  </tr>
  <?php foreach ($bySite as $site => $c): if ($site === 'Total') continue; ?>
  <tr>
    <td class="label"><?= htmlspecialchars($labels[$site] ?? $site) ?></td>
    <td><?= $c['n1']        ?? 0 ?></td>
    <td><?= $c['events_n1'] ?? 0 ?></td>
    <td><?= isset($c['rate_n1']) ? $c['rate_n1'].'%' : '—' ?></td>
    <td><?= $c['n2']        ?? 0 ?></td>
    <td><?= $c['events_n2'] ?? 0 ?></td>
    <td><?= isset($c['rate_n2']) ? $c['rate_n2'].'%' : '—' ?></td>
  </tr>
  <?php endforeach; ?>
  <?php $tot = $bySite['Total'] ?? []; ?>
  <tr style="font-weight:bold;background:#f1f5f9">
    <td class="label">Total</td>
    <td><?= $tot['n1']        ?? 0 ?></td>
    <td><?= $tot['events_n1'] ?? 0 ?></td>
    <td><?= isset($tot['rate_n1']) ? $tot['rate_n1'].'%' : '—' ?></td>
    <td><?= $tot['n2']        ?? 0 ?></td>
    <td><?= $tot['events_n2'] ?? 0 ?></td>
    <td><?= isset($tot['rate_n2']) ? $tot['rate_n2'].'%' : '—' ?></td>
  </tr>
</table>
        <?php
        return ob_get_clean();
    }

    private function velocityTable(array $byArm, array $arms, string $unit): string
    {
        ob_start(); ?>
<table>
  <tr>
    <th>Arm</th>
    <th>N</th>
    <th>Mean (<?= htmlspecialchars($unit) ?>)</th>
    <th>Median</th><th>SD</th><th>Min</th><th>Max</th><th>Missing</th>
  </tr>
  <?php foreach ($arms as $arm):
    $c    = $byArm[$arm] ?? [];
    $bold = $arm === 'Total' ? ' style="font-weight:bold;background:#f1f5f9"' : '';
  ?>
  <tr<?= $bold ?>>
    <td class="label"><?= $this->armCell($arm) ?></td>
    <td><?= $c['count']   ?? 0 ?></td>
    <td><?= $c['mean']    ?? '—' ?></td>
    <td><?= $c['median']  ?? '—' ?></td>
    <td><?= $c['sd']      ?? '—' ?></td>
    <td><?= $c['min']     ?? '—' ?></td>
    <td><?= $c['max']     ?? '—' ?></td>
    <td><?= $c['missing'] ?? 0 ?></td>
  </tr>
  <?php endforeach; ?>
</table>
        <?php
        return ob_get_clean();
    }

    private function velocitySiteTable(array $bySite, array $labels, string $unit): string
    {
        ob_start(); ?>
<table>
  <tr>
    <th>Site</th>
    <th>N</th>
    <th>Mean (<?= htmlspecialchars($unit) ?>)</th>
    <th>Median</th><th>SD</th><th>Min</th><th>Max</th><th>Missing</th>
  </tr>
  <?php foreach ($bySite as $site => $c): if ($site === 'Total') continue; ?>
  <tr>
    <td class="label"><?= htmlspecialchars($labels[$site] ?? $site) ?></td>
    <td><?= $c['count']   ?? 0 ?></td>
    <td><?= $c['mean']    ?? '—' ?></td>
    <td><?= $c['median']  ?? '—' ?></td>
    <td><?= $c['sd']      ?? '—' ?></td>
    <td><?= $c['min']     ?? '—' ?></td>
    <td><?= $c['max']     ?? '—' ?></td>
    <td><?= $c['missing'] ?? 0 ?></td>
  </tr>
  <?php endforeach; ?>
  <?php $tot = $bySite['Total'] ?? []; ?>
  <tr style="font-weight:bold;background:#f1f5f9">
    <td class="label">Total</td>
    <td><?= $tot['count']   ?? 0 ?></td>
    <td><?= $tot['mean']    ?? '—' ?></td>
    <td><?= $tot['median']  ?? '—' ?></td>
    <td><?= $tot['sd']      ?? '—' ?></td>
    <td><?= $tot['min']     ?? '—' ?></td>
    <td><?= $tot['max']     ?? '—' ?></td>
    <td><?= $tot['missing'] ?? 0 ?></td>
  </tr>
</table>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // Utility
    // =========================================================================

    private function armCell(string $arm): string
    {
        return match($arm) {
            'Intervention' => '<span class="tag-int">Intervention</span>',
            'Control'      => '<span class="tag-ctrl">Control</span>',
            'Total'        => '<strong>Total</strong>',
            default        => htmlspecialchars($arm),
        };
    }
}

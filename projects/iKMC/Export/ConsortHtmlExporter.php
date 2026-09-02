<?php
namespace CEL\Projects\iKMC\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * ConsortHtmlExporter
 *
 * Renders the iKMC CONSORT flow diagram as an HTML page with:
 *   - Main column of "boxes" connected by arrows (the participant funnel)
 *   - Side branches showing exclusion counts at each level
 *   - Period summary at the top
 *
 * Layout uses CSS grid + flex — no JS dependencies, prints cleanly.
 */
class ConsortHtmlExporter implements ExporterInterface
{
    public function __construct(
        bool   $inline  = false,
        string $cssPath = '/assets/css/ikmc_consort.css',
        string $toolbar = ''
    ) {
        $this->cssPath = $cssPath;
        $this->toolbar = $toolbar;
    }

    private string $cssPath;
    private string $toolbar;

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

    private function renderFullPage(array $p): string
    {
        $b = $p['boxes']  ?? [];
        $period = $p['period'] ?? [];
        $from   = htmlspecialchars($period['date_from'] ?? '—');
        $to     = htmlspecialchars($period['date_to']   ?? '—');

        ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>iKMC — CONSORT Diagram</title>
<link rel="stylesheet" href="<?= htmlspecialchars($this->cssPath) ?>">
<style>
  body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f8fafc; color: #1f2937; }
  .ikmc-header { background: linear-gradient(135deg, #064e3b 0%, #059669 100%); color: #fff; padding: 24px 32px; }
  .ikmc-header h1 { margin: 0 0 4px; font-size: 22px; }
  .ikmc-header p  { margin: 0; opacity: .8; font-size: 13px; }
  .consort { max-width: 1080px; margin: 24px auto; padding: 0 24px 48px; }
  .row-wrap { display: grid; grid-template-columns: 1fr 360px 1fr; gap: 18px; align-items: center; margin-bottom: 8px; }
  .row-wrap .filler { visibility: hidden; }
  .box-main {
    grid-column: 2;
    background: #fff; border: 2px solid #059669; border-radius: 10px;
    padding: 16px 20px; text-align: center; box-shadow: 0 2px 8px rgba(0,0,0,.06);
  }
  .box-main .label { font-size: 13px; color: #064e3b; font-weight: 600; margin-bottom: 4px; }
  .box-main .count { font-size: 28px; font-weight: bold; color: #059669; }
  .box-main .sub   { font-size: 11px; color: #6b7280; margin-top: 4px; }
  .box-main .arm   { display: inline-block; margin: 0 8px; font-size: 12px; color: #374151; }
  .box-main .arm strong { color: #064e3b; }

  .arrow {
    width: 2px; height: 22px; background: #059669;
    margin: 0 auto; position: relative;
  }
  .arrow::after {
    content: ''; position: absolute; bottom: -2px; left: -4px;
    border: 5px solid transparent; border-top-color: #059669;
  }

  .branch {
    background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px;
    padding: 10px 14px; font-size: 12px; color: #78350f;
    position: relative;
  }
  .branch-left  { grid-column: 1; }
  .branch-right { grid-column: 3; }
  .branch ul { margin: 4px 0 0; padding-left: 18px; }
  .branch li { margin: 2px 0; }
  .branch .b-title { font-weight: bold; color: #92400e; margin-bottom: 4px; font-size: 12px; }
  .branch .b-count { color: #b45309; font-weight: bold; }
  .branch::before {
    content: ''; position: absolute; top: 50%; height: 2px; background: #f59e0b;
    width: 18px;
  }
  .branch-left::before  { right: -18px; }
  .branch-right::before { left:  -18px; }

  .legend {
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 14px 18px; margin-bottom: 18px; font-size: 12px; color: #4b5563;
  }
  .legend strong { color: #064e3b; }

  .outcomes-grid {
    display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 12px;
  }
  .outcome-card {
    background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 8px;
    padding: 12px; text-align: center;
  }
  .outcome-card .o-label { font-size: 11px; color: #065f46; font-weight: 600; margin-bottom: 4px; }
  .outcome-card .o-count { font-size: 22px; font-weight: bold; color: #047857; }
  .outcome-card.dead     { background: #fef2f2; border-color: #fca5a5; }
  .outcome-card.dead .o-count   { color: #b91c1c; }
  .outcome-card.dead .o-label   { color: #7f1d1d; }
</style>
</head>
<body>

<div class="ikmc-header">
  <h1>iKMC — CONSORT Diagram</h1>
  <p>Participant flow • Period: <?= $from ?> to <?= $to ?></p>
</div>

<?= $this->toolbar ?>

<div class="consort">

  <div class="legend">
    <strong>How to read this diagram:</strong>
    Each green box is a stage in the participant pipeline.
    Yellow boxes on the sides show babies excluded at that stage.
    A baby progresses from top to bottom only if eligible — total enrolled is at the bottom.
  </div>

  <!-- ── Box 1: Pre-screened ─────────────────────────────────────────────── -->
  <div class="row-wrap">
    <div class="filler"></div>
    <div class="box-main">
      <div class="label">1. Pre-screened</div>
      <div class="count"><?= $b['pre_screened'] ?? 0 ?></div>
      <div class="sub">scr_baby_scrno is not null</div>
    </div>
    <div class="branch branch-right">
      <div class="b-title">Excluded at pre-screening</div>
      <ul>
        <li>Not preterm / Not LBW: <span class="b-count"><?= $b['excluded_not_lbw'] ?? 0 ?></span></li>
        <li>Missing BW & GA: <span class="b-count"><?= $b['excluded_missing'] ?? 0 ?></span></li>
        <li>Died in first 2 hrs: <span class="b-count"><?= $b['excluded_died_2h'] ?? 0 ?></span></li>
      </ul>
    </div>
  </div>
  <div class="arrow"></div>

  <!-- ── Box 2: Screened ─────────────────────────────────────────────────── -->
  <div class="row-wrap">
    <div class="branch branch-left">
      <div class="b-title">Excluded at screening</div>
      <ul>
        <li>BW/GA above cutoff & not sick: <span class="b-count"><?= $b['excluded_above_cutoff'] ?? 0 ?></span></li>
        <li>Outborn not reached within 24 hrs: <span class="b-count"><?= $b['excluded_no_24h'] ?? 0 ?></span></li>
      </ul>
    </div>
    <div class="box-main">
      <div class="label">2. Screened (Preterm / LBW, alive)</div>
      <div class="count"><?= $b['screened'] ?? 0 ?></div>
      <div class="sub">
        <span class="arm"><strong>Inborn:</strong> <?= $b['screened_inborn']  ?? 0 ?></span>
        <span class="arm"><strong>Outborn:</strong> <?= $b['screened_outborn'] ?? 0 ?></span>
      </div>
    </div>
    <div class="branch branch-right">
      <div class="b-title">Clinical exclusions <em>(not mutually exclusive)</em></div>
      <ul>
        <li>Unable to breathe spontaneously: <span class="b-count"><?= $b['excluded_no_spont_breath'] ?? 0 ?></span></li>
        <li>Congenital malformation: <span class="b-count"><?= $b['excluded_cong_malf'] ?? 0 ?></span></li>
        <li>In shock (inotropes): <span class="b-count"><?= $b['excluded_inotropes'] ?? 0 ?></span></li>
        <li>Mechanical ventilation: <span class="b-count"><?= $b['excluded_mech_vent'] ?? 0 ?></span></li>
      </ul>
    </div>
  </div>
  <div class="arrow"></div>

  <!-- ── Box 3: Eligible ─────────────────────────────────────────────────── -->
  <div class="row-wrap">
    <div class="filler"></div>
    <div class="box-main">
      <div class="label">3. Eligible for M-SNCU care</div>
      <div class="count"><?= $b['eligible'] ?? 0 ?></div>
      <div class="sub">
        <span class="arm"><strong>Inborn:</strong> <?= $b['eligible_inborn']  ?? 0 ?></span>
        <span class="arm"><strong>Outborn:</strong> <?= $b['eligible_outborn'] ?? 0 ?></span>
      </div>
      <div class="sub" style="margin-top:2px;font-style:italic">scr_beligenr = 11</div>
    </div>
    <div class="branch branch-right">
      <div class="b-title">Pre-consent dropouts</div>
      <ul>
        <li>Refused consent: <span class="b-count"><?= $b['refused_consent'] ?? 0 ?></span></li>
        <li>Bed shortage in M-SNCU: <span class="b-count"><?= $b['bed_shortage'] ?? 0 ?></span></li>
        <li>Transferred to non-iKMC facility: <span class="b-count"><?= $b['transferred_other'] ?? 0 ?></span></li>
        <li>Transferred to iKMC facility: <span class="b-count"><?= $b['transferred_ikmc'] ?? 0 ?></span></li>
        <li>Discharged before enrolment: <span class="b-count"><?= $b['discharged_before'] ?? 0 ?></span></li>
        <li>Died before enrolment: <span class="b-count"><?= $b['died_before'] ?? 0 ?></span></li>
        <li>LAMA before enrolment: <span class="b-count"><?= $b['lama_before'] ?? 0 ?></span></li>
        <li>Baby not admitted: <span class="b-count"><?= $b['not_admitted'] ?? 0 ?></span></li>
      </ul>
    </div>
  </div>
  <div class="arrow"></div>

  <!-- ── Box 4: Consented ────────────────────────────────────────────────── -->
  <div class="row-wrap">
    <div class="filler"></div>
    <div class="box-main">
      <div class="label">4. Eligible & Consented</div>
      <div class="count"><?= $b['consented'] ?? 0 ?></div>
      <div class="sub">
        <span class="arm"><strong>Inborn:</strong> <?= $b['consented_inborn']  ?? 0 ?></span>
        <span class="arm"><strong>Outborn:</strong> <?= $b['consented_outborn'] ?? 0 ?></span>
      </div>
      <div class="sub" style="margin-top:2px;font-style:italic">scr_mconst = 11</div>
    </div>
    <div class="branch branch-right">
      <div class="b-title">Consented but enrolment form not filled</div>
      <ul>
        <li>Awaiting enrolment: <span class="b-count"><?= $b['consented_no_enr'] ?? 0 ?></span></li>
      </ul>
    </div>
  </div>
  <div class="arrow"></div>

  <!-- ── Box 5: Enrolled ─────────────────────────────────────────────────── -->
  <div class="row-wrap">
    <div class="filler"></div>
    <div class="box-main" style="border-color:#047857;background:#ecfdf5">
      <div class="label">5. Enrolled (Primary cohort)</div>
      <div class="count" style="color:#047857"><?= $b['enrolled'] ?? 0 ?></div>
      <div class="sub">
        <span class="arm"><strong>Inborn:</strong> <?= $b['enrolled_inborn']  ?? 0 ?></span>
        <span class="arm"><strong>Outborn:</strong> <?= $b['enrolled_outborn'] ?? 0 ?></span>
      </div>
      <div class="sub" style="margin-top:2px;font-style:italic">enr_dof is not null</div>
    </div>
    <div class="branch branch-right">
      <div class="b-title">Still in hospital under follow-up</div>
      <ul>
        <li>Currently admitted: <span class="b-count"><?= $b['still_in_hospital'] ?? 0 ?></span></li>
      </ul>
    </div>
  </div>
  <div class="arrow"></div>

  <!-- ── Box 6: Outcome Complete ─────────────────────────────────────────── -->
  <div class="row-wrap">
    <div class="filler"></div>
    <div class="box-main">
      <div class="label">6. Completed outcome assessment</div>
      <div class="count"><?= $b['outcome_complete'] ?? 0 ?></div>
      <div class="sub" style="font-style:italic">dis_dof is not null</div>

      <div class="outcomes-grid">
        <div class="outcome-card">
          <div class="o-label">Discharged to home</div>
          <div class="o-count"><?= $b['outcome_discharged'] ?? 0 ?></div>
        </div>
        <div class="outcome-card">
          <div class="o-label">Transfer (non-study)</div>
          <div class="o-count"><?= $b['outcome_transfer'] ?? 0 ?></div>
        </div>
        <div class="outcome-card">
          <div class="o-label">LAMA</div>
          <div class="o-count"><?= $b['outcome_lama'] ?? 0 ?></div>
        </div>
        <div class="outcome-card dead">
          <div class="o-label">Death</div>
          <div class="o-count"><?= $b['outcome_death'] ?? 0 ?></div>
        </div>
      </div>
    </div>
    <div class="filler"></div>
  </div>

</div>

</body>
</html>
<?php
        return ob_get_clean();
    }
}

<?php

namespace CEL\Projects\Emollient\Export;

use CEL\Shared\Domain\Export\ExporterInterface;

/**
 * MonthlySiteSummaryHtmlExporter
 *
 * Renders the TSC monthly report: Enrollment, LAMA, DOPR and SAE by site and
 * month, from MonthlySiteSummaryAggregator.
 *
 * Built to be lifted into a deck, not read on a screen:
 *   - charts are 16:9 and sized for a slide, each with its own PNG download
 *     at 2x so it lands sharp in PowerPoint
 *   - site checkboxes filter the table and every chart together, so "just JSS
 *     and SNMC" is two clicks and a download
 *   - everything is inline — one self-contained file with no CSS, JS or font
 *     fetched from anywhere, so a saved copy still works offline and in an
 *     email attachment
 *
 * Palette: the eight-slot categorical order, validated for colour-vision
 * deficiency in both light and dark mode on adjacent pairs (the right pairlist
 * for stacks and bars). Light mode's mid-tones fall below 3:1 against the
 * surface, which obliges visible labels or a table — this report carries the
 * table, and series are direct-labelled wherever there are four or fewer.
 */
class MonthlySiteSummaryHtmlExporter implements ExporterInterface
{
    private bool   $inline;
    private string $toolbar;

    /** Categorical slots, in fixed order. Never cycled, never reordered. */
    private const SERIES_LIGHT = ['#2a78d6','#eb6834','#1baf7a','#eda100',
                                  '#e87ba4','#008300','#4a3aa7','#e34948'];
    private const SERIES_DARK  = ['#3987e5','#d95926','#199e70','#c98500',
                                  '#d55181','#008300','#9085e9','#e66767'];

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->toolbar = $toolbar;
    }

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->render($payload);

        if ($outputPath) {
            file_put_contents($outputPath, $html);
            return;
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
    }

    public function exportSection(
        \Traversable|array $data,
        string $section,
        bool $inline = false,
        ?string $outputPath = null
    ): void {
        $this->export($data, $outputPath);
    }

    // =====================================================================

    private function render(array $p): string
    {
        $months   = $p['months']       ?? [];
        $mlabels  = $p['month_labels'] ?? [];
        $sites    = $p['sites']        ?? [];
        $labels   = $p['site_labels']  ?? [];
        $measures = $p['measures']     ?? [];
        $period   = $p['period']       ?? [];
        $diag     = $p['diagnostics']  ?? [];

        if (!$months || !$sites) {
            return $this->shell('<div class="empty">No records matched this period and site selection.</div>', $period);
        }

        // Everything the page needs, handed to the browser once.
        $data = json_encode([
            'months'      => $months,
            'monthLabels' => $mlabels,
            'sites'       => $sites,
            'siteLabels'  => $labels,
            'measures'    => $measures,
            'event'       => $p['event']  ?? [],
            'cohort'      => $p['cohort'] ?? [],
            'eventSiteTotals'  => $p['event_site_totals']  ?? [],
            'cohortSiteTotals' => $p['cohort_site_totals'] ?? [],
            'seriesLight' => self::SERIES_LIGHT,
            'seriesDark'  => self::SERIES_DARK,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $body = $this->header($period)
              . $this->controls($sites, $labels)
              . $this->charts()
              . '<div id="tableHost"></div>'
              . $this->dataNotes($diag, $p['exclusions'] ?? [])
              . $this->definitions($p['definitions'] ?? []);

        return $this->shell($body, $period, $data);
    }

    // ── pieces ───────────────────────────────────────────────────────────

    private function header(array $period): string
    {
        $from = $this->e($period['date_from'] ?? '');
        $to   = $this->e($period['date_to']   ?? '');
        $gen  = $this->e($period['generated'] ?? '');

        return <<<HTML
        <header class="rep-head">
          <h1>Monthly Site Summary</h1>
          <p class="sub">Enrollment, LAMA, DOPR and SAE by site and month
             &middot; {$from} to {$to}
             &middot; generated {$gen}</p>
        </header>
        HTML;
    }

    private function controls(array $sites, array $labels): string
    {
        $boxes = '';
        foreach ($sites as $s) {
            $code  = $this->e($s);
            $label = $this->e($labels[$s] ?? $s);
            $boxes .= "<label class='chk'><input type='checkbox' class='site-box' value='{$code}' checked>"
                    . "<span>{$label}</span></label>";
        }

        return <<<HTML
        <section class="controls" role="group" aria-label="Report filters">
          <div class="ctl-row">
            <span class="ctl-label">Sites</span>
            <div class="chk-wrap">{$boxes}</div>
            <button type="button" class="btn" id="allSites">All</button>
            <button type="button" class="btn" id="noSites">None</button>
          </div>
          <div class="ctl-row">
            <span class="ctl-label">Count events in</span>
            <label class="chk"><input type="radio" name="basis" value="event" checked>
              <span>the month they happened</span></label>
            <label class="chk"><input type="radio" name="basis" value="cohort">
              <span>the month the baby was enrolled</span></label>
            <button type="button" class="btn" id="themeBtn" title="Toggle dark mode">Dark</button>
          </div>
          <div class="ctl-row" id="dlRow" hidden>
            <span class="ctl-label">Download</span>
            <button type="button" class="btn primary" id="dlExcel">Excel &mdash; ticked sites</button>
            <button type="button" class="btn primary" id="dlCsv">CSV &mdash; ticked sites</button>
            <span class="dl-note">Use these. The buttons in the toolbar at the top of the
              page know nothing about the tick boxes and always export <em>every</em> site;
              one of them is labelled &ldquo;Excel (Missing)&rdquo;, which is wording borrowed
              from the form-completion reports and means nothing here.</span>
          </div>
        </section>
        HTML;
    }

    private function charts(): string
    {
        $c = function (string $id, string $title, string $note) {
            return <<<HTML
            <figure class="chart-card">
              <figcaption>
                <span class="c-title">{$title}</span>
                <button type="button" class="btn png" data-chart="{$id}">PNG</button>
              </figcaption>
              <div class="svg-host" id="{$id}"></div>
              <p class="c-note">{$note}</p>
            </figure>
            HTML;
        };

        return '<section class="charts">'
             . $c('chartEnrol', 'Enrollment by month',
                  'Stacked by site. Hover a segment for the figure.')
             . $c('chartUnplanned', 'LAMA and DOPR by month',
                  'Across the selected sites.')
             . $c('chartRate', 'Unplanned discharge as a share of enrollment',
                  'LAMA + DOPR over enrollment, whole period. Sites with no enrollment are omitted.')
             . '</section>';
    }

    private function dataNotes(array $d, array $exclusions): string
    {
        $row = fn(string $l, $v, string $flag = '') =>
            '<tr><td>' . $this->e($l) . '</td><td class="num">' . (int)$v . '</td><td class="flag">' . $flag . '</td></tr>';

        $rows  = $row('Records in the export',                 $d['babies_seen']        ?? 0);
        $rows .= $row('No site, never enrolled',               $d['no_site_not_enrolled'] ?? 0,
                      'expected — prescreening instances');
        $rows .= $row('No site but enrolled',                  $d['no_site_enrolled']   ?? 0,
                      ($d['no_site_enrolled'] ?? 0) > 0 ? 'cannot be placed — worth chasing' : '');
        $rows .= $row('Enrolled with no enr_datetime',         $d['enrolled_no_date']   ?? 0);
        $rows .= $row('Enrolment date could not be read',      $d['enrolled_bad_date']  ?? 0);

        foreach (($d['discharge_no_date'] ?? []) as $type => $n) {
            $flag = in_array($type, ['TYP_LAMA','TYP_DOPR'], true) ? 'affects this report' : '';
            $rows .= $row($type . ' recorded with no discharge date', $n, $flag);
        }

        $q5only = (int)($d['sae_q5_only'] ?? 0);
        $rows .= $row('SAE forms present',                        $d['sae_form_present'] ?? 0);
        $rows .= $row('SAE with Q8 blank — not counted',           $d['sae_q8_missing']  ?? 0,
                      $q5only > 0 ? 'Q5 is filled on ' . $q5only . ' of these' : '');
        $rows .= $row('SAE Q8 present but unreadable',             $d['sae_q8_bad_date'] ?? 0);
        $rows .= $row('Discharge form site differs from enrolment',$d['site_mismatch_discharge'] ?? 0);
        $rows .= $row('SAE form site differs from enrolment',      $d['site_mismatch_sae']       ?? 0);

        $unpl = $d['cohort_unplaceable'] ?? [];
        $tot  = array_sum(array_map('intval', $unpl));
        $rows .= $row('Counted by event month, unplaceable by enrolment month', $tot,
                      $tot > 0 ? 'why the two bases differ' : '');

        $warn = '';
        if ($q5only > 0) {
            $warn = '<p class="warn"><strong>' . $q5only . ' SAE form(s) have Q5 filled and Q8 blank.</strong> '
                  . 'SAEs are dated by Q8 (sae_start_date), so these are absent from the counts above. '
                  . 'If this number is not near zero, Q8 is not the field sites are filling and the '
                  . 'dating field should be reconsidered before these figures are presented.</p>';
        }

        $exc = '';
        foreach ($exclusions as $x) $exc .= '<li>' . $this->e($x) . '</li>';

        return <<<HTML
        <section class="notes">
          <h2>Data notes — what is <em>not</em> in the figures above</h2>
          {$warn}
          <table class="notes-tbl"><tbody>{$rows}</tbody></table>
          <h3>Excluded by design</h3>
          <ul>{$exc}</ul>
        </section>
        HTML;
    }

    private function definitions(array $defs): string
    {
        $rows = '';
        foreach ($defs as $k => $v) {
            $rows .= '<tr><th>' . $this->e((string)$k) . '</th><td>' . $this->e((string)$v) . '</td></tr>';
        }
        return '<section class="defs"><h2>How each figure is counted</h2>'
             . '<table class="defs-tbl"><tbody>' . $rows . '</tbody></table></section>';
    }

    // ── shell ────────────────────────────────────────────────────────────

    private function shell(string $body, array $period, ?string $data = null): string
    {
        $css     = $this->css();
        $js      = $data !== null ? '<script>const REPORT = ' . $data . ';' . $this->js() . '</script>' : '';
        $toolbar = $this->toolbar;
        $title   = 'Monthly Site Summary — ' . $this->e($period['date_to'] ?? '');

        return <<<HTML
        <!doctype html>
        <html lang="en"><head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$title}</title>
        <style>{$css}</style>
        </head>
        <body class="viz-root">
        {$toolbar}
        <main class="wrap">{$body}</main>
        {$js}
        </body></html>
        HTML;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // =====================================================================
    // CSS
    // =====================================================================

    private function css(): string
    {
        return <<<'CSS'
:root {
  color-scheme: light;
  --surface-1: #fcfcfb; --surface-2: #f3f3f0; --line: #dedcd4;
  --text-primary: #0b0b0b; --text-secondary: #52514e; --text-muted: #7a7872;
  --accent: #2a78d6; --warn-bg: #fff4e5; --warn-line: #eb6834;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    color-scheme: dark;
    --surface-1: #1a1a19; --surface-2: #232321; --line: #3a3a37;
    --text-primary: #ffffff; --text-secondary: #c3c2b7; --text-muted: #918f85;
    --accent: #3987e5; --warn-bg: #2d2317; --warn-line: #d95926;
  }
}
:root[data-theme="dark"] {
  color-scheme: dark;
  --surface-1: #1a1a19; --surface-2: #232321; --line: #3a3a37;
  --text-primary: #ffffff; --text-secondary: #c3c2b7; --text-muted: #918f85;
  --accent: #3987e5; --warn-bg: #2d2317; --warn-line: #d95926;
}
* { box-sizing: border-box; }
body {
  margin: 0; background: var(--surface-1); color: var(--text-primary);
  font: 15px/1.5 "Segoe UI", Arial, Helvetica, sans-serif;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 1240px; margin: 0 auto; padding: 28px 16px 64px; }

.rep-head h1 { margin: 0 0 4px; font-size: 26px; letter-spacing: -0.01em; }
.rep-head .sub { margin: 0 0 22px; color: var(--text-secondary); font-size: 13px; }

.controls {
  background: var(--surface-2); border: 1px solid var(--line); border-radius: 10px;
  padding: 12px 14px; margin-bottom: 24px;
}
.ctl-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 14px; }
.ctl-row + .ctl-row { margin-top: 10px; padding-top: 10px; border-top: 1px solid var(--line); }
.ctl-label { font-size: 12px; text-transform: uppercase; letter-spacing: .06em;
             color: var(--text-muted); min-width: 118px; }
.chk-wrap { display: flex; flex-wrap: wrap; gap: 6px 14px; flex: 1 1 420px; }
.chk { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
.chk input { accent-color: var(--accent); }
.btn {
  font: inherit; font-size: 12px; padding: 4px 11px; cursor: pointer;
  background: var(--surface-1); color: var(--text-primary);
  border: 1px solid var(--line); border-radius: 6px;
}
.btn:hover { border-color: var(--accent); color: var(--accent); }
.btn.primary { background: var(--accent); color: #fff; border-color: var(--accent); font-weight: 600; }
.btn.primary:hover { filter: brightness(1.1); color: #fff; }
#dlRow .dl-note { flex: 1 1 340px; line-height: 1.45; }

.charts { display: grid; gap: 20px; margin-bottom: 28px; }
.chart-card {
  margin: 0; background: var(--surface-1); border: 1px solid var(--line);
  border-radius: 10px; padding: 14px 16px 10px;
}
.chart-card figcaption {
  display: flex; align-items: center; justify-content: space-between;
  gap: 12px; margin-bottom: 6px;
}
.c-title { font-size: 15px; font-weight: 600; }
.c-note { margin: 4px 0 0; font-size: 12px; color: var(--text-muted); }
.svg-host svg { display: block; width: 100%; height: auto; }

.dl-note { font-size: 12px; color: var(--text-muted); }
.legend { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 2px 0 8px; }
.legend span { display: inline-flex; align-items: center; gap: 6px;
               font-size: 12px; color: var(--text-secondary); }
.legend i { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }

table { border-collapse: collapse; width: 100%; font-size: 13px; }
caption { text-align: left; font-weight: 600; font-size: 14px; padding: 18px 0 7px; }
th, td { padding: 5px 9px; border-bottom: 1px solid var(--line); text-align: left; }
thead th { font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
           color: var(--text-muted); border-bottom: 1.5px solid var(--line); white-space: nowrap; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
tr.total td { font-weight: 700; border-top: 1.5px solid var(--line); background: var(--surface-2); }
td.zero { color: var(--text-muted); }
.tbl-card { background: var(--surface-1); border: 1px solid var(--line);
            border-radius: 10px; padding: 2px 16px 14px; margin-bottom: 18px; overflow-x: auto; }

.notes, .defs { margin-top: 30px; border-top: 2px solid var(--line); padding-top: 14px; }
.notes h2, .defs h2 { font-size: 16px; margin: 0 0 10px; }
.notes h3 { font-size: 13px; margin: 18px 0 6px; color: var(--text-secondary); }
.notes-tbl td.flag { color: var(--warn-line); font-size: 12px; }
.notes ul { margin: 0; padding-left: 18px; font-size: 13px; color: var(--text-secondary); }
.notes li { margin-bottom: 4px; }
.warn { background: var(--warn-bg); border-left: 3px solid var(--warn-line);
        padding: 9px 12px; font-size: 13px; margin: 0 0 14px; border-radius: 0 6px 6px 0; }
.defs-tbl th { width: 130px; vertical-align: top; font-weight: 600; color: var(--text-secondary); }
.defs-tbl td { color: var(--text-secondary); }
.empty { padding: 40px; text-align: center; color: var(--text-muted); }

.tip {
  position: fixed; pointer-events: none; z-index: 20; opacity: 0;
  background: var(--text-primary); color: var(--surface-1);
  font-size: 12px; padding: 5px 9px; border-radius: 6px; white-space: nowrap;
  transition: opacity .09s;
}
@media print {
  .controls, .btn { display: none !important; }
  .chart-card, .tbl-card { break-inside: avoid; border-color: #ccc; }
}
CSS;
    }

    // =====================================================================
    // JS — chart drawing, filtering, PNG export
    // =====================================================================

    private function js(): string
    {
        return <<<'JS'
(function () {
  const NS = 'http://www.w3.org/2000/svg';
  const el = (t, a = {}) => { const n = document.createElementNS(NS, t);
    for (const k in a) n.setAttribute(k, a[k]); return n; };

  const tip = document.createElement('div');
  tip.className = 'tip'; document.body.appendChild(tip);
  const showTip = (e, html) => {
    tip.innerHTML = html; tip.style.opacity = 1;
    tip.style.left = Math.min(e.clientX + 12, innerWidth - tip.offsetWidth - 8) + 'px';
    tip.style.top  = (e.clientY - 30) + 'px';
  };
  const hideTip = () => { tip.style.opacity = 0; };

  // A label sitting ON a filled mark has to contrast with that fill, not with
  // the page. Compute it rather than guess: white reads on the blue and the
  // violet, but not on the yellow.
  const lum = hex => {
    const v = i => {
      const c = parseInt(hex.slice(i, i + 2), 16) / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    };
    return 0.2126 * v(1) + 0.7152 * v(3) + 0.0722 * v(5);
  };
  const onFill = hex => (lum(hex) > 0.42 ? '#111111' : '#ffffff');

  const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark' ||
    (!document.documentElement.getAttribute('data-theme') &&
      matchMedia('(prefers-color-scheme: dark)').matches);
  const palette = () => isDark() ? REPORT.seriesDark : REPORT.seriesLight;
  const ink  = () => getComputedStyle(document.body).getPropertyValue('--text-secondary').trim();
  const ink2 = () => getComputedStyle(document.body).getPropertyValue('--text-muted').trim();
  const grid = () => getComputedStyle(document.body).getPropertyValue('--line').trim();
  const surf = () => getComputedStyle(document.body).getPropertyValue('--surface-1').trim();

  const selectedSites = () =>
    [...document.querySelectorAll('.site-box')].filter(b => b.checked).map(b => b.value);
  const basis = () => document.querySelector('input[name=basis]:checked').value;
  const cell = (site, month, measure) => {
    const g = REPORT[basis()];
    return (g[site] && g[site][month] && g[site][month][measure]) || 0;
  };
  const label = s => REPORT.siteLabels[s] || s;

  // ── shared chart frame ───────────────────────────────────────────────
  // 16:9 at 960x540 so a downloaded PNG drops onto a slide without resizing.
  const W = 960, H = 540, M = { t: 18, r: 20, b: 64, l: 62 };

  // h defaults to 16:9. The per-site bar chart overrides it: with three sites
  // selected a 16:9 canvas is four-fifths empty, and empty canvas on a slide
  // reads as missing data rather than as spacing.
  function frame(host, h) {
    const ht = h || H;
    host.innerHTML = '';
    const svg = el('svg', { viewBox: `0 0 ${W} ${ht}`, width: W, height: ht,
                            'font-family': 'Segoe UI, Arial, sans-serif' });
    svg.appendChild(el('rect', { x: 0, y: 0, width: W, height: ht, fill: surf() }));
    svg.dataset.h = ht;
    host.appendChild(svg);
    return svg;
  }

  function yAxis(svg, max, plotH, plotW) {
    const ticks = niceTicks(max);
    ticks.forEach(v => {
      const y = M.t + plotH - (v / ticks[ticks.length - 1]) * plotH;
      svg.appendChild(el('line', { x1: M.l, x2: M.l + plotW, y1: y, y2: y,
        stroke: grid(), 'stroke-width': v === 0 ? 1.5 : 1 }));
      const t = el('text', { x: M.l - 9, y: y + 4, 'text-anchor': 'end',
        'font-size': 12, fill: ink2() });
      t.textContent = v; svg.appendChild(t);
    });
    return ticks[ticks.length - 1];
  }

  function niceTicks(max) {
    if (max <= 0) return [0, 1];
    const raw = max / 5, mag = Math.pow(10, Math.floor(Math.log10(raw)));
    const step = [1, 2, 2.5, 5, 10].find(s => s * mag >= raw) * mag;
    const out = []; for (let v = 0; v <= max + step * 0.001; v += step) out.push(+v.toFixed(6));
    if (out[out.length - 1] < max) out.push(+(out[out.length - 1] + step).toFixed(6));
    return out;
  }

  function xLabels(svg, labels, plotW, plotH) {
    const band = plotW / labels.length;
    labels.forEach((l, i) => {
      const t = el('text', { x: M.l + band * (i + 0.5), y: M.t + plotH + 20,
        'text-anchor': 'middle', 'font-size': 12, fill: ink2() });
      t.textContent = l; svg.appendChild(t);
    });
    return band;
  }

  function legend(hostId, items) {
    const host = document.getElementById(hostId);
    const old = host.previousElementSibling;
    if (old && old.classList.contains('legend')) old.remove();
    if (items.length < 2) return;
    const d = document.createElement('div');
    d.className = 'legend';
    items.forEach(it => {
      const s = document.createElement('span');
      s.innerHTML = `<i style="background:${it.color}"></i>${it.label}`;
      d.appendChild(s);
    });
    host.parentNode.insertBefore(d, host);
  }

  // ── 1. Enrollment by month, stacked by site ──────────────────────────
  function drawEnrol() {
    const host = document.getElementById('chartEnrol');
    const sites = selectedSites(), months = REPORT.months, pal = palette();
    const svg = frame(host);
    if (!sites.length) return legend('chartEnrol', []);

    // Never cycle hues: past eight sites the rest fold into one "Other" band.
    const shown = sites.slice(0, 8), other = sites.slice(8);
    const series = shown.map((s, i) => ({ site: s, label: label(s), color: pal[i] }));
    if (other.length) series.push({ site: null, color: ink2(),
      label: `Other (${other.length} site${other.length > 1 ? 's' : ''})` });

    const totals = months.map(m => sites.reduce((a, s) => a + cell(s, m, 'enrolled'), 0));
    const plotW = W - M.l - M.r, plotH = H - M.t - M.b;
    const top = yAxis(svg, Math.max(...totals, 1), plotH, plotW);
    const band = xLabels(svg, REPORT.monthLabels, plotW, plotH);
    const bw = Math.min(band * 0.62, 56);

    months.forEach((m, mi) => {
      let acc = 0;
      series.forEach(se => {
        const v = se.site ? cell(se.site, m, 'enrolled')
                          : other.reduce((a, s) => a + cell(s, m, 'enrolled'), 0);
        if (v <= 0) return;
        const h  = (v / top) * plotH;
        const y  = M.t + plotH - ((acc + v) / top) * plotH;
        // 2px surface gap between stacked segments keeps the boundary legible
        const r = el('rect', { x: M.l + band * (mi + 0.5) - bw / 2, y: y,
          width: bw, height: Math.max(h - 2, 1), fill: se.color, rx: 2 });
        r.addEventListener('mousemove', e =>
          showTip(e, `<strong>${se.label}</strong><br>${REPORT.monthLabels[mi]}: ${v} enrolled`));
        r.addEventListener('mouseleave', hideTip);
        svg.appendChild(r);

        // Value inside the segment. A downloaded PNG has no hover, so without
        // this a shared chart shows only the month total and the reader cannot
        // recover which site contributed what.
        if (h >= 15) {
          const lab = el('text', {
            x: M.l + band * (mi + 0.5), y: y + (h - 2) / 2 + 4,
            'text-anchor': 'middle', 'font-size': 11, 'font-weight': 600,
            fill: onFill(se.color), 'pointer-events': 'none',
          });
          lab.textContent = v;
          svg.appendChild(lab);
        }
        acc += v;
      });
      if (totals[mi] > 0) {
        const t = el('text', { x: M.l + band * (mi + 0.5),
          y: M.t + plotH - (totals[mi] / top) * plotH - 6,
          'text-anchor': 'middle', 'font-size': 11, 'font-weight': 600, fill: ink() });
        t.textContent = totals[mi]; svg.appendChild(t);
      }
    });
    legend('chartEnrol', series);
  }

  // ── 2. LAMA and DOPR by month, grouped ───────────────────────────────
  function drawUnplanned() {
    const host = document.getElementById('chartUnplanned');
    const sites = selectedSites(), months = REPORT.months, pal = palette();
    const svg = frame(host);
    const series = [{ key: 'lama', label: 'LAMA', color: pal[0] },
                    { key: 'dopr', label: 'DOPR', color: pal[1] }];
    legend('chartUnplanned', series);
    if (!sites.length) return;

    const vals = months.map(m => series.map(s =>
      sites.reduce((a, si) => a + cell(si, m, s.key), 0)));
    const plotW = W - M.l - M.r, plotH = H - M.t - M.b;
    const top = yAxis(svg, Math.max(1, ...vals.flat()), plotH, plotW);
    const band = xLabels(svg, REPORT.monthLabels, plotW, plotH);
    const bw = Math.min(band * 0.30, 26);

    months.forEach((m, mi) => {
      series.forEach((s, si) => {
        const v = vals[mi][si];
        if (v <= 0) return;
        const h = (v / top) * plotH;
        const x = M.l + band * (mi + 0.5) + (si - 0.5) * (bw + 2) - bw / 2 + bw / 2;
        const r = el('rect', { x: x - bw / 2, y: M.t + plotH - h,
          width: bw, height: h, fill: s.color, rx: 2 });
        r.addEventListener('mousemove', e =>
          showTip(e, `<strong>${s.label}</strong><br>${REPORT.monthLabels[mi]}: ${v}`));
        r.addEventListener('mouseleave', hideTip);
        svg.appendChild(r);

        // Above the bar — these are separate bars, not a stack, so there is
        // room, and the number survives being shared as an image.
        const lab = el('text', { x: x, y: M.t + plotH - h - 5, 'text-anchor': 'middle',
          'font-size': 11, 'font-weight': 600, fill: ink(), 'pointer-events': 'none' });
        lab.textContent = v;
        svg.appendChild(lab);
      });
    });
  }

  // ── 3. Unplanned share by site ───────────────────────────────────────
  function drawRate() {
    const host = document.getElementById('chartRate');
    const sites = selectedSites(), pal = palette();
    legend('chartRate', []);

    const tot = basis() === 'event' ? REPORT.eventSiteTotals : REPORT.cohortSiteTotals;
    const rows = sites.map(s => {
      const t = tot[s] || {};
      return { site: s, label: label(s), enr: t.enrolled || 0, unp: t.unplanned || 0,
               pct: t.enrolled ? (t.unplanned / t.enrolled) * 100 : null };
    }).filter(r => r.pct !== null).sort((a, b) => b.pct - a.pct);

    const rowH = 52;
    const svg  = frame(host, Math.min(H, Math.max(140, rows.length * rowH + 52)));
    if (!rows.length) return;

    const max = Math.max(...rows.map(r => r.pct), 1);
    const L = 210;
    const y0 = 26;

    rows.forEach((r, i) => {
      const y = y0 + i * rowH;
      const w = (r.pct / max) * (W - L - 110);
      const t = el('text', { x: L - 10, y: y + rowH / 2 + 4, 'text-anchor': 'end',
        'font-size': 13, fill: ink() });
      t.textContent = r.label.length > 26 ? r.label.slice(0, 25) + '…' : r.label;
      svg.appendChild(t);

      const bar = el('rect', { x: L, y: y + rowH * 0.18, width: Math.max(w, 1),
        height: rowH * 0.64, fill: pal[0], rx: 3 });
      bar.addEventListener('mousemove', e =>
        showTip(e, `<strong>${r.label}</strong><br>${r.unp} of ${r.enr} enrolled`));
      bar.addEventListener('mouseleave', hideTip);
      svg.appendChild(bar);

      // direct label — the table carries the rest, so one number per bar
      const v = el('text', { x: L + w + 9, y: y + rowH / 2 + 4, 'font-size': 12,
        'font-weight': 600, fill: ink() });
      v.textContent = r.pct.toFixed(1) + '%  (' + r.unp + '/' + r.enr + ')';
      svg.appendChild(v);
    });
  }

  // ── table ────────────────────────────────────────────────────────────
  function drawTable() {
    const sites = selectedSites(), months = REPORT.months;
    const host = document.getElementById('tableHost');
    const tot = basis() === 'event' ? REPORT.eventSiteTotals : REPORT.cohortSiteTotals;
    const mk = Object.keys(REPORT.measures);

    let h = '<div class="tbl-card"><table><caption>Summary — whole period</caption><thead><tr><th>Site</th>';
    mk.forEach(k => h += `<th class="num">${REPORT.measures[k]}</th>`);
    h += '<th class="num">Unplanned %</th></tr></thead><tbody>';

    const sum = {}; mk.forEach(k => sum[k] = 0);
    sites.forEach(s => {
      const t = tot[s] || {};
      h += `<tr><td>${label(s)}</td>`;
      mk.forEach(k => { const v = t[k] || 0; sum[k] += v;
        h += `<td class="num${v ? '' : ' zero'}">${v || '–'}</td>`; });
      const p = t.enrolled ? ((t.unplanned / t.enrolled) * 100).toFixed(1) + '%' : '–';
      h += `<td class="num">${p}</td></tr>`;
    });
    h += '<tr class="total"><td>All selected sites</td>';
    mk.forEach(k => h += `<td class="num">${sum[k]}</td>`);
    h += `<td class="num">${sum.enrolled ? ((sum.unplanned / sum.enrolled) * 100).toFixed(1) + '%' : '–'}</td>`;
    h += '</tr></tbody></table></div>';

    // one month grid per measure
    mk.forEach(k => {
      h += `<div class="tbl-card"><table><caption>${REPORT.measures[k]} by month</caption><thead><tr><th>Site</th>`;
      REPORT.monthLabels.forEach(m => h += `<th class="num">${m}</th>`);
      h += '<th class="num">Total</th></tr></thead><tbody>';
      const colSum = months.map(() => 0);
      sites.forEach(s => {
        h += `<tr><td>${label(s)}</td>`;
        let rs = 0;
        months.forEach((m, i) => { const v = cell(s, m, k); rs += v; colSum[i] += v;
          h += `<td class="num${v ? '' : ' zero'}">${v || '–'}</td>`; });
        h += `<td class="num">${rs}</td></tr>`;
      });
      h += '<tr class="total"><td>Total</td>';
      colSum.forEach(v => h += `<td class="num">${v || '–'}</td>`);
      h += `<td class="num">${colSum.reduce((a, b) => a + b, 0)}</td></tr></tbody></table></div>`;
    });

    host.innerHTML = h;
  }

  // ── PNG at 2x, so it lands sharp in a slide ──────────────────────────
  function toPng(hostId) {
    const svg = document.querySelector('#' + hostId + ' svg');
    if (!svg) return;
    const xml  = new XMLSerializer().serializeToString(svg);
    const img  = new Image();
    const blob = new Blob([xml], { type: 'image/svg+xml;charset=utf-8' });
    const url  = URL.createObjectURL(blob);
    img.onload = function () {
      const c = document.createElement('canvas');
      c.width = W * 2; c.height = (+svg.dataset.h || H) * 2;
      const ctx = c.getContext('2d');
      ctx.fillStyle = surf(); ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      c.toBlob(b => {
        const a = document.createElement('a');
        a.href = URL.createObjectURL(b);
        a.download = hostId.replace('chart', 'monthly_') + '.png';
        a.click();
      });
    };
    img.src = url;
  }

  // ── wiring ───────────────────────────────────────────────────────────
  function redraw() { drawEnrol(); drawUnplanned(); drawRate(); drawTable(); }

  document.querySelectorAll('.site-box').forEach(b => b.addEventListener('change', redraw));
  document.querySelectorAll('input[name=basis]').forEach(r => r.addEventListener('change', redraw));
  document.getElementById('allSites').onclick = () => {
    document.querySelectorAll('.site-box').forEach(b => b.checked = true); redraw(); };
  document.getElementById('noSites').onclick = () => {
    document.querySelectorAll('.site-box').forEach(b => b.checked = false); redraw(); };
  document.querySelectorAll('.png').forEach(b =>
    b.onclick = () => toPng(b.getAttribute('data-chart')));

  // ── exports that honour the tick boxes ───────────────────────────────
  // The checkboxes filter this page in the browser; a CSV or Excel download
  // is a fresh server request, so the selection has to travel in the URL or
  // the file comes back containing every site. These buttons put it there.
  function exportUrl(fmt) {
    const q = new URLSearchParams(location.search);
    q.set('format', fmt);
    q.set('sites', selectedSites().join(','));   // always explicit
    return location.pathname + '?' + q.toString();
  }

  const dlRow = document.getElementById('dlRow');
  // Only meaningful when the engine served this page. A saved copy opened
  // from disk has no query string and no server to ask.
  if (dlRow && /[?&]report=/.test(location.search)) {
    dlRow.hidden = false;
    const go = fmt => {
      if (!selectedSites().length) {
        alert('Tick at least one site first — the download follows the selection above.');
        return;
      }
      location.href = exportUrl(fmt);
    };
    document.getElementById('dlExcel').onclick = () => go('excel');
    document.getElementById('dlCsv').onclick   = () => go('csv');
  }
  document.getElementById('themeBtn').onclick = function () {
    const dark = isDark();
    document.documentElement.setAttribute('data-theme', dark ? 'light' : 'dark');
    this.textContent = dark ? 'Dark' : 'Light';
    redraw();
  };
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', redraw);

  redraw();
})();
JS;
    }
}

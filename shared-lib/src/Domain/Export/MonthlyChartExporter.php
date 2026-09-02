<?php

namespace CEL\Shared\Domain\Export;

/**
 * MonthlyEnrollmentChartExporter
 *
 * Renders a toggleable enrollment chart with two views:
 *
 * View 1 — Stacked Bar (default)
 *   - One stacked bar per month, segments coloured by site
 *   - Data labels always visible inside each segment
 *   - Cumulative grand total line on secondary right Y-axis
 *
 * View 2 — Site-wise Line
 *   - One line per site (original view)
 *   - Dashed total line (if showTotalLine = true)
 *
 * No summary table is rendered for this chart.
 */
class MonthlyChartExporter extends ChartExporter
{
    protected bool $showTotalLine = true;

    private string $jsPath;

    private string $defaultView;

    public function __construct(
        bool   $inline      = false,
        string $cssPath     = '/assets/css/weekly_enrollment.css',
        string $jsPath      = '/assets/js/monthly_enrollment_chart.js',
        string $toolbar     = '',
        string $defaultView = 'stacked'
    ) {
        parent::__construct($inline, $cssPath, $toolbar);
        $this->jsPath      = $jsPath;
        $this->defaultView = $defaultView;
    }

    protected function periodsKey(): string     { return 'months'; }
    protected function canvasId(): string       { return 'monthlyChart'; }
    protected function axisLabel(): string      { return 'Month'; }
    protected function chartTitle(): string     { return 'Month-wise'; }
    protected function defaultCssPath(): string { return '/assets/css/weekly_enrollment.css'; }

    // ── Override export() to render our own full page ─────────────────────────

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->renderMonthly($payload);

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

    // ── Full page renderer ────────────────────────────────────────────────────

    private function renderMonthly(array $payload): string
    {
        $periods      = $payload[$this->periodsKey()];
        $sites        = $payload['sites'];
        $counts       = $payload['counts'];
        $periodTotals = $payload['period_totals'] ?? [];
        $dateFrom     = $payload['date_from'];
        $dateTo       = $payload['date_to'];
        $currentDate  = (new \DateTime())->format('Y-m-d H:i');
        $title        = "Month-wise Site-wise Enrollment ({$dateFrom} – {$dateTo})";

        // ── Stacked bar datasets (one per site) ───────────────────────────────
        $stackedDatasets = [];
        foreach ($sites as $i => $site)
        {
            $color = $this->palette[$i % count($this->palette)];
            $stackedDatasets[] = [
                'type'            => 'bar',
                'label'           => $site,
                'data'            => array_map(fn($p) => $counts[$site][$p] ?? 0, $periods),
                'backgroundColor' => $color,
                'borderColor'     => $color,
                'borderWidth'     => 1,
                'stack'           => 'enrollment',
                'yAxisID'         => 'yLeft',
            ];
        }

        // Cumulative line on right axis
        $cumulative = [];
        $running    = 0;
        foreach ($periods as $p)
        {
            $running     += $periodTotals[$p] ?? 0;
            $cumulative[] = $running;
        }

        $stackedDatasets[] = [
            'label'           => 'Cumulative',
            'data'            => $cumulative,
            'type'            => 'line',
            'borderColor'     => '#111111',
            'backgroundColor' => '#111111',
            'borderWidth'     => 2,
            'pointRadius'     => 5,
            'pointStyle'      => 'circle',
            'borderDash'      => [],
            'fill'            => false,
            'yAxisID'         => 'yRight',
            'stack'           => '',          // not part of the bar stack
        ];

        // ── Line chart datasets (one per site + total) ────────────────────────
        $lineDatasets = [];
        foreach ($sites as $i => $site)
        {
            $color = $this->palette[$i % count($this->palette)];
            $lineDatasets[] = [
                'label'            => $site,
                'data'             => array_map(fn($p) => $counts[$site][$p] ?? 0, $periods),
                'borderColor'      => $color,
                'backgroundColor'  => $color,
                'pointRadius'      => 5,
                'pointHoverRadius' => 7,
                'tension'          => 0.1,
                'fill'             => false,
                'yAxisID'          => 'yLeft',
            ];
        }

        if ($this->showTotalLine)
        {
            $lineDatasets[] = [
                'label'            => 'Monthly Total',
                'data'             => array_map(fn($p) => $periodTotals[$p] ?? 0, $periods),
                'borderColor'      => '#000000',
                'backgroundColor'  => '#000000',
                'borderDash'       => [6, 3],
                'pointRadius'      => 5,
                'pointHoverRadius' => 7,
                'tension'          => 0.1,
                'fill'             => false,
                'yAxisID'          => 'yLeft',
            ];

            $lineDatasets[] = [
                'label'            => 'Cumulative',
                'data'             => $cumulative,
                'borderColor'      => '#444444',
                'backgroundColor'  => '#444444',
                'borderDash'       => [],
                'borderWidth'      => 2,
                'pointRadius'      => 5,
                'pointHoverRadius' => 7,
                'pointStyle'       => 'circle',
                'tension'          => 0.1,
                'fill'             => false,
                'yAxisID'          => 'yRight',
            ];
        }

        $xLabels        = json_encode($periods);
        $stackedJson    = json_encode($stackedDatasets, JSON_PRETTY_PRINT);
        $lineJson       = json_encode($lineDatasets,    JSON_PRETTY_PRINT);
        $titleJson      = json_encode($title);
        $axisLabelJson  = json_encode($this->axisLabel());
        $headTags       = $this->htmlHead();
        $jsBlock        = $this->jsHead($xLabels, $stackedJson, $lineJson, $titleJson, $axisLabelJson, $this->defaultView);

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <title>Monthly Enrollment Chart</title>
        {$headTags}
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        </head>
        <body>
        {$this->toolbar}
        <div class="chart-wrap">
          <h2 class="chart-title">Enrolments
            <span class="report-date">Report Date: {$currentDate}</span>
          </h2>

          <div class="toggle-bar">
            <button class="toggle-btn" id="btnStacked" data-view="stacked" onclick="switchView('stacked')">
              Stacked Bar
            </button>
            <button class="toggle-btn" id="btnLine" data-view="line" onclick="switchView('line')">
              Site Lines
            </button>
          </div>

          <div class="chart-container">
            <canvas id="monthlyChart"></canvas>
          </div>
        </div>

        {$jsBlock}
        </body>
        </html>
        HTML;
    }

    // ── JS delivery — data inline, behaviour external (or embedded for PDF) ───

    private function jsHead(
        string $xLabels,
        string $stackedJson,
        string $lineJson,
        string $titleJson,
        string $axisLabelJson,
        string $defaultView = 'stacked'
    ): string 
    {
        // Data variables are always inline — they are PHP-generated per request
        $dataBlock = <<<JS
        <script>
        const xLabels       = {$xLabels};
        const stackedDs     = {$stackedJson};
        const lineDs        = {$lineJson};
        const titleText     = {$titleJson};
        const axisLabelText = {$axisLabelJson};
        const defaultView   = '{$defaultView}';
        </script>
        JS;

        if ($this->inline)
        {
            // PDF / download mode — embed behaviour directly so the file is self-contained
            $jsFile = __DIR__ . '/../../../../reporting-engine/public/assets/js/monthly_enrollment_chart.js';
            $behaviour = file_exists($jsFile)
                ? "<script>
" . file_get_contents($jsFile) . "
</script>"
                : '<!-- monthly_enrollment_chart.js not found -->';
        }
        else
        {
            // Web mode — link external file; browser caches it across requests
            $behaviour = "<script src='{$this->jsPath}'></script>";
        }

        return $dataBlock . "
        " . $behaviour;
    }

}

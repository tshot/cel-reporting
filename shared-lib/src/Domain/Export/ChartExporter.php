<?php

namespace CEL\Shared\Domain\Export;

/**
 * ChartExporter (abstract base)
 *
 * Generic rendering engine for period-wise, site-wise line charts.
 *
 * Concrete subclasses declare only what differs between chart variants:
 *   periodsKey()      — payload key holding the period labels ('weeks' / 'months')
 *   canvasId()        — HTML canvas element id
 *   axisLabel()       — x-axis label text
 *   chartTitle()      — title prefix ("Week-wise" / "Month-wise")
 *   defaultCssPath()  — fallback CSS href
 */
abstract class ChartExporter implements ExporterInterface
{
    protected bool   $inline;
    protected string $cssPath;
    protected string $toolbar;

    protected bool $showTotalLine = true;

    protected array $palette = [
        '#1f77b4', '#ff7f0e', '#2ca02c', '#d62728',
        '#9467bd', '#8c564b', '#e377c2', '#7f7f7f',
    ];

    public function __construct(
        bool   $inline  = false,
        string $cssPath = '',
        string $toolbar = ''
    ) {
        $this->inline  = $inline;
        $this->cssPath = $cssPath ?: $this->defaultCssPath();
        $this->toolbar = $toolbar;
    }

    abstract protected function periodsKey(): string;
    abstract protected function canvasId(): string;
    abstract protected function axisLabel(): string;
    abstract protected function chartTitle(): string;
    abstract protected function defaultCssPath(): string;

    public function export(\Traversable|array $data, ?string $outputPath = null): void
    {
        $payload = is_array($data) ? $data : iterator_to_array($data);
        $html    = $this->render($payload);

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

    private function render(array $payload): string
    {
        $periods     = $payload[$this->periodsKey()];
        $currentDate = (new \DateTime())->format('Y-m-d H:i');
        $title       = "{$this->chartTitle()} Site-wise Enrollment Trend"
                     . " ({$payload['date_from']} – {$payload['date_to']})";

        $dsJson    = json_encode($this->buildDatasets($payload), JSON_PRETTY_PRINT);
        $xLabels   = json_encode($periods);
        $titleJson = json_encode($title);
        $axisLabel = json_encode($this->axisLabel());
        $canvasId  = $this->canvasId();
        $tableHtml  = $this->renderTable($payload);
        $headTags   = $this->htmlHead();
        $dataScript = $this->chartDataScript($canvasId, $xLabels, $dsJson, $titleJson, $axisLabel);
        $chartJs    = $this->chartJsTag();

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="UTF-8">
        <title>Enrollment Chart</title>
        {$headTags}
        <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
        </head>
        <body>
        {$this->toolbar}
        <div class="weekly-wrap">
          <h2 class="weekly-title">Enrolments
            <span class="report-date">Report Date: {$currentDate}</span>
          </h2>
          <canvas id="{$canvasId}"></canvas>
        </div>
        {$tableHtml}
        {$dataScript}
        {$chartJs}
        </body>
        </html>
        HTML;
    }

    private function buildDatasets(array $payload): array
    {
        $periods      = $payload[$this->periodsKey()];
        $sites        = $payload['sites'];
        $counts       = $payload['counts'];
        $periodTotals = $payload['period_totals'] ?? [];
        $datasets     = [];

        foreach ($sites as $i => $site)
        {
            $color      = $this->palette[$i % count($this->palette)];
            $datasets[] = [
                'label'            => $site,
                'data'             => array_map(fn($p) => $counts[$site][$p] ?? 0, $periods),
                'yAxisID'          => 'y',
                'borderColor'      => $color,
                'backgroundColor'  => $color,
                'pointRadius'      => 5,
                'pointHoverRadius' => 7,
                'tension'          => 0.1,
                'fill'             => false,
            ];
        }

        if ($this->showTotalLine)
        {
            $datasets[] = [
                'label'            => 'Total',
                'data'             => array_map(fn($p) => $periodTotals[$p] ?? 0, $periods),
                'yAxisID'          => 'yTotal',
                'borderColor'      => '#000000',
                'backgroundColor'  => '#000000',
                'borderDash'       => [6, 3],
                'pointRadius'      => 5,
                'pointHoverRadius' => 7,
                'tension'          => 0.1,
                'fill'             => false,
            ];
        }

        return $datasets;
    }

    private function renderTable(array $payload): string
    {
        $periods      = $payload[$this->periodsKey()];
        $sites        = $payload['sites'];
        $counts       = $payload['counts'];
        $siteTotals   = $payload['site_totals']   ?? [];
        $periodTotals = $payload['period_totals'] ?? [];
        $grandTotal   = $payload['grand_total']   ?? 0;

        $headers = implode('', array_map(
            fn($p) => '<th>' . htmlspecialchars(str_replace("\n", ' ', $p)) . '</th>',
            $periods
        ));

        $bodyRows = '';
        foreach ($sites as $site)
        {
            $cells     = implode('', array_map(
                fn($p) => '<td>' . ($counts[$site][$p] ?? 0 ?: '–') . '</td>',
                $periods
            ));
            $bodyRows .= '<tr>'
                       . '<td>' . htmlspecialchars($site) . '</td>'
                       . $cells
                       . '<td><strong>' . ($siteTotals[$site] ?? 0) . '</strong></td>'
                       . '</tr>';
        }

        $totalCells = implode('', array_map(
            fn($p) => '<td><strong>' . ($periodTotals[$p] ?? 0 ?: '–') . '</strong></td>',
            $periods
        ));

        $totalRow = '<tr class="total-row">'
                  . '<td><strong>Total</strong></td>'
                  . $totalCells
                  . '<td><strong>' . $grandTotal . '</strong></td>'
                  . '</tr>';

        return <<<HTML
        <table class="enrollment-table">
          <thead><tr><th>Site</th>{$headers}<th>Total</th></tr></thead>
          <tbody>{$bodyRows}{$totalRow}</tbody>
        </table>
        HTML;
    }

    /**
     * Output only the dynamic data that Chart.js needs — no logic.
     * The chart initialisation logic lives in enrollment-chart.js.
     * This is the data bridge pattern: PHP injects JSON into named
     * window globals; the external JS reads from those globals.
     */
    private function chartDataScript(
        string $canvasId,
        string $xLabels,
        string $dsJson,
        string $titleJson,
        string $axisLabel
    ): string {
        return <<<JS
        <script>
        window.CHART_CANVAS_ID  = '{$canvasId}';
        window.CHART_X_LABELS   = {$xLabels};
        window.CHART_DATASETS   = {$dsJson};
        window.CHART_TITLE      = {$titleJson};
        window.CHART_AXIS_LABEL = {$axisLabel};
        </script>
        JS;
    }

    /**
     * Output the enrollment-chart.js script tag.
     * In inline mode (PDF / download), the file content is embedded
     * directly — consistent with how section-export.js is handled.
     */
    private function chartJsTag(): string
    {
        if ($this->inline) {
            $jsFile = __DIR__ . '/../../../../reporting-engine/public/assets/js/enrollment-chart.js';
            $js     = file_exists($jsFile) ? file_get_contents($jsFile) : '';
            return "<script>\n{$js}\n</script>";
        }

        return "<script src='/assets/js/enrollment-chart.js'></script>";
    }

    protected function htmlHead(): string
    {
        if ($this->inline)
        {
            $cssFile = __DIR__ . '/../../../../reporting-engine/public/assets/css/' . basename($this->cssPath);
            $css     = file_exists($cssFile) ? file_get_contents($cssFile) : '';
            return "<style>\n{$css}\n</style>";
        }

        return "<link rel='stylesheet' href='{$this->cssPath}'>";
    }

    /**
     * Resolve the filesystem path to a JS asset file.
     * Used by subclasses or inline mode to embed JS content.
     */
    protected function jsFilePath(string $filename): string
    {
        return realpath(__DIR__ . '/../../../../reporting-engine/public/assets/js/' . $filename) ?: '';
    }
}

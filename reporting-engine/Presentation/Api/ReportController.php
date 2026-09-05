<?php

namespace CEL\Reporting\Presentation\Api;

use CEL\Reporting\Application\ReportFacade;
use CEL\Shared\Domain\Export\CsvExporter;
use CEL\Shared\Domain\Export\ExporterInterface;
use CEL\Shared\Domain\Export\ExporterRegistry;

class ReportController
{
    /**
     * Per-request cache for loaded reports.php configs.
     * Keyed by project name — prevents double-loading when the same
     * project's config is needed for both format checking and alias resolution.
     */
    private array $reportsConfigCache = [];

    public function generate(): void
    {
        $project = $_GET['project'] ?? null;
        $report  = $_GET['report']  ?? null;
        $format  = strtolower($_GET['format'] ?? 'html');

        if (!$project || !$report) 
        {
            $this->renderHomePage();
            return;
        }

        try 
        {
            $facade = new ReportFacade();

            // ── Runtime overrides from query string ───────────────────────────
            $overrides = [];
            if (!empty($_GET['date_from'])) $overrides['date_from']   = $_GET['date_from'];
            if (!empty($_GET['date_to']))   $overrides['date_to']     = $_GET['date_to'];
            if (!empty($_GET['sites']))
            {
                $overrides['site_filter'] = array_filter(
                    array_map('trim', explode(',', $_GET['sites']))
                );
            }

            // ── Check format is supported by this report ──────────────────────
            $reportsConfig = $this->loadReportsConfig($project);
            $reportDef     = $reportsConfig[$report] ?? [];
            $supportedFmts = $reportDef['formats'] ?? ['html', 'download_html', 'pdf', 'csv'];

            if (!in_array($format, $supportedFmts, true))
            {
                http_response_code(400);
                header('Content-Type: text/html');
                echo "<h2>Format not supported</h2>"
                   . "<p>The <strong>{$report}</strong> report does not support the "
                   . "<strong>{$format}</strong> format. "
                   . "Supported formats: " . implode(', ', $supportedFmts) . ".</p>"
                   . "<p><a href='?project={$project}&report={$report}&format=html'>View report</a></p>";
                return;
            }

            $result = $facade->generate($project, $report, $overrides);

            $alias = $this->resolveExporterAlias($project, $report);

            match ($format) 
            {
                'csv'              => $this->serveCsv($result, $report, $alias),
                'excel'            => $this->serveExcel($result, $report, $alias),
                'participants_csv' => $this->serveParticipantsCsv($result, $report, $alias),
                'download_html'    => $this->serveHtmlDownload($result, $report, $alias),
                'pdf'              => $this->servePdf($result, $report, $alias),
                'section'          => $this->serveSection($result, $project, $report),
                default            => $this->serveHtml($result, $project, $report),
            };

        } 
        catch (\InvalidArgumentException $e) 
        {
            // Validation error (e.g. invalid date range) — show clean message
            http_response_code(400);
            header('Content-Type: text/html');
            echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
                  <title>Invalid Request</title>
                  <style>
                    body { font-family: Arial, sans-serif; padding: 40px; color: #333; }
                    .error-box { border-left: 4px solid #e53935; background: #fff3f3;
                                 padding: 16px 20px; border-radius: 3px; max-width: 600px; }
                    h2 { color: #e53935; margin: 0 0 8px; font-size: 16px; }
                    p  { margin: 0 0 12px; }
                    a  { color: #1a6496; }
                  </style></head><body>
                  <div class='error-box'>
                    <h2>&#9888; Invalid Request</h2>
                    <p>" . htmlspecialchars($e->getMessage()) . "</p>
                    <p><a href='javascript:history.back()'>&#8592; Go back</a></p>
                  </div></body></html>";
        }
        catch (\Throwable $e) 
        {
            http_response_code(500);
            header('Content-Type: text/html');
            error_log('[ReportController] ' . $e->getMessage()
                . ' in ' . $e->getFile() . ':' . $e->getLine());

            $debug = (ini_get('display_errors') == '1');

            if ($debug)
            {
                // Development — show full detail for easy debugging
                echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
                      <title>Error</title>
                      <style>
                        body { font-family: Arial, sans-serif; padding: 40px; color: #333; }
                        .error-box { border-left: 4px solid #c0392b; background: #fff3f3;
                                     padding: 16px 20px; border-radius: 3px; max-width: 900px; }
                        h2 { color: #c0392b; margin: 0 0 8px; font-size: 16px; }
                        pre { background: #f5f5f5; padding: 12px; border-radius: 3px;
                              font-size: 12px; overflow-x: auto; white-space: pre-wrap; }
                        .label { font-weight: bold; color: #555; font-size: 12px; }
                        a { color: #1a6496; }
                      </style></head><body>
                      <div class='error-box'>
                        <h2>&#9888; " . htmlspecialchars(get_class($e)) . "</h2>
                        <p>" . htmlspecialchars($e->getMessage()) . "</p>
                        <p class='label'>File: " . htmlspecialchars($e->getFile()) . " &nbsp;Line: " . $e->getLine() . "</p>
                        <pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>
                        <p><a href='javascript:history.back()'>&#8592; Go back</a></p>
                      </div></body></html>";
            }
            else
            {
                // Production — generic message, detail in error log only
                echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
                      <title>Error</title>
                      <style>
                        body { font-family: Arial, sans-serif; padding: 40px; color: #333; }
                        .error-box { border-left: 4px solid #c0392b; background: #fff3f3;
                                     padding: 16px 20px; border-radius: 3px; max-width: 600px; }
                        h2 { color: #c0392b; margin: 0 0 8px; font-size: 16px; }
                        p  { margin: 0 0 12px; }
                        a  { color: #1a6496; }
                      </style></head><body>
                      <div class='error-box'>
                        <h2>&#9888; Something went wrong</h2>
                        <p>The report could not be generated. Please check your filters and try again.</p>
                        <p>If the problem persists, contact your system administrator.</p>
                        <p><a href='javascript:history.back()'>&#8592; Go back</a></p>
                      </div></body></html>";
            }
        }
    }

    // -------------------------------------------------------------------------
    // Resolve the HTML exporter for this report via ExporterRegistry.
    //
    // The report definition in reports.php declares 'exporter' => 'alias'.
    // ExporterRegistry maps that alias to a concrete class — no string
    // matching on report names, no controller edits when adding new exporters.
    //
    // Falls back to 'eligibility' if no 'exporter' key is declared, so
    // existing reports without the key continue to work during migration.
    // -------------------------------------------------------------------------
    private function resolveHtmlExporter(
        string  $report,
        bool    $inline,
        string  $cssPath     = '',
        string  $toolbar     = '',
        string  $defaultView = 'stacked',
        ?string $exporterAlias = null
    ): ExporterInterface {
        $alias = $exporterAlias ?? 'eligibility';

        return ExporterRegistry::make(
            alias:       $alias,
            inline:      $inline,
            cssPath:     $cssPath,
            toolbar:     $toolbar,
            defaultView: $defaultView,
        );
    }

    // -------------------------------------------------------------------------
    // HTML — render in browser with toolbar (Print + download buttons)
    // -------------------------------------------------------------------------
    private function serveHtml(mixed $result, string $project, string $report): void
    {
        $alias    = $this->resolveExporterAlias($project, $report);
        $exporter = $this->resolveHtmlExporter(
            report:        $report,
            inline:        false,
            toolbar:       $this->buildToolbar($project, $report),
            defaultView:   $_GET['view'] ?? 'stacked',
            exporterAlias: $alias,
        );
        $exporter->export($result, null);
    }

    // -------------------------------------------------------------------------
    // Section export — standalone interactive page for one section
    // -------------------------------------------------------------------------
    private function serveSection(mixed $result, string $project, string $report): void
    {
        $section  = $_GET['section']  ?? 'screening';
        $download = !empty($_GET['download']);
        $alias    = $this->resolveExporterAlias($project, $report);
        $exporter = $this->resolveHtmlExporter(report: $report, inline: true, exporterAlias: $alias);

        if (!method_exists($exporter, 'exportSection'))
        {
            http_response_code(400);
            echo "<p>Section export not supported for this report.</p>";
            return;
        }

        if ($download)
        {
            // Inline mode — CSS/JS embedded for standalone file
            $filename = $report . '_' . $section . '_' . date('Y-m-d') . '.html';
            header('Content-Type: text/html; charset=UTF-8');
            header("Content-Disposition: attachment; filename=\"{$filename}\"");
            $exporter->exportSection($result, $section, true);
        }
        else
        {
            // Web view — external CSS/JS linked
            $exporter->exportSection($result, $section, false);
        }
    }

    // -------------------------------------------------------------------------
    // HTML download — self-contained file with embedded CSS
    // -------------------------------------------------------------------------
    private function serveHtmlDownload(mixed $result, string $reportName, string $exporterAlias = 'eligibility'): void
    {
        $exporter = $this->resolveHtmlExporter(report: $reportName, inline: true, exporterAlias: $exporterAlias);

        // Capture to buffer
        ob_start();
        $exporter->export($result, null);
        $html = ob_get_clean();

        $filename = $reportName . '_' . date('Y-m-d') . '.html';
        header('Content-Type: text/html; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');
        echo $html;
    }

    // -------------------------------------------------------------------------
    // PDF — via Puppeteer (headless Chrome)
    // -------------------------------------------------------------------------
    private function servePdf(mixed $result, string $reportName, string $exporterAlias = 'eligibility'): void
    {
        // 1. Generate self-contained HTML to a temp file
        $tmpHtml = tempnam(sys_get_temp_dir(), 'report_') . '.html';
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'report_') . '.pdf';

        $exporter = $this->resolveHtmlExporter(
            report:        $reportName,
            inline:        true,
            defaultView:   $_GET['view'] ?? 'stacked',
            exporterAlias: $exporterAlias ?? 'eligibility',
        );
        $exporter->export($result, $tmpHtml);

        // 2. Call Puppeteer script
        $script   = escapeshellarg(__DIR__ . '/../../src/pdf/puppeteer-pdf.js');
        $input    = escapeshellarg('file://' . $tmpHtml);
        $output   = escapeshellarg($tmpPdf);
        $cmd      = "node {$script} --url={$input} --output={$output} 2>&1";

        exec($cmd, $cmdOutput, $exitCode);

        @unlink($tmpHtml);

        if ($exitCode !== 0 || !file_exists($tmpPdf)) 
        {
            http_response_code(500);
            header('Content-Type: text/html');
            echo "<h2>PDF generation failed</h2><pre>"
               . htmlspecialchars(implode("\n", $cmdOutput))
               . "</pre><p>Ensure Puppeteer is installed: <code>npm install puppeteer</code> "
               . "in the reporting-engine directory.</p>";
            return;
        }

        // 3. Stream PDF to browser
        $filename = $reportName . '_' . date('Y-m-d') . '.pdf';
        header('Content-Type: application/pdf');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Content-Length: ' . filesize($tmpPdf));
        header('Cache-Control: no-cache');
        readfile($tmpPdf);

        @unlink($tmpPdf);
    }

    // -------------------------------------------------------------------------
    // CSV download
    // -------------------------------------------------------------------------
    private function serveCsv(mixed $result, string $reportName, string $exporterAlias = ''): void
    {
        $filename = $reportName . '_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');

        // Check for a dedicated CSV exporter registered as '{alias}_csv'
        $csvAlias = $exporterAlias . '_csv';
        try {
            $exporter = ExporterRegistry::make($csvAlias);
        } catch (\InvalidArgumentException) {
            $exporter = new CsvExporter();
        }

        $exporter->export($result, 'php://output');
    }

    /**
     * Full participant table as CSV — one row per participant, all columns.
     * Looks for a dedicated exporter at '{alias}_participants_csv'. If none is
     * registered, falls through to the regular CSV exporter so the link never
     * breaks even on reports that don't have a participant CSV.
     */
    private function serveParticipantsCsv(mixed $result, string $reportName, string $exporterAlias = ''): void
    {
        $filename = $reportName . '_participants_' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: no-cache');

        $alias = $exporterAlias . '_participants_csv';
        try {
            $exporter = ExporterRegistry::make($alias);
        } catch (\InvalidArgumentException) {
            // Fall back to the standard CSV exporter
            $fallback = $exporterAlias . '_csv';
            try {
                $exporter = ExporterRegistry::make($fallback);
            } catch (\InvalidArgumentException) {
                $exporter = new CsvExporter();
            }
        }

        $exporter->export($result, 'php://output');
    }

    private function serveExcel(mixed $result, string $reportName, string $exporterAlias): void
    {
        // Look for a dedicated Excel exporter registered as '{alias}_excel'
        // Falls back to the HTML exporter if no Excel exporter is registered.
        $excelAlias = $exporterAlias . '_excel';

        try {
            $exporter = ExporterRegistry::make($excelAlias);
        } catch (\InvalidArgumentException) {
            // No dedicated Excel exporter — inform the user
            header('Content-Type: text/html; charset=UTF-8');
            echo "<p>No Excel exporter registered for <strong>{$exporterAlias}</strong>.</p>";
            return;
        }

        $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $filename = $reportName . '_data_' . $dateFrom . '_to_' . $dateTo . '.xlsx';

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        $exporter->export($result, null);
    }

    // -------------------------------------------------------------------------
    // Look up the exporter alias declared in reports.php for this report.
    // Returns 'eligibility' as a safe default if no 'exporter' key exists,
    // so reports that haven't been migrated yet continue to work.
    // -------------------------------------------------------------------------
    private function resolveExporterAlias(string $project, string $report): string
    {
        $reportsConfig = $this->loadReportsConfig($project);
        return $reportsConfig[$report]['exporter'] ?? 'eligibility';
    }

    // -------------------------------------------------------------------------
    // Load and cache the reports config for a project.
    // reports.php is require'd at most once per project per request —
    // subsequent calls return the cached array without re-executing the file.
    // -------------------------------------------------------------------------
    private function loadReportsConfig(string $project): array
    {
        if (!isset($this->reportsConfigCache[$project]))
        {
            $path = realpath(__DIR__ . '/../..') . '/../projects/' . $project . '/reports.php';
            $this->reportsConfigCache[$project] = file_exists($path) ? (require $path) : [];
        }

        return $this->reportsConfigCache[$project];
    }

    // -------------------------------------------------------------------------
    // Toolbar HTML injected into the rendered report page
    // Filters live on the home page — toolbar only shows title + downloads.
    // Filter params from the home page are carried in the URL and preserved
    // in all download links so exports reflect the selected period/sites.
    // -------------------------------------------------------------------------
    private function buildToolbar(string $project, string $report): string
    {
        $base = "?project={$project}&report={$report}";

        // Carry filter params from the home page into all download links
        $filterQs = '';
        if (!empty($_GET['date_from'])) $filterQs .= '&date_from=' . urlencode($_GET['date_from']);
        if (!empty($_GET['date_to']))   $filterQs .= '&date_to='   . urlencode($_GET['date_to']);
        if (!empty($_GET['sites']))     $filterQs .= '&sites='     . urlencode($_GET['sites']);

        // All styling via CSS classes — no inline styles in toolbar HTML
        $homeLink = "<a href='/' class='toolbar-home-link'>&#8592; Reports</a>";

        // Look up declared formats for this report so we only render buttons
        // for the formats it actually supports. Default keeps legacy behaviour
        // (HTML / CSV / PDF) for any report that doesn't declare a 'formats' key.
        $reportsConfig = $this->loadReportsConfig($project);
        $supported     = $reportsConfig[$report]['formats']
                         ?? ['html', 'download_html', 'pdf', 'csv'];

        $htmlBtn = in_array('download_html', $supported, true)
            ? "<a href='{$base}&format=download_html{$filterQs}' class='toolbar-btn'>&#8659; HTML</a>"
            : '';

        $csvBtn = in_array('csv', $supported, true)
            ? "<a href='{$base}&format=csv{$filterQs}' class='toolbar-btn'>&#8659; CSV (Missing)</a>"
            : '';

        $excelBtn = in_array('excel', $supported, true)
            ? "<a href='{$base}&format=excel{$filterQs}' class='toolbar-btn'>&#8659; Excel (Missing)</a>"
            : '';

        $pdfBtn = '';
        if (in_array('pdf', $supported, true)) {
            $pdfBtn = str_contains($report, 'MonthlyEnrollmentChart')
                ? "<button onclick='var v=document.querySelector(\"[data-view].active\")?.dataset?.view||\"stacked\";window.location=\"{$base}&format=pdf{$filterQs}&view=\"+v;' class='toolbar-btn'>&#8659; PDF</button>"
                : "<a href='{$base}&format=pdf{$filterQs}' class='toolbar-btn'>&#8659; PDF</a>";
        }

        // Section buttons driven by exporter alias — no report name string matching
        $alias          = $this->resolveExporterAlias($project, $report);
        $sectionButtons = '';

        if ($alias === 'eligibility')
        {
            $sectionButtons = "
    <div class='toolbar-actions toolbar-sections'>
        <span class='toolbar-section-label'>Export section:</span>
        <a href='{$base}&format=section&section=consort{$filterQs}'      class='toolbar-btn' target='_blank'>CONSORT</a>
        <a href='{$base}&format=section&section=screening{$filterQs}'    class='toolbar-btn' target='_blank'>Screening</a>
        <a href='{$base}&format=section&section=enrollment{$filterQs}'   class='toolbar-btn' target='_blank'>Enrollment</a>
        <a href='{$base}&format=section&section=demographics{$filterQs}' class='toolbar-btn' target='_blank'>Demographics</a>
    </div>";
        }
        elseif ($alias === 'form_completion')
        {
            $sectionButtons = "
    <div class='toolbar-actions toolbar-sections'>
        <span class='toolbar-section-label'>Export section:</span>
        <a href='{$base}&format=section&section=participants{$filterQs}'  class='toolbar-btn' target='_blank'>Participants HTML</a>
        <a href='{$base}&format=participants_csv{$filterQs}'              class='toolbar-btn'>&#8659; Participants CSV</a>
        <a href='{$base}&format=section&section=site-summary{$filterQs}'  class='toolbar-btn' target='_blank'>Site Summary</a>
        <a href='{$base}&format=section&section=day-frequency{$filterQs}' class='toolbar-btn' target='_blank'>Day Frequency</a>
    </div>";
        }
        elseif ($alias === 'one_time_completion')
        {
            $sectionButtons = "
    <div class='toolbar-actions toolbar-sections'>
        <span class='toolbar-section-label'>Export section:</span>
        <a href='{$base}&format=participants_csv{$filterQs}' class='toolbar-btn'>&#8659; Participants CSV</a>
    </div>";
        }
        elseif ($alias === 'length_of_stay')
        {
            $sectionButtons = "
    <div class='toolbar-actions toolbar-sections'>
        <span class='toolbar-section-label'>Export section:</span>
        <a href='{$base}&format=section&section=summary{$filterQs}'  class='toolbar-btn' target='_blank'>Summary</a>
        <a href='{$base}&format=section&section=by-site{$filterQs}'  class='toolbar-btn' target='_blank'>By Site</a>
        <a href='{$base}&format=section&section=by-arm{$filterQs}'   class='toolbar-btn' target='_blank'>By Arm</a>
        <a href='{$base}&format=section&section=patients{$filterQs}' class='toolbar-btn' target='_blank'>Patients</a>
    </div>";
        }

        return "
<div class='report-toolbar'>
    <div class='toolbar-left'>
        {$homeLink}
        <span class='toolbar-title'>{$report}</span>
    </div>
    <div class='toolbar-actions'>
        {$htmlBtn}
        {$csvBtn}
        {$excelBtn}
        {$pdfBtn}
        <button onclick='window.print()' class='toolbar-btn'>&#128438; Print</button>
    </div>
    {$sectionButtons}
</div>";
    }

    // -------------------------------------------------------------------------
    // Home page
    // -------------------------------------------------------------------------
    private function renderHomePage(): void
    {
        header('Content-Type: text/html');

        $projectsRoot = realpath(__DIR__ . '/../..') . '/../projects';
        $projects = is_dir($projectsRoot)
            ? array_filter(scandir($projectsRoot), fn($d) => $d !== '.' && $d !== '..' && is_dir("{$projectsRoot}/{$d}"))
            : [];

        // Current filter values (project-scoped via ?proj=X&date_from=...&date_to=...&sites=...)
        $activeProj   = $_GET['proj']      ?? '';
        $dateFrom     = $_GET['date_from'] ?? '';
        $dateTo       = $_GET['date_to']   ?? '';
        $sitesRaw     = $_GET['sites']     ?? '';
        $selectedSites = $sitesRaw ? array_map('trim', explode(',', $sitesRaw)) : [];

        $projectBlocks = '';

        foreach ($projects as $proj)
        {
            $reportsFile = "{$projectsRoot}/{$proj}/reports.php";
            if (!file_exists($reportsFile)) continue;

            $reports = require $reportsFile;

            // Load site labels for this project's multi-select
            $siteLabelsPath = "{$projectsRoot}/{$proj}/site_labels.php";
            $siteLabels     = file_exists($siteLabelsPath) ? (require $siteLabelsPath) : [];

            // Are these the filter values for this project?
            $isActive  = ($activeProj === $proj);
            $projFrom  = $isActive ? $dateFrom  : '';
            $projTo    = $isActive ? $dateTo    : '';
            $projSites = $isActive ? $selectedSites : [];

            // Build filter query string to append to all report links
            $filterQs = "proj={$proj}";
            if ($projFrom)           $filterQs .= "&date_from={$projFrom}";
            if ($projTo)             $filterQs .= "&date_to={$projTo}";
            if (!empty($projSites))  $filterQs .= "&sites=" . implode(',', $projSites);

            // Build site options
            $siteOptions = '';
            foreach ($siteLabels as $code => $label)
            {
                $sel          = in_array($code, $projSites, true) ? ' selected' : '';
                $siteOptions .= "<option value='" . htmlspecialchars($code) . "'{$sel}>"
                              . htmlspecialchars($label) . "</option>\n";
            }

            $selectSize   = min(max(count($siteLabels), 1), 5);
            $projFromHtml = htmlspecialchars($projFrom);
            $projToHtml   = htmlspecialchars($projTo);
            $sitesHidden  = htmlspecialchars(implode(',', $projSites));

            // Build report rows grouped by 'group' key in report definition.
            // Reports without a 'group' key go into 'General'.
            // Groups are rendered as sub-headers within the report table.
            $grouped = [];
            foreach ($reports as $reportName => $reportDef)
            {
                $group = $reportDef['group'] ?? 'General';
                $grouped[$group][$reportName] = $reportDef;
            }

            $reportRows = '';
            $colSpan    = 6;

            foreach ($grouped as $groupName => $groupReports)
            {
                // Group sub-header row
                $reportRows .= "<tr class='report-group-hdr'>"
                    . "<td colspan='{$colSpan}'>{$groupName}</td>"
                    . "</tr>";

                foreach ($groupReports as $reportName => $reportDef)
                {
                    $base         = "?project={$proj}&report={$reportName}";
                    $filteredBase = $base . ($filterQs ? '&' . $filterQs : '');
                    $supported    = $reportDef['formats'] ?? ['html', 'download_html', 'pdf', 'csv'];

                    $viewLink  = in_array('html',          $supported) ? "<a href='{$filteredBase}&format=html'>View</a>"         : '<span style="color:#bbb">–</span>';
                    $htmlLink  = in_array('download_html', $supported) ? "<a href='{$filteredBase}&format=download_html'>HTML</a>" : '<span style="color:#bbb">–</span>';
                    $csvLink   = in_array('csv',           $supported) ? "<a href='{$filteredBase}&format=csv'>CSV</a>"           : '<span style="color:#bbb">–</span>';
                    $excelLink = in_array('excel',         $supported) ? "<a href='{$filteredBase}&format=excel'>Excel</a>"       : '<span style="color:#bbb">–</span>';
                    $pdfLink   = in_array('pdf',           $supported) ? "<a href='{$filteredBase}&format=pdf'>PDF</a>"           : '<span style="color:#bbb">–</span>';

                    $reportRows .= "<tr>"
                        . "<td class='report-name'><strong>{$reportName}</strong></td>"
                        . "<td>{$viewLink}</td>"
                        . "<td>{$htmlLink}</td>"
                        . "<td>{$csvLink}</td>"
                        . "<td>{$excelLink}</td>"
                        . "<td>{$pdfLink}</td>"
                        . "</tr>";
                }
            }

            // Site select only shown when site_labels exist for this project
            $siteFilterHtml = '';
            if (!empty($siteLabels))
            {
                $siteFilterHtml = "
                <div class='filter-field'>
                    <label class='filter-label'>
                        Sites <span>(blank = all)</span>
                    </label>
                    <input type='hidden' name='sites' value='{$sitesHidden}' id='sites-hidden-{$proj}'>
                    <select id='sites-select-{$proj}' multiple size='{$selectSize}' class='filter-select'>
                        {$siteOptions}
                    </select>
                </div>";
            }

            // Accordion: active project starts open, others closed
            $isOpen      = $isActive || ($activeProj === '' && $proj === array_values((array)$projects)[0]);
            $openClass   = $isOpen ? ' accordion-open' : '';
            $ariaExp     = $isOpen ? 'true' : 'false';
            $reportCount = count($reports);

            $projectBlocks .= "
<div class='accordion-panel{$openClass}'>
    <button class='accordion-header' aria-expanded='{$ariaExp}' type='button'>
        <span class='accordion-title'>{$proj}</span>
        <span class='accordion-meta'>{$reportCount} report" . ($reportCount !== 1 ? 's' : '') . "</span>
        <span class='accordion-icon'>&#9660;</span>
    </button>
    <div class='accordion-body'>

        <form method='get' id='filter-form-{$proj}' class='project-filter'>
            <input type='hidden' name='proj' value='{$proj}'>
            <div class='filter-field'>
                <label class='filter-label'>From</label>
                <input type='date' name='date_from' value='{$projFromHtml}' class='filter-input'>
            </div>
            <div class='filter-field'>
                <label class='filter-label'>To</label>
                <input type='date' name='date_to' value='{$projToHtml}' class='filter-input'>
            </div>
            {$siteFilterHtml}
            <div class='filter-actions'>
                <button type='submit' class='filter-submit'>&#8635; Apply</button>
            </div>
        </form>

        <table class='report-list'>
            <thead>
                <tr>
                    <th>Report</th>
                    <th>View</th>
                    <th>HTML</th>
                    <th>CSV</th>
                    <th>Excel</th>
                    <th>PDF</th>
                </tr>
            </thead>
            <tbody>{$reportRows}</tbody>
        </table>

    </div>
</div>";
        }

        ob_start(); ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>CEL Reporting Engine</title>
    <link rel="stylesheet" href="/assets/css/reporting-menu.css">
    <script src="/assets/js/reporting-menu.js" defer></script>
</head>
<body>
<div class="container">
    <h1>CEL Reporting Engine</h1>
    <p class="page-subtitle">Set the period and sites below, then open a report.</p>
    <?= $projectBlocks ?>
</div>
</body>
</html>
<?php
        echo ob_get_clean();
    }
}

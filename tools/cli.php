<?php

/**
 * cli.php — Generate formatted reports from the command line
 *
 * Designed for formatted reports (HTML, PDF-ready).
 * For raw data exports (CSV dumps) use: php tools/dump.php
 *
 * Usage:
 *   php tools/cli.php --project=Emollient --report=EligibilityReport --output=report.html
 *   php tools/cli.php --project=Emollient --report=MonthlyDashboard  --output=dashboard.html
 *   php tools/cli.php --project=Emollient --report=EligibilityReport \
 *               --date-from=2026-01-01 --date-to=2026-03-31 \
 *               --sites=GSVM,JSS --output=report.html
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// tools/ sits at the repo root alongside reporting-engine/, shared-lib/,
// projects/ and vendor/. Composer's autoloader is at the REPO ROOT.
$repoRoot = dirname(__DIR__);

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    fwrite(STDERR, "       Run 'composer install' in {$repoRoot}\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';

// Load .env before anything reads $_ENV — same as reporting-engine/public/index.php.
Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

use CEL\Reporting\Application\ReportFacade;
use CEL\Shared\Domain\Export\CsvExporter;
use CEL\Projects\Emollient\Export\EligibilityHtmlExporter;
use CEL\Shared\Domain\Export\WeeklyChartExporter;
use CEL\Shared\Domain\Export\MonthlyChartExporter;
use CEL\Shared\Domain\Export\MonthlyDashboardHtmlExporter;

$options = getopt('', [
    'project:',
    'report:',
    'output::',
    'date-from::',
    'date-to::',
    'sites::',
]);

if (!isset($options['project']) || !isset($options['report']))
{
    echo "Usage:\n";
    echo "  php tools/cli.php --project=Emollient --report=EligibilityReport --output=report.html\n";
    echo "\nOptions:\n";
    echo "  --project     Project name (required)\n";
    echo "  --report      Report name (required)\n";
    echo "  --output      Output file path (default: output.html)\n";
    echo "  --date-from   Override date_from (YYYY-MM-DD)\n";
    echo "  --date-to     Override date_to   (YYYY-MM-DD)\n";
    echo "  --sites       Comma-separated site codes to filter (e.g. GSVM,JSS)\n";
    echo "\nFor CSV data dumps use: php tools/dump.php\n";
    exit(1);
}

$project = $options['project'];
$report  = $options['report'];
$output  = $options['output'] ?? 'output.html';

// Build overrides from CLI flags
$overrides = [];
if (!empty($options['date-from'])) $overrides['date_from']   = $options['date-from'];
if (!empty($options['date-to']))   $overrides['date_to']     = $options['date-to'];
if (!empty($options['sites']))
{
    $overrides['site_filter'] = array_filter(
        array_map('trim', explode(',', $options['sites']))
    );
}

try
{
    $facade = new ReportFacade();
    $result = $facade->generate($project, $report, $overrides);

    $ext = strtolower(pathinfo($output, PATHINFO_EXTENSION));

    $exporter = match (true)
    {
        in_array($ext, ['html', 'htm']) && str_contains($report, 'Weekly')
            => new WeeklyChartExporter(inline: true),

        in_array($ext, ['html', 'htm']) && str_contains($report, 'MonthlyEnrollmentChart')
            => new MonthlyChartExporter(inline: true),

        in_array($ext, ['html', 'htm']) && str_contains($report, 'MonthlyDashboard')
            => new MonthlyDashboardHtmlExporter(inline: true),

        in_array($ext, ['html', 'htm'])
            => new EligibilityHtmlExporter(inline: true),

        default => new CsvExporter(),
    };

    $exporter->export($result, $output);

    echo "Export completed: {$output}\n";
}
catch (\Throwable $e)
{
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File:  " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}

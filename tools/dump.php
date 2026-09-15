<?php

/**
 * tools/dump.php — Stream a transformer-mode report to CSV
 *
 * Usage:
 *   php tools/dump.php --project=Emollient --report=WideDump --output=wide.csv
 *   php tools/dump.php --project=Emollient --report=RawDump  --output=raw.csv
 *   php tools/dump.php --project=Emollient --report=FlatDump --output=flat.csv
 *   php tools/dump.php --project=Emollient --report=FullProjectDump --output=full.csv
 *   php tools/dump.php --project=Emollient --report=RawLongitudinalDump --output=longitudinal.csv
 *   php tools/dump.php --project=Emollient --report=WideDumpNoRepeat --output=/tmp/wide_norepeat.csv
 * 
 * --- with paths 
 * # No --output: writes to reports/tools/output/WideDump_2026-03-15.csv (auto-created)
 *	php tools/dump.php --project=Emollient --report=WideDump

 *	# Relative path: resolved from wherever you run the command
*	php tools/dump.php --project=Emollient --report=WideDump --output=wide.csv

*	# Absolute path: used as-is (most reliable)
*	php tools/dump.php --project=Emollient --report=WideDump --output=/tmp/wide.csv
*	php tools/dump.php --project=Emollient --report=WideDump --output=/home/amit/exports/wide.csv
 *
 * Options:
 *   --project     Project name (required)
 *   --report      Report name from reports.php (required)
 *   --output      Output CSV path (default: {report}_{date}.csv)
 *   --date-from   Filter from date YYYY-MM-DD (optional)
 *   --date-to     Filter to date YYYY-MM-DD (optional)
 *
 * Notes:
 *   - Streams row-by-row — safe for large datasets
 *   - Wide dumps build a blueprint first (requires metadata fetch)
 *   - WideDump / FullProjectDump can produce very wide files (1000+ columns)
 */

// Raise the limit to 1G for wide-mode buffering, but never lower an
// explicit -d memory_limit=... or an unlimited (-1) setting.
$toBytes = static function (string $v): int 
{
    $v = trim($v);
    if ($v === '' || $v === '-1') { return PHP_INT_MAX; }   // unlimited
    $unit = strtolower(substr($v, -1));
    $n    = (int)$v;
    return match ($unit) {
        'g' => $n * 1024 ** 3,
        'm' => $n * 1024 ** 2,
        'k' => $n * 1024,
        default => $n,
    };
};

if ($toBytes(ini_get('memory_limit')) < 1024 ** 3) 
{
    ini_set('memory_limit', '1G');
}

error_reporting(E_ALL);
ini_set('display_errors', 1);

// tools/ sits at the repo root alongside reporting-engine/, shared-lib/,
// projects/ and vendor/. Composer's autoloader is at the REPO ROOT — the
// same one reporting-engine/public/index.php requires.
$repoRoot = dirname(__DIR__);

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    fwrite(STDERR, "       Run 'composer install' in {$repoRoot}\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';

// Load .env before anything reads $_ENV — same as reporting-engine/public/index.php.
// Credentials live there, never in code. CLI scripts must do this explicitly.
Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

use CEL\Reporting\Application\ReportFacade;
use CEL\Shared\Domain\Export\CsvExporter;

// ── Parse arguments ────────────────────────────────────────────────────────

$opts = getopt('', [
    'project:',
    'report:',
    'output::',
    'date-from::',
    'date-to::',
]);

if (empty($opts['project']) || empty($opts['report']))
{
    echo <<<USAGE
Usage:
  php tools/dump.php --project=Emollient --report=WideDump --output=wide.csv

Options:
  --project     Project name (required)
  --report      Report name (required)
  --output      Output CSV path (default: <report>_<date>.csv)
  --date-from   Filter from date YYYY-MM-DD
  --date-to     Filter to date YYYY-MM-DD

Available dump reports:
  RawDump              All fields, raw longitudinal (no transformation)
  FlatDump             All fields, flat with composite key
  WideDump             All fields, one row per participant (very wide)
  FullProjectDump      16 core forms, one row per participant
  RawLongitudinalDump  16 core forms, flat with composite key

USAGE;
    exit(1);
}

$project   = $opts['project'];
$report    = $opts['report'];
$dateFrom  = $opts['date-from'] ?? null;
$dateTo    = $opts['date-to']   ?? null;

// Resolve output path:
// - If --output given and absolute, use as-is
// - If --output given and relative, resolve relative to CWD (where script was called from)
// - If --output not given, default to tools/output/<report>_<date>.csv
$outputDir = __DIR__ . '/output';

if (!empty($opts['output']))
{
    $rawOutput = $opts['output'];
    $output = (str_starts_with($rawOutput, '/') || str_starts_with($rawOutput, '~'))
        ? $rawOutput
        : getcwd() . '/' . $rawOutput;
}
else
{
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);
    $output = $outputDir . '/' . $report . '_' . date('Y-m-d') . '.csv';
}

// ── Build overrides ────────────────────────────────────────────────────────

$overrides = [];
if ($dateFrom) $overrides['date_from'] = $dateFrom;
if ($dateTo)   $overrides['date_to']   = $dateTo;

// ── Run ───────────────────────────────────────────────────────────────────

echo "Project : {$project}\n";
echo "Report  : {$report}\n";
echo "Output  : {$output}\n";
if ($dateFrom || $dateTo)
    echo "Period  : " . ($dateFrom ?? '(start)') . " → " . ($dateTo ?? '(end)') . "\n";
echo "\n";

$t0 = microtime(true);

try
{
    $facade = new ReportFacade();

    echo "Fetching data from REDCap...\n";
    $result = $facade->generate($project, $report, $overrides);

    echo "Writing CSV...\n";
    $exporter = new CsvExporter();
    $exporter->export($result, $output);

    $elapsed = round(microtime(true) - $t0, 1);
    $size    = file_exists($output) ? round(filesize($output) / 1024, 1) . ' KB' : 'unknown';
    echo "Done in {$elapsed}s — {$size}\n";
    echo "Output: {$output}\n";
}
catch (\InvalidArgumentException $e)
{
    fwrite(STDERR, "Invalid arguments: " . $e->getMessage() . "\n");
    exit(1);
}
catch (\Throwable $e)
{
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File:  " . $e->getFile() . ":" . $e->getLine() . "\n");
    exit(1);
}

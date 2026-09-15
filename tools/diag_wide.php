#!/usr/bin/env php
<?php
/**
 * tools/diag_wide.php — Diagnose a wide-mode report
 *
 * Usage (from the repo root, e.g. /var/www/reports):
 *   php tools/diag_wide.php --project=Emollient --report=EmolliationWide
 *
 * Prints: row count, column count, repeat map, first 3 column names,
 *         first baby's data for sanity check.
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

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;
use CEL\Shared\Domain\Metadata\WideColumnBlueprintBuilder;
use CEL\Shared\Domain\Transformers\TransformerFactory;

$opts    = getopt('', ['project:', 'report:']);
$project = $opts['project'] ?? 'Emollient';
$report  = $opts['report']  ?? 'EmolliationWide';

$projectsRoot = $repoRoot . '/projects';

$reportsFile = "{$projectsRoot}/{$project}/reports.php";
$configFile  = "{$projectsRoot}/{$project}/config.php";

foreach ([$reportsFile, $configFile] as $f)
{
    if (!is_file($f))
    {
        fwrite(STDERR, "ERROR: Not found: {$f}\n");
        fwrite(STDERR, "       Check --project={$project} is spelled correctly.\n");
        exit(1);
    }
}

$allReports = require $reportsFile;

if (!isset($allReports[$report]))
{
    fwrite(STDERR, "ERROR: Report '{$report}' is not defined in {$reportsFile}\n\n");
    fwrite(STDERR, "Available reports in {$project}:\n");
    foreach (array_keys($allReports) as $name)
    {
        fwrite(STDERR, "  - {$name}\n");
    }
    exit(1);
}

$definition = $allReports[$report];
$cfg        = require $configFile;

$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$filterForms  = $definition['forms']  ?? [];
$filterEvents = $definition['events'] ?? [];
$chunkSize    = (int)($definition['chunk_size'] ?? 200);

echo "=== Fetching metadata ===\n";
$metadata     = $client->fetchMetadata();
$projectEvents= $client->fetchEvents();
$formEventMap = $client->fetchFormEventMapping();
echo "  metadata fields : " . count($metadata) . "\n";
echo "  project events  : " . count($projectEvents) . "\n";
echo "  form-event maps : " . count($formEventMap) . "\n";

echo "\n=== Streaming records (chunk={$chunkSize}) ===\n";
$stream   = $client->stream([], $filterForms, $filterEvents, $chunkSize);
$buffered = iterator_to_array($stream, false);
echo "  total rows buffered: " . count($buffered) . "\n";

if (empty($buffered)) {
    echo "\n*** NO ROWS RETURNED FROM REDCAP ***\n";
    echo "Check: forms=" . implode(',', $filterForms) . "\n";
    echo "Check: events=" . implode(',', array_slice($filterEvents, 0, 3)) . "...\n";
    exit(1);
}

// Show sample of first row
$first = $buffered[0];
echo "  first row keys  : " . implode(', ', array_keys($first)) . "\n";
echo "  redcap_event_name        : " . ($first['redcap_event_name'] ?? '?') . "\n";
echo "  redcap_repeat_instrument : " . ($first['redcap_repeat_instrument'] ?? '(empty)') . "\n";
echo "  redcap_repeat_instance   : " . ($first['redcap_repeat_instance'] ?? '(empty)') . "\n";

echo "\n=== Building repeat map ===\n";
$repeatMap = [];
foreach ($buffered as $row) {
    $event    = $row['redcap_event_name']        ?? null;
    $form     = $row['redcap_repeat_instrument'] ?? null;
    $instance = (int)($row['redcap_repeat_instance'] ?? 0);
    if (!$event || !$form || !$instance) continue;
    if ($filterForms && !in_array($form, $filterForms)) continue;
    $repeatMap[$event][$form] = max($repeatMap[$event][$form] ?? 0, $instance);
}
if (empty($repeatMap)) {
    echo "  *** repeatMap is EMPTY — no repeating rows found for these forms/events ***\n";
    echo "  Distinct repeat_instruments seen:\n";
    $seen = [];
    foreach ($buffered as $row) {
        $ri = $row['redcap_repeat_instrument'] ?? '';
        if ($ri !== '') $seen[$ri] = true;
    }
    foreach (array_keys($seen) as $ri) echo "    - {$ri}\n";
    if (empty($seen)) echo "    (none — all rows are non-repeating)\n";
} else {
    foreach ($repeatMap as $event => $forms) {
        foreach ($forms as $form => $maxInst) {
            echo "  {$event} / {$form} → max instance {$maxInst}\n";
        }
    }
}

echo "\n=== Building blueprint ===\n";
$builder   = new WideColumnBlueprintBuilder();
$blueprint = $builder->build(
    $metadata, $projectEvents, $formEventMap,
    $filterForms ?: null, null, $repeatMap
);
$cols = $blueprint['columns'] ?? [];
echo "  total columns: " . count($cols) . "\n";
echo "  first 5 cols : " . implode(', ', array_slice($cols, 0, 5)) . "\n";
echo "  last  5 cols : " . implode(', ', array_slice($cols, -5)) . "\n";

echo "\n=== Transforming ===\n";
$primaryKey  = $metadata[0]['field_name'] ?? 'record_id';
$transformer = TransformerFactory::create('wide', [
    'primaryKey'  => $primaryKey,
    'columns'     => $cols,
    'fieldToForm' => $blueprint['fieldToForm'] ?? [],
]);
$rows = iterator_to_array($transformer->transform(new ArrayIterator($buffered)), false);
echo "  output rows (babies): " . count($rows) . "\n";

if (!empty($rows)) {
    $baby = $rows[0];
    echo "  first baby record_id: " . ($baby[$primaryKey] ?? '?') . "\n";
    // Show non-empty values
    $nonEmpty = array_filter($baby, fn($v) => $v !== null && $v !== '');
    echo "  non-empty columns in first baby: " . count($nonEmpty) . "\n";
    foreach (array_slice($nonEmpty, 0, 5, true) as $col => $val) {
        echo "    {$col} = {$val}\n";
    }
}

echo "\nDone.\n";


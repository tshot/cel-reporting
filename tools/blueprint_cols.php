#!/usr/bin/env php
<?php
/**
 * blueprint_cols.php — print the wide-mode column names for a report,
 * without running a full export. Answers "how are repeating forms named?"
 *
 * Streams the record set and keeps ONLY the repeat map (4 fields per row),
 * so memory stays flat regardless of project size.
 *
 * Usage (from the repo root):
 *   php tools/blueprint_cols.php --project=Emollient --report=WideDump
 *   php tools/blueprint_cols.php --project=Emollient --report=WideDump --grep=emolliation
 *   php tools/blueprint_cols.php --project=Emollient --report=WideDump --out=/tmp/cols.txt
 *   php tools/blueprint_cols.php --project=Emollient --report=WideDump --chunk=50
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

$repoRoot = dirname(__DIR__);

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;
use CEL\Shared\Domain\Metadata\WideColumnBlueprintBuilder;

$opts    = getopt('', ['project:', 'report:', 'grep::', 'out::', 'chunk::']);
$project = $opts['project'] ?? 'Emollient';
$report  = $opts['report']  ?? 'WideDump';
$grep    = $opts['grep']    ?? null;
$out     = $opts['out']     ?? null;

$projectsRoot = $repoRoot . '/projects';
$reportsFile  = "{$projectsRoot}/{$project}/reports.php";

if (!is_file($reportsFile))
{
    fwrite(STDERR, "ERROR: Not found: {$reportsFile}\n");
    exit(1);
}

$allReports = require $reportsFile;

if (!isset($allReports[$report]))
{
    fwrite(STDERR, "ERROR: Report '{$report}' not defined in {$reportsFile}\n\n");
    fwrite(STDERR, "Available reports in {$project}:\n");
    foreach (array_keys($allReports) as $name) { fwrite(STDERR, "  - {$name}\n"); }
    exit(1);
}

$definition = $allReports[$report];
$cfg        = require "{$projectsRoot}/{$project}/config.php";
$client     = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$filterForms  = $definition['forms']  ?? [];
$filterEvents = $definition['events'] ?? [];
$chunkSize    = (int)($opts['chunk'] ?? $definition['chunk_size'] ?? 200);

echo "Report : {$report}\n";
echo "Forms  : " . ($filterForms ? implode(', ', $filterForms) : '(all)') . "\n";
echo "Chunk  : {$chunkSize}\n\n";

$metadata      = $client->fetchMetadata();
$projectEvents = $client->fetchEvents();
$formEventMap  = $client->fetchFormEventMapping();

echo "Streaming to build the repeat map (nothing buffered)...\n";

$repeatMap = [];
$rows      = 0;
$formsSeen = [];

foreach ($client->stream([], $filterForms, $filterEvents, $chunkSize) as $row)
{
    $rows++;

    $event    = $row['redcap_event_name']        ?? null;
    $form     = $row['redcap_repeat_instrument'] ?? null;
    $instance = (int)($row['redcap_repeat_instance'] ?? 0);

    unset($row);   // release the wide row immediately

    if ($form !== null && $form !== '') { $formsSeen[$form] = true; }
    if (!$event || !$form || !$instance) { continue; }
    if ($filterForms && !in_array($form, $filterForms, true)) { continue; }

    $repeatMap[$event][$form] = max($repeatMap[$event][$form] ?? 0, $instance);

    if ($rows % 5000 === 0)
    {
        printf("  %d rows, peak memory %.0f MB\n", $rows, memory_get_peak_usage(true) / 1048576);
    }
}

printf("  rows streamed: %d, peak memory %.0f MB\n", $rows, memory_get_peak_usage(true) / 1048576);

echo "\nRepeat map (event / form -> max instance):\n";

if (!$repeatMap)
{
    echo "  (empty — no repeating rows for these forms/events)\n";
    echo "  repeat_instruments seen: "
       . ($formsSeen ? implode(', ', array_keys($formsSeen)) : '(none)') . "\n";
}

foreach ($repeatMap as $event => $forms)
{
    foreach ($forms as $form => $max) { echo "  {$event} / {$form} -> {$max}\n"; }
}

$blueprint = (new WideColumnBlueprintBuilder())->build(
    $metadata, $projectEvents, $formEventMap, $filterForms ?: null, null, $repeatMap
);
$cols = $blueprint['columns'] ?? [];

echo "\nTotal columns: " . count($cols) . "\n";

if ($grep !== null)
{
    $hit = array_values(array_filter($cols, fn($c) => stripos($c, $grep) !== false));
    echo "Matching '{$grep}': " . count($hit) . "\n";
    foreach (array_slice($hit, 0, 40) as $c) { echo "  {$c}\n"; }
    if (count($hit) > 40) { echo "  ... and " . (count($hit) - 40) . " more\n"; }
}

if ($out !== null)
{
    file_put_contents($out, implode("\n", $cols) . "\n");
    echo "\nFull column list written to {$out}\n";
}

printf("\nPeak memory: %.0f MB\n", memory_get_peak_usage(true) / 1048576);

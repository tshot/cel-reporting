#!/usr/bin/env php
<?php
/**
 * tools/diag_discharge.php
 * Diagnoses discharge_arm_1 to find why dis_in_hosp = Y count is high.
 * Usage: php tools/diag_discharge.php --project=Emollient
 */
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// tools/ sits at the repo root alongside reporting-engine/, shared-lib/,
// projects/ and vendor/. Composer's autoloader is at the REPO ROOT — the
// same one reporting-engine/public/index.php requires.
$repoRoot   = dirname(__DIR__);
$engineRoot = $repoRoot . '/reporting-engine';

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    fwrite(STDERR, "       Run 'composer install' in {$repoRoot}\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';

// config.php reads $_ENV. The web entry point loads .env during
// bootstrap; a CLI script must do it explicitly.
Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['project:']);
$project = $opts['project'] ?? 'Emollient';
$cfg     = require $repoRoot . '/projects' . "/{$project}/config.php";
$client  = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = ['record_id', 'dis_hosp_code', 'discharge_form_complete',
           'dis_discharge_type', 'dis_in_hosp', 'dis_datetime'];

echo "=== Fetching discharge_arm_1 ===\n";
$stream   = $client->stream($fields, [], ['discharge_arm_1'], 200);
$buffered = iterator_to_array($stream, false);
echo "Total rows: " . count($buffered) . "\n\n";

// Rows per record
$byRecord = [];
foreach ($buffered as $row) 
{
    $byRecord[$row['record_id'] ?? ''][] = $row;
}
$singles = $multiples = 0;
$multiEx = null;
foreach ($byRecord as $id => $rows) 
{
    if (count($rows) === 1) $singles++;
    else { $multiples++; $multiEx = $multiEx ?? ['id'=>$id,'rows'=>$rows]; }
}
echo "Records with 1 row   : {$singles}\n";
echo "Records with >1 rows : {$multiples}\n\n";

if ($multiEx) 
{
    echo "=== Example multi-row record: {$multiEx['id']} ===\n";
    foreach ($multiEx['rows'] as $i => $row) {
        echo "  Row {$i}: repeat_instrument='" . ($row['redcap_repeat_instrument']??'') .
             "' instance='" . ($row['redcap_repeat_instance']??'') .
             "' dis_in_hosp='" . ($row['dis_in_hosp']??'') .
             "' dis_discharge_type='" . ($row['dis_discharge_type']??'') . "'\n";
    }
    echo "\n";
}

// Count Y with and without dedup
$allY = $uniqY = 0; $seen = [];
foreach ($buffered as $row) 
{
    if (($row['dis_in_hosp'] ?? '') !== 'Y') continue;
    $allY++;
    $id = $row['record_id'] ?? '';
    if (!isset($seen[$id])) { $uniqY++; $seen[$id] = true; }
}
echo "=== dis_in_hosp = Y ===\n";
echo "  All rows (possible double count) : {$allY}\n";
echo "  Unique record_ids                : {$uniqY}\n\n";

// repeat_instrument breakdown
echo "=== redcap_repeat_instrument breakdown ===\n";
$ri = [];
foreach ($buffered as $row) 
{
    $k = $row['redcap_repeat_instrument'] ?? '';
    $ri[$k ?: '(non-repeating)'] = ($ri[$k ?: '(non-repeating)'] ?? 0) + 1;
}
foreach ($ri as $k => $n) echo "  '{$k}': {$n} rows\n";

echo "\nDone.\n";

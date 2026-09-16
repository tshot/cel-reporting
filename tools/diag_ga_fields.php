<?php
/**
 * tools/diag_ga_fields.php
 * Dumps baseline_arm_1 fields for a sample of records to see what GA field
 * is actually populated in REDCap.
 */
ini_set('memory_limit', '256M');
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

$cfg    = require $repoRoot . '/projects' . '/Emollient/config.php';
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

// Fetch a broad set of baseline fields — we don't know the exact name yet
$fields = [
    'record_id',
    'base_calc_ga_wks',
    'base_calc_ga_days',
    'base_ga_wks',
    'base_ga_days',
    'base_study_arm',
];

$stream  = $client->stream($fields, [], ['baseline_arm_1'], 50);
$printed = 0;
$headers = false;

foreach ($stream as $row) {
    $id = $row['record_id'] ?? null;
    if (!$id) continue;

    // Find fields with non-blank values
    $nonBlank = array_filter($row, fn($v) => $v !== '' && $v !== null);
    unset($nonBlank['record_id'], $nonBlank['redcap_event_name'],
          $nonBlank['redcap_repeat_instrument'], $nonBlank['redcap_repeat_instance']);

    if (!$headers) {
        echo str_pad('record_id', 12);
        foreach ($fields as $f) echo str_pad($f, 20);
        echo "\n" . str_repeat('-', 12 + count($fields) * 20) . "\n";
        $headers = true;
    }

    echo str_pad($id, 12);
    foreach ($fields as $f) echo str_pad($row[$f] ?? '', 20);
    echo "\n";

    if (++$printed >= 10) break;
}

echo "\nDone. {$printed} records shown.\n";

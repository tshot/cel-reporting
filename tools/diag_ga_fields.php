<?php
/**
 * tools/diag_ga_fields.php
 * Dumps baseline_arm_1 fields for a sample of records to see what GA field
 * is actually populated in REDCap.
 */
ini_set('memory_limit', '256M');
$engineRoot = realpath(__DIR__ . '/../reporting-engine');
require $engineRoot . '/vendor/autoload.php';
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$cfg    = require realpath($engineRoot . '/../projects') . '/Emollient/config.php';
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

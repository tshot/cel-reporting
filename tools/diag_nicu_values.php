<?php
ini_set('memory_limit', '256M');
$engineRoot = realpath(__DIR__ . '/../reporting-engine');
require $engineRoot . '/vendor/autoload.php';
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['project:']);
$project = $opts['project'] ?? 'Emollient';
$cfg     = require realpath($engineRoot . '/../projects') . "/{$project}/config.php";
$client  = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$stream  = $client->stream(['record_id', 'baby_admit_nicu'], [], ['day0_arm_1'], 200);

$counts = [];
$total  = 0;
foreach ($stream as $row) {
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form') continue;
    $val = $row['baby_admit_nicu'] ?? '';
    $counts[$val] = ($counts[$val] ?? 0) + 1;
    $total++;
}

echo "=== baby_admit_nicu value distribution ===\n";
echo "Total prescreening rows: {$total}\n\n";
arsort($counts);
foreach ($counts as $val => $n) {
    $display = $val === '' ? '(blank)' : "'{$val}'";
    echo "  {$display}: {$n}\n";
}

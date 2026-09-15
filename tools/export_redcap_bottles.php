#!/usr/bin/env php
<?php
/**
 * tools/export_redcap_bottles.php
 *
 * Exports all bottle numbers from REDCap to a CSV file with full context:
 * record_id, event_name, instance, fi_sao_container_id, int_sao_container_id,
 * dis_bottle_1..5
 *
 * Usage:
 *   php tools/export_redcap_bottles.php
 *   php tools/export_redcap_bottles.php --out=/tmp/bottles.csv
 */

require '/var/www/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable('/var/www/oil-tracking');
$dotenv->load();

$opts    = getopt('', ['out:']);
$outFile = $opts['out'] ?? '/tmp/redcap_bottles_' . date('Y-m-d_His') . '.csv';

$apiUrl   = $_ENV['REDCAP_API_URL']   ?? '';
$apiToken = $_ENV['REDCAP_API_TOKEN'] ?? '';

echo "Fetching from REDCap...\n";

$params = [
    'token'   => $apiToken,
    'content' => 'record',
    'format'  => 'json',
    'type'    => 'flat',
    'fields'  => implode(',', [
        'record_id',
        'fi_sao_container_id',
        'int_sao_container_id',
        'dis_bottle_1',
        'dis_bottle_2',
        'dis_bottle_3',
        'dis_bottle_4',
        'dis_bottle_5',
    ]),
];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $apiUrl,
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_POSTFIELDS     => http_build_query($params, '', '&'),
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo "ERROR: HTTP {$httpCode}\n";
    $err = json_decode($response, true);
    echo "Message: " . ($err['error'] ?? $response) . "\n";
    exit(1);
}

$data = json_decode($response, true);
echo "Total rows from API : " . count($data) . "\n";

// ── Write CSV ─────────────────────────────────────────────────────────────────
$fp = fopen($outFile, 'w');

// Header
fputcsv($fp, [
    'record_id',
    'event_name',
    'repeat_instrument',
    'instance',
    'fi_sao_container_id',
    'int_sao_container_id',
    'dis_bottle_1',
    'dis_bottle_2',
    'dis_bottle_3',
    'dis_bottle_4',
    'dis_bottle_5',
    'has_bottle',       // Y if any bottle field is filled
    'bottle_source',    // which field has the bottle
    'bottle_value',     // the actual bottle number (first non-blank)
]);

$totalWithBottle    = 0;
$uniqueEmolliation  = [];
$uniqueDischarge    = [];

foreach ($data as $row) {
    $recordId   = (string)($row['record_id']                ?? '');
    $event      = (string)($row['redcap_event_name']        ?? '');
    $repeatForm = (string)($row['redcap_repeat_instrument'] ?? '');
    $instance   = (string)($row['redcap_repeat_instance']   ?? '');

    $fi  = trim((string)($row['fi_sao_container_id']  ?? ''));
    $int = trim((string)($row['int_sao_container_id'] ?? ''));
    $d1  = trim((string)($row['dis_bottle_1'] ?? ''));
    $d2  = trim((string)($row['dis_bottle_2'] ?? ''));
    $d3  = trim((string)($row['dis_bottle_3'] ?? ''));
    $d4  = trim((string)($row['dis_bottle_4'] ?? ''));
    $d5  = trim((string)($row['dis_bottle_5'] ?? ''));

    // Determine source and value
    $source = '';
    $value  = '';
    if ($fi  !== '') { $source = 'fi_sao_container_id';  $value = $fi;  $uniqueEmolliation[$fi]  = true; }
    if ($int !== '') { $source = 'int_sao_container_id'; $value = $int; $uniqueEmolliation[$int] = true; }
    foreach (['dis_bottle_1'=>$d1,'dis_bottle_2'=>$d2,'dis_bottle_3'=>$d3,
              'dis_bottle_4'=>$d4,'dis_bottle_5'=>$d5] as $field => $val) {
        if ($val !== '') {
            $source = $field;
            $value  = $val;
            $uniqueDischarge[$val] = true;
        }
    }

    $hasBottle = ($value !== '') ? 'Y' : '';
    if ($hasBottle) $totalWithBottle++;

    // Only write rows that have at least one bottle field filled
    if ($value === '') continue;

    fputcsv($fp, [
        $recordId, $event, $repeatForm, $instance,
        $fi, $int, $d1, $d2, $d3, $d4, $d5,
        $hasBottle, $source, $value,
    ]);
}

fclose($fp);

$inBoth = array_intersect_key($uniqueEmolliation, $uniqueDischarge);
$union  = array_merge($uniqueEmolliation, array_diff_key($uniqueDischarge, $uniqueEmolliation));

echo "Rows with bottle data: " . $totalWithBottle . "\n";
echo "Unique emolliation   : " . count($uniqueEmolliation) . "\n";
echo "Unique discharge     : " . count($uniqueDischarge) . "\n";
echo "In both              : " . count($inBoth) . "\n";
echo "Total unique (union) : " . count($union) . "\n";
echo "\nCSV written to: {$outFile}\n";
echo "Open this file in Excel and compare with your own count.\n";

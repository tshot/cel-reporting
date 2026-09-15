#!/usr/bin/env php
<?php
/**
 * tools/diag_redcap_bottles.php
 *
 * Diagnoses why getAllBottleNumbers() returns 0 from REDCap.
 * Shows raw data from REDCap for bottle-related fields.
 *
 * Usage (from /var/www):
 *   php tools/diag_redcap_bottles.php
 *   php tools/diag_redcap_bottles.php --limit=10   # show first 10 rows only
 *   php tools/diag_redcap_bottles.php --raw         # dump raw REDCap response
 */

require '/var/www/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable('/var/www/oil-tracking');
$dotenv->load();

$opts  = getopt('', ['limit:', 'raw']);
$limit = isset($opts['limit']) ? (int)$opts['limit'] : 0;
$raw   = isset($opts['raw']);

$apiUrl   = $_ENV['REDCAP_API_URL']   ?? '';
$apiToken = $_ENV['REDCAP_API_TOKEN'] ?? '';

if (!$apiUrl || !$apiToken) {
    echo "ERROR: REDCAP_API_URL or REDCAP_API_TOKEN not set in .env\n";
    exit(1);
}

echo "=== REDCap Bottle Number Diagnostic ===\n";
echo "API URL: {$apiUrl}\n\n";

// ── Fetch records ──────────────────────────────────────────────────────────────
$fields = [
    'record_id',
    // redcap_event_name and redcap_repeat_instrument returned automatically
    'fi_sao_container_id',
    'int_sao_container_id',
    'dis_bottle_1',
    'dis_bottle_2',
    'dis_bottle_3',
    'dis_bottle_4',
    'dis_bottle_5',
];

$params = [
    'token'   => $apiToken,
    'content' => 'record',
    'format'  => 'json',
    'type'    => 'flat',
    'fields'  => implode(',', $fields),
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

if ($raw) 
{
    echo "=== Raw Response (first 2000 chars) ===\n";
    echo substr($response, 0, 2000) . "\n\n";
}

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo "ERROR: Invalid JSON response\n";
    echo "HTTP Code: {$httpCode}\n";
    echo "Response: " . substr($response, 0, 500) . "\n";
    exit(1);
}

echo "HTTP Code       : {$httpCode}\n";
echo "Total rows      : " . count($data) . "\n\n";

// ── Analyse each row ───────────────────────────────────────────────────────────
$counts = [
    'fi_sao'      => 0,
    'int_sao'     => 0,
    'dis_bottles' => 0,
    'empty_event' => 0,
    'events'      => [],
    'repeat_instruments' => [],
];

$fi_bottles  = [];
$int_bottles = [];
$dis_bottles = [];

foreach ($data as $i => $row) {
    if ($limit > 0 && $i >= $limit) break;

    $event      = trim($row['redcap_event_name']        ?? '');
    $repeatForm = trim($row['redcap_repeat_instrument'] ?? '');
    $fi         = trim($row['fi_sao_container_id']      ?? '');
    $int        = trim($row['int_sao_container_id']     ?? '');

    if ($event === '') $counts['empty_event']++;

    // Track unique events and repeat instruments
    if ($event !== '') $counts['events'][$event] = true;
    if ($repeatForm !== '') $counts['repeat_instruments'][$repeatForm] = true;

    // fi_sao_container_id
    if ($fi !== '') {
        $counts['fi_sao']++;
        $fi_bottles[] = $fi;
    }

    // int_sao_container_id
    if ($int !== '') {
        $counts['int_sao']++;
        $int_bottles[] = $int;
    }

    // dis_bottle_1..5
    foreach (range(1, 5) as $n) {
        $v = trim($row["dis_bottle_{$n}"] ?? '');
        if ($v !== '') {
            $counts['dis_bottles']++;
            $dis_bottles[] = $v;
        }
    }
}

echo "=== Events found in response ===\n";
foreach (array_keys($counts['events']) as $ev) {
    echo "  {$ev}\n";
}

echo "\n=== Repeat instruments found ===\n";
if (empty($counts['repeat_instruments'])) {
    echo "  (none — all rows have blank redcap_repeat_instrument)\n";
} else {
    foreach (array_keys($counts['repeat_instruments']) as $ri) {
        echo "  {$ri}\n";
    }
}

echo "\n=== Bottle field counts ===\n";
echo sprintf("  fi_sao_container_id filled  : %d rows\n", $counts['fi_sao']);
echo sprintf("  int_sao_container_id filled : %d rows\n", $counts['int_sao']);
echo sprintf("  dis_bottle_1..5 filled      : %d values\n", $counts['dis_bottles']);
echo sprintf("  Rows with blank event name  : %d\n", $counts['empty_event']);

// Show sample values
if (!empty($fi_bottles)) 
{
    echo "\n=== Sample fi_sao_container_id values (first 5) ===\n";
    foreach (array_slice(array_unique($fi_bottles), 0, 5) as $v) 
    {
        echo "  '{$v}'\n";
    }
}
if (!empty($int_bottles)) 
{
    echo "\n=== Sample int_sao_container_id values (first 5) ===\n";
    foreach (array_slice(array_unique($int_bottles), 0, 5) as $v) 
    {
        echo "  '{$v}'\n";
    }
}
if (!empty($dis_bottles)) 
{
    echo "\n=== Sample dis_bottle values (first 5) ===\n";
    foreach (array_slice(array_unique($dis_bottles), 0, 5) as $v) 
    {
        echo "  '{$v}'\n";
    }
}

// ── Simulate getAllBottleNumbers() with union logic ───────────────────────────
echo "\n=== Simulating getAllBottleNumbers() with union logic ===\n";

$emolliationBottles = [];
$dischargeBottles   = [];

foreach ($data as $row) 
{
    $repeatForm = trim((string)($row['redcap_repeat_instrument'] ?? ''));

    // fi_sao — non-repeating
    if ($repeatForm === '') 
    {
        $v = trim((string)($row['fi_sao_container_id'] ?? ''));
        if ($v !== '' && !isset($emolliationBottles[$v]))
            $emolliationBottles[$v] = true;
    }
    // int_sao — repeating
    if ($repeatForm !== '') 
    {
        $v = trim((string)($row['int_sao_container_id'] ?? ''));
        if ($v !== '' && !isset($emolliationBottles[$v]))
            $emolliationBottles[$v] = true;
    }
    // dis_bottle_1..5
    foreach (range(1, 5) as $n) {
        $v = trim((string)($row["dis_bottle_{$n}"] ?? ''));
        if ($v !== '' && !isset($dischargeBottles[$v]))
            $dischargeBottles[$v] = true;
    }
}

$inBoth     = array_intersect_key($emolliationBottles, $dischargeBottles);
$allBottles = array_merge($emolliationBottles, array_diff_key($dischargeBottles, $emolliationBottles));

// Analyse int_sao distribution across repeat/non-repeat rows
$int_sao_repeating    = 0;
$int_sao_nonrepeating = 0;
$int_sao_all_unique   = [];
foreach ($data as $row) 
{
    $repeatForm = trim((string)($row['redcap_repeat_instrument'] ?? ''));
    $v = trim((string)($row['int_sao_container_id'] ?? ''));
    if ($v !== '') 
    {
        $int_sao_all_unique[$v] = true;
        if ($repeatForm !== '') $int_sao_repeating++;
        else                    $int_sao_nonrepeating++;
    }
}
echo sprintf("\n=== int_sao_container_id distribution ===\n");
echo sprintf("  Rows where repeating form     : %d\n", $int_sao_repeating);
echo sprintf("  Rows where NON-repeating form : %d\n", $int_sao_nonrepeating);
echo sprintf("  Unique across ALL rows        : %d\n", count($int_sao_all_unique));
echo sprintf("  (your expected unique count)  : 335\n");

echo sprintf("\n=== fi_sao_container_id distribution ===\n");
$fi_sao_unique = [];
foreach ($data as $row) {
    $v = trim((string)($row['fi_sao_container_id'] ?? ''));
    if ($v !== '') $fi_sao_unique[$v] = true;
}
echo sprintf("  Unique fi_sao values          : %d\n", count($fi_sao_unique));

// Combined unique emolliation ignoring repeatForm filter
$all_emol_unique = array_merge($int_sao_all_unique, $fi_sao_unique);
echo sprintf("  Combined unique (no filter)   : %d\n", count($all_emol_unique));
echo "\n";

echo sprintf("  Unique emolliation bottles  : %d\n", count($emolliationBottles));
echo sprintf("  Unique discharge bottles    : %d\n", count($dischargeBottles));
echo sprintf("  In both (overlap)           : %d\n", count($inBoth));
echo sprintf("  Total unique (union)        : %d\n", count($allBottles));
echo sprintf("  Expected formula            : %d + %d - %d = %d\n",
    count($emolliationBottles), count($dischargeBottles),
    count($inBoth),
    count($emolliationBottles) + count($dischargeBottles) - count($inBoth));

echo "\nDone.\n";

#!/usr/bin/env php
<?php
/**
 * tools/diag_eligibility_prescreened.php
 *
 * Lists every record counted as PreScreened in the given date range,
 * using the exact same logic as EligibilityAggregator.
 *
 * Usage:
 *   php tools/diag_eligibility_prescreened.php \
 *       --project=Emollient \
 *       --from=2026-04-02 \
 *       --to=2026-04-08
 */

ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$engineRoot = realpath(__DIR__ . '/../reporting-engine');
require $engineRoot . '/vendor/autoload.php';
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['project:', 'from:', 'to:']);
$project = $opts['project'] ?? 'Emollient';
$from    = $opts['from']    ?? '2026-04-02';
$to      = $opts['to']      ?? '2026-04-08';

$dtFrom  = (new DateTime($from))->setTime(0, 0, 0);
$dtTo    = (new DateTime($to))->setTime(23, 59, 59);

$cfg    = require realpath($engineRoot . '/../projects') . "/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = [
    'record_id',
    'baby_hosp_code',
    'baby_datetime_birth',
    'baby_dc_id',
    'baby_prescreening_and_screening_form_complete',
];

echo "=== PreScreened babies: {$from} to {$to} ===\n\n";

$stream   = $client->stream($fields, [], ['day0_arm_1'], 200);

$inRange  = [];
$total    = 0;

foreach ($stream as $row) 
{
    // Only repeating prescreening form rows
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form') continue;

    $raw = trim($row['baby_datetime_birth'] ?? '');
    if ($raw === '') continue;

    try   { $dt = new DateTime($raw); }
    catch (Exception) { continue; }

    if ($dt < $dtFrom || $dt > $dtTo) continue;

    $total++;
    $id   = $row['record_id']   ?? '';
    $hosp = trim($row['baby_hosp_code'] ?? '');
    $dc   = trim($row['baby_dc_id']     ?? '');
    $dob  = $raw;

    $inRange[] = [
        'record_id'   => $id,
        'site'        => $hosp,
        'dc'          => $dc,
        'birth_date'  => $dob,
        'complete'    => $row['baby_prescreening_and_screening_form_complete'] ?? '',
    ];
}

// Sort by site then record_id
usort($inRange, fn($a, $b) => [$a['site'], $a['record_id']] <=> [$b['site'], $b['record_id']]);

echo str_pad('record_id', 14)
   . str_pad('site', 8)
   . str_pad('dc', 12)
   . str_pad('birth_date', 24)
   . "complete\n";
echo str_repeat('-', 70) . "\n";

foreach ($inRange as $r) 
{
    echo str_pad($r['record_id'], 14)
       . str_pad($r['site'],      8)
       . str_pad($r['dc'],        12)
       . str_pad($r['birth_date'],24)
       . $r['complete'] . "\n";
}

echo "\nTotal PreScreened in range: {$total}\n";

// Summary by site
$bySite = [];
foreach ($inRange as $r) 
{
    $bySite[$r['site']] = ($bySite[$r['site']] ?? 0) + 1;
}
echo "\nBy site:\n";
foreach ($bySite as $site => $n) 
{
    echo "  {$site}: {$n}\n";
}

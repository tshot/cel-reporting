#!/usr/bin/env php
<?php
/**
 * tools/diag_discharge_date.php
 * Count discharges between two dates irrespective of status.
 * Usage: php tools/diag_discharge_date.php --project=Emollient --from=2026-04-02 --to=2026-04-08
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

$cfg    = require realpath($engineRoot . '/../projects') . "/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = ['record_id', 'dis_hosp_code', 'dis_datetime',
           'dis_discharge_type', 'dis_in_hosp', 'discharge_form_complete'];

echo "=== Discharges: {$from} to {$to} ===\n\n";

$stream   = $client->stream($fields, [], ['discharge_arm_1'], 200);
$buffered = iterator_to_array($stream, false);

$dtFrom = new DateTime($from);
$dtTo   = (new DateTime($to))->setTime(23, 59, 59);

$inRange    = [];
$bySite     = [];
$byType     = [];
$inHospY    = 0;

foreach ($buffered as $row) {
    $raw = trim($row['dis_datetime'] ?? '');
    if ($raw === '') continue;

    try   { $dt = new DateTime($raw); }
    catch (Exception) { continue; }

    if ($dt < $dtFrom || $dt > $dtTo) continue;

    $id   = $row['record_id']       ?? '';
    $site = $row['dis_hosp_code']   ?? 'Unknown';
    $type = $row['dis_discharge_type'] ?? '(blank)';
    $inH  = $row['dis_in_hosp']     ?? '';

    $inRange[] = ['id'=>$id, 'site'=>$site, 'datetime'=>$raw,
                  'type'=>$type, 'in_hosp'=>$inH];

    $bySite[$site] = ($bySite[$site] ?? 0) + 1;
    $byType[$type] = ($byType[$type] ?? 0) + 1;
    if ($inH === 'Y') $inHospY++;
}

echo "Total discharges in range : " . count($inRange) . "\n\n";

echo "=== By site ===\n";
arsort($bySite);
foreach ($bySite as $site => $n) echo "  {$site}: {$n}\n";

echo "\n=== By discharge type ===\n";
arsort($byType);
foreach ($byType as $type => $n) echo "  {$type}: {$n}\n";

echo "\n=== dis_in_hosp = Y : {$inHospY} ===\n";

echo "\n=== All discharge records in range ===\n";
echo str_pad('record_id', 12) . str_pad('site', 10) .
     str_pad('dis_datetime', 22) . str_pad('type', 14) . "in_hosp\n";
echo str_repeat('-', 70) . "\n";
foreach ($inRange as $r) {
    echo str_pad($r['id'],       12) . str_pad($r['site'],     10) .
         str_pad($r['datetime'], 22) . str_pad($r['type'],     14) .
         $r['in_hosp'] . "\n";
}

// Show dis_in_hosp = Y records with calculated 28-day date
$stream2  = $client->stream(
    ['record_id','dis_hosp_code','dis_in_hosp','dis_last_mon_date','dis_last_event_number'],
    [], ['discharge_arm_1'], 200
);
$buf2 = iterator_to_array($stream2, false);

echo "\n=== dis_in_hosp = Y records with calculated 28-day completion date ===\n";
echo str_pad('record_id',12).str_pad('site',10).str_pad('last_event',12)
    .str_pad('last_mon_date',16).str_pad('calc_date',14)."in_range\n";
echo str_repeat('-',70)."\n";

$inRangeHosp = 0;
foreach ($buf2 as $row) {
    if (($row['dis_in_hosp'] ?? '') !== 'Y') continue;
    $id          = $row['record_id']            ?? '';
    $site        = $row['dis_hosp_code']        ?? '';
    $lastMon     = trim($row['dis_last_mon_date']     ?? '');
    $lastEvent   = trim($row['dis_last_event_number'] ?? '');
    $calcDate    = '(missing)';
    $inRange     = 'NO';

    if ($lastMon !== '' && is_numeric($lastEvent)) {
        try {
            $dt       = new DateTime($lastMon);
            $daysLeft = 28 - (int)$lastEvent;
            if ($daysLeft > 0) $dt->modify("+{$daysLeft} days");
            $calcDate = $dt->format('Y-m-d');
            if ($dt >= $dtFrom && $dt <= $dtTo) { $inRange = 'YES'; $inRangeHosp++; }
        } catch (Exception) {}
    }

    echo str_pad($id,12).str_pad($site,10).str_pad($lastEvent,12)
        .str_pad($lastMon,16).str_pad($calcDate,14).$inRange."\n";
}
echo "\nTotal dis_in_hosp=Y with calc_date in range: {$inRangeHosp}\n";

echo "\nDone.\n";

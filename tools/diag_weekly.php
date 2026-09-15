#!/usr/bin/env php
<?php
/**
 * tools/diag_weekly.php
 *
 * Diagnoses the WeeklyMeeting report — shows exactly what events and
 * field values are coming back from REDCap so we can fix the aggregator.
 *
 * Usage (from /var/www):
 *   php tools/diag_weekly.php --project=Emollient 2>&1 | tee /tmp/diag_weekly.txt
 */

ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$engineRoot = realpath(__DIR__ . '/../reporting-engine');
require $engineRoot . '/vendor/autoload.php';

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['project:']);
$project = $opts['project'] ?? 'Emollient';

$projectsRoot = realpath($engineRoot . '/../projects');
$cfg          = require "{$projectsRoot}/{$project}/config.php";
$client       = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$events = ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1', 'day29_arm_1'];
$fields = [
    'record_id',
    'baby_hosp_code', 'enr_hosp_code', 'dis_hosp_code',
    'baby_prescreening_and_screening_form_complete',
    'baby_datetime_birth',
    'baby_weight_nicu',
    'baby_prescrn_eligible',
    'enr_study_arm',
    'discharge_form_complete',
    'dis_discharge_type',
    'dis_in_hosp',
    'protocol_deviation_form_complete',
    'fu28_day28_fup',
];

echo "=== Fetching sample (chunk=5 records) ===\n";
$stream   = $client->stream($fields, [], $events, 5);
$buffered = iterator_to_array($stream, false);
echo "Total rows: " . count($buffered) . "\n\n";

// Group rows by event
$byEvent = [];
foreach ($buffered as $row) {
    $event = $row['redcap_event_name'] ?? '(missing)';
    $byEvent[$event][] = $row;
}

echo "=== Events seen ===\n";
foreach ($byEvent as $event => $rows) {
    echo "  {$event}: " . count($rows) . " rows\n";
}
echo "\n";

// For each event, show first row's field values
foreach ($events as $event) {
    echo "=== {$event} ===\n";
    $rows = $byEvent[$event] ?? [];
    if (empty($rows)) {
        echo "  NO ROWS RETURNED\n\n";
        continue;
    }

    $first = $rows[0];
    echo "  redcap_repeat_instrument : " . ($first['redcap_repeat_instrument'] ?? '(empty)') . "\n";
    echo "  redcap_repeat_instance   : " . ($first['redcap_repeat_instance']   ?? '(empty)') . "\n";
    echo "\n  Field values:\n";
    foreach ($fields as $f) {
        if ($f === 'record_id') continue;
        $val = $first[$f] ?? '(field not in row)';
        if ($val !== '(field not in row)' && $val !== '') {
            echo "    {$f} = '{$val}'\n";
        } elseif ($val === '') {
            echo "    {$f} = (blank)\n";
        } else {
            echo "    {$f} = (field not returned)\n";
        }
    }

    // For day0 check both repeating and non-repeating
    if ($event === 'day0_arm_1' && count($rows) > 1) {
        echo "\n  Rows by repeat_instrument:\n";
        $seen = [];
        foreach ($rows as $r) {
            $ri = $r['redcap_repeat_instrument'] ?? '';
            $seen[$ri] = ($seen[$ri] ?? 0) + 1;
        }
        foreach ($seen as $ri => $cnt) {
            echo "    '" . ($ri ?: '(non-repeating)') . "': {$cnt} rows\n";
        }
    }
    echo "\n";
}

// Check specific field names that might be wrong
echo "=== Checking variable names ===\n";
$checkFields = [
    'baby_weight_nicu'                               => 'NICU admission weight',
    'baby_prescreening_and_screening_form_complete'  => 'Prescreening form complete',
    'baby_prescrn_eligible'                          => 'PreScreening eligible',
    'discharge_form_complete'                        => 'Discharge form complete',
    'dis_discharge_type'                             => 'Discharge type',
    'dis_in_hosp'                                    => 'Still in hospital',
    'protocol_deviation_form_complete'               => 'Protocol deviation complete',
    'fu28_day28_fup'                                 => '29-day follow-up',
];

foreach ($checkFields as $field => $label) {
    $found = false;
    $foundVal = '';
    foreach ($buffered as $row) {
        if (array_key_exists($field, $row) && $row[$field] !== '') {
            $found    = true;
            $foundVal = $row[$field];
            break;
        }
    }
    if ($found) {
        echo "  OK    {$field} (e.g. '{$foundVal}')\n";
    } else {
        // Check if field exists in row but blank
        $inRow = false;
        foreach ($buffered as $row) {
            if (array_key_exists($field, $row)) { $inRow = true; break; }
        }
        if ($inRow) {
            echo "  BLANK {$field} — field returned but always empty in this sample\n";
        } else {
            echo "  MISS  {$field} — not returned at all (wrong name?)\n";
        }
    }
}

// Show completion status breakdown for prescreening form
echo "\n=== PreScreening form completion status breakdown ===\n";
$statusCounts = [];
foreach ($buffered as $row) {
    if (($row['redcap_event_name'] ?? '') !== 'day0_arm_1') continue;
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form') continue;
    $status = $row['baby_prescreening_and_screening_form_complete'] ?? '(missing)';
    if ($status === '') $status = '(blank)';
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
}
foreach ($statusCounts as $status => $count) {
    $label = match($status) {
        '0'       => 'Incomplete',
        '1'       => 'Unverified',
        '2'       => 'Complete',
        '(blank)' => 'Blank (never opened)',
        default   => $status,
    };
    echo "  {$status} ({$label}): {$count}\n";
}
$total = array_sum($statusCounts);
echo "  Total repeating instances: {$total}\n";
echo "  (Your 220 is likely the total; our 198 counts only status=2 Complete)\n";

echo "\nDone.\n";

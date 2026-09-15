#!/usr/bin/env php
<?php
/**
 * tools/diag_coverage_due.php
 *
 * Traces the exact Due/Attempted/Given calculation for a specific record.
 *
 * Usage:
 *   php tools/diag_coverage_due.php --project=Emollient --record=309-123
 */

ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$engineRoot = realpath(__DIR__ . '/../reporting-engine');
require $engineRoot . '/vendor/autoload.php';

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;
use CEL\Projects\Emollient\Support\EmolliationSessionCalculator;

$opts      = getopt('', ['project:', 'record:']);
$project   = $opts['project'] ?? 'Emollient';
$recordId  = $opts['record']  ?? null;

if (!$recordId) 
{
    echo "Usage: php tools/diag_coverage_due.php --project=Emollient --record=309-123\n";
    exit(1);
}

$projectsRoot = realpath($engineRoot . '/../projects');
$cfg          = require "{$projectsRoot}/{$project}/config.php";
$client       = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = [
    'record_id',
    'enr_consent_granted', 'enr_study_arm', 'enr_hosp_code',
    'enr_datetime', 'enr_baby_dob',
    'fi_emol_perid',
    'day0_interventionemolliation_module_complete',
    'int_emoliate_baby',
    'dis_datetime',
    'pd_datetime', 'sw_datetime', 'sae_datetime',
];

$events = array_merge(
    ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1'],
    array_map(fn($d) => "day{$d}_arm_1", range(1, 28))
);

$rows = iterator_to_array($client->stream($fields, [], $events, 50), false);
$rows = array_filter($rows, fn($r) => ($r['record_id'] ?? '') === $recordId);
$rows = array_values($rows);

if (empty($rows)) 
{
    echo "*** No rows found for record {$recordId}\n";
    exit(1);
}

echo "=== Coverage Due Diagnostic: {$recordId} ===\n\n";

// ── Parse data ────────────────────────────────────────────────────────────────
$enrDate     = null;
$dob         = null;
$fiEmolPerid = '';
$day0ModFilled = false;
$stopDates   = [];
$sessionRows = [];
$day0Attempted = 0;

foreach ($rows as $row) 
{
    $event      = $row['redcap_event_name']        ?? '';
    $repeatForm = $row['redcap_repeat_instrument'] ?? '';

    if ($event === 'day0_arm_1' && $repeatForm === '') 
    {
        if (!empty($row['enr_datetime']))
            $enrDate = new \DateTime($row['enr_datetime']);
        if (!empty($row['enr_baby_dob']))
            $dob = new \DateTime($row['enr_baby_dob']);

        $fiEmolPerid = strtoupper(trim($row['fi_emol_perid'] ?? ''));
        $complete    = trim($row['day0_interventionemolliation_module_complete'] ?? '');
        $day0ModFilled = ($fiEmolPerid !== '' || $complete !== '');
        if ($day0ModFilled) $day0Attempted = 1;

        echo "--- day0_arm_1 non-repeating row ---\n";
        echo "  enr_datetime  : " . ($row['enr_datetime'] ?? '(blank)') . "\n";
        echo "  enr_baby_dob  : " . ($row['enr_baby_dob'] ?? '(blank)') . "\n";
        echo "  fi_emol_perid : " . ($fiEmolPerid ?: '(blank)') . "\n";
        echo "  day0_mod_cmp  : " . ($complete ?: '(blank)') . "\n";
        echo "  day0_attempted: {$day0Attempted}\n\n";
    }

    if ($event === 'discharge_arm_1' && !empty($row['dis_datetime'])) {
        $stopDates['discharge'] = new \DateTime($row['dis_datetime']);
        echo "--- discharge_arm_1 ---\n";
        echo "  dis_datetime  : " . $row['dis_datetime'] . "\n\n";
    }

    if ($event === 'other_forms_arm_1') {
        foreach (['deviation' => 'pd_datetime', 'withdrawal' => 'sw_datetime', 'sae' => 'sae_datetime'] as $reason => $field) 
        {
            if (!empty($row[$field])) 
            {
                $stopDates[$reason] = new \DateTime($row[$field]);
                echo "--- other_forms_arm_1 ({$reason}) ---\n";
                echo "  {$field}: " . $row[$field] . "\n\n";
            }
        }
    }

    if ($repeatForm === 'daily_interventionemolliation_form') 
    {
        preg_match('/^day(\d+)_arm_/', $event, $m);
        $day   = isset($m[1]) ? (int)$m[1] : null;
        $given = trim($row['int_emoliate_baby'] ?? '') === 'Y';
        $sessionRows[] = ['day' => $day, 'given' => $given, 'event' => $event];
    }
}

$refDate = $enrDate ?? $dob;
echo "=== Raw Values ===\n";
echo "refDate (enr_datetime) : " . ($refDate ? $refDate->format('Y-m-d') : '(null)') . "\n";
echo "fi_emol_perid          : " . ($fiEmolPerid ?: '(blank)') . "\n";
echo "Stop dates             : " . (empty($stopDates) ? '(none)' : '') . "\n";
foreach ($stopDates as $reason => $dt) 
{
    echo "  {$reason}: " . $dt->format('Y-m-d') . "\n";
}
echo "Daily session rows     : " . count($sessionRows) . "\n";
foreach ($sessionRows as $s) 
{
    echo "  day={$s['day']} ({$s['event']}) given=" . ($s['given'] ? 'Y' : 'N') . "\n";
}
echo "\n";

// ── computeEndDay ─────────────────────────────────────────────────────────────
$calc   = new EmolliationSessionCalculator();
$endInfo = $refDate !== null
    ? $calc->computeEndDay($refDate, $stopDates)
    : ['endDay' => 0, 'stopReason' => '', 'lastDayIsToday' => false];

$endDay         = $endInfo['endDay'];
$stopReason     = $endInfo['stopReason'];
$lastDayIsToday = $endInfo['lastDayIsToday'];

echo "=== computeEndDay() ===\n";
echo "endDay         : {$endDay}\n";
echo "stopReason     : " . ($stopReason ?: '(none — ongoing)') . "\n";
echo "lastDayIsToday : " . ($lastDayIsToday ? 'true' : 'false') . "\n\n";

// ── Count sessions ────────────────────────────────────────────────────────────
$attempted      = $day0Attempted;
$given          = $day0Attempted;
$formsOnLastDay = 0;

foreach ($sessionRows as $s) 
{
    $attempted++;
    if ($s['given']) $given++;
    if ($s['day'] !== null && $s['day'] === $endDay) $formsOnLastDay++;
}

$notGiven = $attempted - $given;

echo "=== Session Counts ===\n";
echo "day0_attempted : {$day0Attempted}\n";
echo "attempted total: {$attempted}\n";
echo "given total    : {$given}\n";
echo "not given      : {$notGiven}\n";
echo "formsOnLastDay : {$formsOnLastDay} (forms on day {$endDay})\n\n";

// ── sessionsFromEndDay ────────────────────────────────────────────────────────
$due = $refDate !== null
    ? EmolliationSessionCalculator::sessionsFromEndDay(
        $endDay, $fiEmolPerid, $formsOnLastDay, $stopReason, $lastDayIsToday
    )
    : null;

$day0 = EmolliationSessionCalculator::day0Sessions($fiEmolPerid);

echo "=== sessionsFromEndDay() ===\n";
echo "endDay         : {$endDay}\n";
echo "fiEmolPerid    : " . ($fiEmolPerid ?: '(blank)') . "\n";
echo "formsOnLastDay : {$formsOnLastDay}\n";
echo "stopReason     : " . ($stopReason ?: '(none)') . "\n";
echo "lastDayIsToday : " . ($lastDayIsToday ? 'true' : 'false') . "\n";
echo "day0Sessions   : {$day0}\n";
echo "\n";
echo "=== RESULT ===\n";
echo "Due            : " . ($due ?? 'null') . "\n";
echo "Attempted      : {$attempted}\n";
echo "Given          : {$given}\n";
echo "Not Given      : {$notGiven}\n";

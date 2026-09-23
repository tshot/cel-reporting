#!/usr/bin/env php
<?php
/**
 * tools/diag_clinical_outcomes.php
 *
 * Diagnoses the ClinicalOutcomesAggregator by verifying:
 *   1. Which fields are returned by the REDCap API for key events
 *   2. Whether enrollment detection works (enr_study_arm on day0_arm_1)
 *   3. Whether discharge fields are correct
 *   4. Whether sepsis fields are returned on day events
 *   5. Whether anthropometric fields exist
 *
 * Usage:
 *   php tools/diag_clinical_outcomes.php --project=Emollient
 *   php tools/diag_clinical_outcomes.php --project=Emollient --record=307-1227
 */

ini_set('memory_limit', '256M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$root = realpath(__DIR__ . '/..');
require $root . '/vendor/autoload.php';


// config.php reads $_ENV. The web entry point loads .env during bootstrap;
// a CLI script must do it explicitly.
Dotenv\Dotenv::createImmutable($root)->safeLoad();
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts      = getopt('', ['project:', 'record:']);
$project   = $opts['project'] ?? 'Emollient';
$testId    = $opts['record']  ?? null;
$cfg       = require $root . "/projects/{$project}/config.php";
$client    = new RedcapApiClient($cfg['api_url'], $cfg['token']);

echo "=== ClinicalOutcomesAggregator Field Diagnostic ===\n";
echo "Project : {$project}\n";
echo "API URL : {$cfg['api_url']}\n\n";

// ── Helper: raw API call returning decoded array ──────────────────────────────
function rawFetch(string $url, string $token, array $params): array
{
    $params['token']  = $token;
    $params['format'] = 'json';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_POSTFIELDS     => http_build_query($params),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode($res, true);
    if (!is_array($decoded)) {
        echo "RAW API RESPONSE: $res\n";
        return [];
    }
    return $decoded;
}

// ── Pick a test record ────────────────────────────────────────────────────────
if (!$testId) {
    echo "Finding an enrolled record...\n";
    $sample = rawFetch($cfg['api_url'], $cfg['token'], [
        'content'     => 'record',
        'type'        => 'flat',
        'events'      => 'day0_arm_1',
        'fields'      => 'record_id,enr_study_arm,enr_consent_granted',
        'filterLogic' => '[enr_study_arm] != ""',
    ]);
    foreach ($sample as $row) {
        if (trim($row['enr_study_arm'] ?? '') !== '') {
            $testId = $row['record_id'];
            break;
        }
    }
    if (!$testId) {
        // Try without filterLogic — get first few and pick one with data
        $sample = rawFetch($cfg['api_url'], $cfg['token'], [
            'content' => 'record',
            'type'    => 'flat',
            'events'  => 'day0_arm_1',
            'fields'  => 'record_id,enr_study_arm',
        ]);
        foreach ($sample as $row) {
            if (trim($row['enr_study_arm'] ?? '') !== '') {
                $testId = $row['record_id'];
                break;
            }
        }
    }
    if (!$testId) {
        die("Could not find an enrolled record. Pass --record=RECORD_ID manually.\n");
    }
    echo "Using record: {$testId}\n\n";
} else {
    echo "Test record : {$testId}\n\n";
}

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 1 — Enrollment fields on day0_arm_1
// ═══════════════════════════════════════════════════════════════════════════════
echo "── TEST 1: Enrollment fields (day0_arm_1) ──────────────────────────────\n";

$enrollFields = [
    'record_id', 'redcap_event_name',
    // Fields the aggregator uses for enrollment detection
    'enr_study_arm',
    'enr_hosp_code',
    'baby_hosp_code',
    // Birth anthropometrics
    'baby_birth_wt_hosp',
    'baby_head_circumference',
    'baby_muac',
    'baby_eligible_enroll',   // twin/triplet filter used by other aggregators
];

$day0Rows = rawFetch($cfg['api_url'], $cfg['token'], [
    'content' => 'record',
    'type'    => 'flat',
    'records' => $testId,
    'events'  => 'day0_arm_1',
    'fields'  => implode(',', $enrollFields),
]);

$day0NonRepeat = array_filter($day0Rows, fn($r) =>
    ($r['redcap_event_name'] ?? '') === 'day0_arm_1'
    && ($r['redcap_repeat_instrument'] ?? '') === ''
);

echo "day0_arm_1 non-repeating rows: " . count($day0NonRepeat) . "\n";
foreach ($day0NonRepeat as $row) {
    foreach (['enr_study_arm','enr_hosp_code','baby_hosp_code',
              'baby_birth_wt_hosp','baby_head_circumference','baby_muac',
              'baby_eligible_enroll'] as $f) {
        $val  = $row[$f] ?? 'KEY_MISSING';
        $mark = $val === 'KEY_MISSING' ? '✗ MISSING' : ($val === '' ? '  (blank)' : '✓');
        echo "  {$mark}  {$f} = " . ($val === 'KEY_MISSING' ? 'NOT RETURNED' : var_export($val, true)) . "\n";
    }
}

// Check if enrollment is detectable
$enrolled = false;
foreach ($day0NonRepeat as $row) {
    if (trim($row['enr_study_arm'] ?? '') !== '') {
        $enrolled = true;
        echo "\n→ ENROLLMENT DETECTED via enr_study_arm = " . var_export($row['enr_study_arm'], true) . "\n";
        break;
    }
}
if (!$enrolled) {
    echo "\n→ ⚠ ENROLLMENT NOT DETECTED — enr_study_arm is blank/missing on day0_arm_1\n";
    echo "  This is why all values are 0. Fix: check correct enrollment field name.\n";

    // Try common alternatives
    echo "\n  Checking alternative field names...\n";
    $altCheck = rawFetch($cfg['api_url'], $cfg['token'], [
        'content' => 'record',
        'type'    => 'flat',
        'records' => $testId,
        'events'  => 'day0_arm_1',
        'fields'  => 'record_id,redcap_event_name',
    ]);
    // Get ALL fields for this record/event to find what's populated
    $allFields = rawFetch($cfg['api_url'], $cfg['token'], [
        'content' => 'record',
        'type'    => 'flat',
        'records' => $testId,
        'events'  => 'day0_arm_1',
    ]);
    echo "  All non-empty fields on day0_arm_1:\n";
    foreach ($allFields as $row) {
        if (($row['redcap_repeat_instrument'] ?? '') !== '') continue;
        foreach ($row as $field => $val) {
            if (in_array($field, ['record_id','redcap_event_name',
                'redcap_repeat_instrument','redcap_repeat_instance'], true)) continue;
            if (trim((string)$val) !== '') {
                echo "    {$field} = " . var_export($val, true) . "\n";
            }
        }
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 2 — Discharge fields on discharge_arm_1
// ═══════════════════════════════════════════════════════════════════════════════
echo "── TEST 2: Discharge fields (discharge_arm_1) ──────────────────────────\n";

$disFields = [
    'record_id', 'redcap_event_name',
    'dis_hosp_code', 'dis_study_arm',
    'dis_discharge_type', 'dis_post_28_discharge_type',
    'dis_in_hosp', 'dis_age_discharge', 'dis_post_28_age_discharge',
    'dis_baby_weight_1', 'dis_baby_weight_2',
    'dis_head_circumference_1', 'dis_head_circumference_2',
    'dis_muac_1', 'dis_muac_2',
];

$disRows = rawFetch($cfg['api_url'], $cfg['token'], [
    'content' => 'record',
    'type'    => 'flat',
    'records' => $testId,
    'events'  => 'discharge_arm_1',
    'fields'  => implode(',', $disFields),
]);

echo "discharge_arm_1 rows: " . count($disRows) . "\n";
foreach ($disRows as $row) {
    foreach (array_slice($disFields, 2) as $f) {
        $val  = $row[$f] ?? 'KEY_MISSING';
        $mark = $val === 'KEY_MISSING' ? '✗ MISSING' : ($val === '' ? '  (blank)' : '✓');
        echo "  {$mark}  {$f} = " . ($val === 'KEY_MISSING' ? 'NOT RETURNED' : var_export($val, true)) . "\n";
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 3 — Sepsis fields on day1_arm_1
// ═══════════════════════════════════════════════════════════════════════════════
echo "── TEST 3: Sepsis fields (day1_arm_1) ──────────────────────────────────\n";

$nssFields = [
    'record_id', 'redcap_event_name',
    'nss_high_temp', 'nss_low_temp', 'nss_rr',
    'nss_neuro_sign___sepsis_neuro_seizure',
    'nss_tlc', 'nss_anc', 'nss_micro_esr', 'nss_crp',
];

$nssRows = rawFetch($cfg['api_url'], $cfg['token'], [
    'content' => 'record',
    'type'    => 'flat',
    'records' => $testId,
    'events'  => 'day1_arm_1',
    'fields'  => implode(',', $nssFields),
]);

echo "day1_arm_1 rows: " . count($nssRows) . "\n";
foreach ($nssRows as $row) {
    foreach (array_slice($nssFields, 2) as $f) {
        $val  = $row[$f] ?? 'KEY_MISSING';
        $mark = $val === 'KEY_MISSING' ? '✗ MISSING' : ($val === '' ? '  (blank)' : '✓');
        echo "  {$mark}  {$f} = " . ($val === 'KEY_MISSING' ? 'NOT RETURNED' : var_export($val, true)) . "\n";
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 4 — Day 29 follow-up fields
// ═══════════════════════════════════════════════════════════════════════════════
echo "── TEST 4: Day 29 follow-up (day29_arm_1) ──────────────────────────────\n";

$fu29Fields = [
    'record_id', 'redcap_event_name',
    'fu28_alive_day28', 'fu28_study_arm',
];

$fu29Rows = rawFetch($cfg['api_url'], $cfg['token'], [
    'content' => 'record',
    'type'    => 'flat',
    'records' => $testId,
    'events'  => 'day29_arm_1',
    'fields'  => implode(',', $fu29Fields),
]);

echo "day29_arm_1 rows: " . count($fu29Rows) . "\n";
foreach ($fu29Rows as $row) {
    foreach (['fu28_alive_day28', 'fu28_study_arm'] as $f) {
        $val  = $row[$f] ?? 'KEY_MISSING';
        $mark = $val === 'KEY_MISSING' ? '✗ MISSING' : ($val === '' ? '  (blank)' : '✓');
        echo "  {$mark}  {$f} = " . ($val === 'KEY_MISSING' ? 'NOT RETURNED' : var_export($val, true)) . "\n";
    }
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════════
// TEST 5 — Skin score fields on day3_arm_1
// ═══════════════════════════════════════════════════════════════════════════════
echo "── TEST 5: Skin condition scores (day3_arm_1) ──────────────────────────\n";

$scsRows = rawFetch($cfg['api_url'], $cfg['token'], [
    'content' => 'record',
    'type'    => 'flat',
    'records' => $testId,
    'events'  => 'day3_arm_1',
    'fields'  => 'record_id,redcap_event_name,scs_hosp_code,scs_condition',
]);

echo "day3_arm_1 rows: " . count($scsRows) . "\n";
foreach ($scsRows as $row) {
    foreach (['scs_hosp_code','scs_condition'] as $f) {
        $val  = $row[$f] ?? 'KEY_MISSING';
        $mark = $val === 'KEY_MISSING' ? '✗ MISSING' : ($val === '' ? '  (blank)' : '✓');
        echo "  {$mark}  {$f} = " . ($val === 'KEY_MISSING' ? 'NOT RETURNED' : var_export($val, true)) . "\n";
    }
}

echo "\n=== Diagnostic complete ===\n";
echo "If enrollment was NOT DETECTED, look at the 'All non-empty fields' output\n";
echo "and update ClinicalOutcomesAggregator::collectDay0() with the correct field names.\n";

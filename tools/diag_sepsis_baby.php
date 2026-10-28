#!/usr/bin/env php
<?php
/**
 * tools/diag_sepsis_baby.php
 *
 * Prints every neonatal_sepsis_screening form for the given babies, one line per
 * form, with the fields the Weekly Meeting sepsis section reads (plus the report
 * fields it does not read yet). Read-only: nothing is written to REDCap.
 *
 * Usage (from /var/www/reports):
 *   php tools/diag_sepsis_baby.php --ids=307-901,307-912 [--project=Emollient]
 *   php tools/diag_sepsis_baby.php --ids=307-901 --csv > /tmp/307-901.csv
 */

ini_set('memory_limit', '512M');
error_reporting(E_ALL);

$repoRoot = dirname(__DIR__);
if (!is_file($repoRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    exit(1);
}
require $repoRoot . '/vendor/autoload.php';
if (class_exists('Dotenv\\Dotenv')) Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['ids:', 'project::', 'csv']);
$project = $opts['project'] ?? 'Emollient';
$ids     = array_values(array_filter(array_map('trim', explode(',', $opts['ids'] ?? ''))));
if (!$ids) {
    fwrite(STDERR, "Usage: php tools/diag_sepsis_baby.php --ids=307-901,307-912 [--csv]\n");
    exit(1);
}

$cfg    = require "{$repoRoot}/projects/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$events = ['day0_arm_1'];
for ($d = 1; $d <= 28; $d++) $events[] = "day{$d}_arm_1";

$fields = [
    'record_id', 'nss_hosp_code', 'nss_datetime', 'nss_sepsis',
    'nss_blood_test', 'nss_blood_test_report', 'nss_tests', 'nss_crp', 'nss_tlc',
    'nss_blood_culture', 'nss_blood_culture_report', 'nss_blood_culture_result',
];

$rows = [];
foreach ($client->streamRecords($ids, $fields, [], $events, 50) as $r) {
    if (trim($r['nss_datetime'] ?? '') === '' && trim($r['nss_sepsis'] ?? '') === '') continue;
    $rows[] = $r;
}
usort($rows, fn($a, $b) => strnatcmp($a['record_id'], $b['record_id'])
                        ?: strcmp($a['nss_datetime'] ?? '', $b['nss_datetime'] ?? ''));

$short = fn($v) => str_replace(['SEPSIS_IMPRESS_', 'SEPSIS_CULTURERES_'], '', (string)$v);
$out = [];
foreach ($rows as $r) {
    $tests = [];
    foreach ($r as $k => $v) {
        if (str_starts_with($k, 'nss_tests___') && trim((string)$v) === '1') {
            $tests[] = strtoupper(str_replace('nss_tests___sepsis_tests_', '', $k));
        }
    }
    $out[] = [
        'record_id'      => $r['record_id'],
        'event'          => $r['redcap_event_name'] ?? '',
        'nss_datetime'   => $r['nss_datetime'] ?? '',
        'site'           => $r['nss_hosp_code'] ?? '',
        'impression'     => $short($r['nss_sepsis'] ?? ''),
        'blood_test'     => $r['nss_blood_test'] ?? '',
        'bt_report'      => $r['nss_blood_test_report'] ?? '',
        'tests_ticked'   => implode('+', $tests),
        'crp'            => $r['nss_crp'] ?? '',
        'tlc'            => $r['nss_tlc'] ?? '',
        'culture'        => $r['nss_blood_culture'] ?? '',
        'culture_report' => $r['nss_blood_culture_report'] ?? '',
        'culture_result' => $short($r['nss_blood_culture_result'] ?? ''),
    ];
}

if (isset($opts['csv'])) {
    $h = fopen('php://stdout', 'w');
    if ($out) fputcsv($h, array_keys($out[0]), ',', '"', '\\');
    foreach ($out as $o) fputcsv($h, $o, ',', '"', '\\');
    exit(0);
}

if (!$out) { echo "No sepsis screening forms found for: " . implode(', ', $ids) . "\n"; exit(0); }
$cols  = array_keys($out[0]);
$width = [];
foreach ($cols as $c) $width[$c] = max(strlen($c), ...array_map(fn($o) => strlen((string)$o[$c]), $out));
$line  = fn($vals) => implode('  ', array_map(fn($c) => str_pad((string)$vals[$c], $width[$c]), $cols)) . "\n";
echo $line(array_combine($cols, $cols));
foreach ($out as $o) echo $line($o);
echo "\n" . count($out) . " form(s) for " . count(array_unique(array_column($out, 'record_id'))) . " baby(ies)\n";

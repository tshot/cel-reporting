<?php
/**
 * NICU admission after 24 hrs — baby extract.
 *
 * Lists one row per prescreening instance where baby_admitted_nicu_24hrs = 'Yes',
 * with the requested fields. Repeating form → each instance is its own row.
 *
 * Usage:
 *   php tools/nicu_24hr_extract.php [--output=PATH] [--site=CODE] [--all]
 *
 *   --output=PATH   CSV destination (default: tools/output/nicu_24hr_<date>.csv)
 *   --site=CODE     Restrict to one hospital code (e.g. --site=KGMU)
 *   --all           Include ALL prescreening rows (add a column for the flag)
 *                   instead of filtering to baby_admitted_nicu_24hrs = 'Yes'
 *
 * Field source: baby_prescreening_and_screening_form (repeating, day0_arm_1).
 */

$opts = getopt('', ['output::', 'site::', 'all']);

// tools/ sits at the repo root alongside reporting-engine/, shared-lib/,
// projects/ and vendor/. Composer's autoloader is at the REPO ROOT — the
// same one reporting-engine/public/index.php requires.
$repoRoot   = dirname(__DIR__);
$engineRoot = $repoRoot . '/reporting-engine';

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    fwrite(STDERR, "       Run 'composer install' in {$repoRoot}\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';

// config.php reads $_ENV. The web entry point loads .env during
// bootstrap; a CLI script must do it explicitly.
Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

// ── Config ────────────────────────────────────────────────────────────────
$cfg    = require __DIR__ . '/../projects/Emollient/config.php';
$apiUrl = $cfg['api_url'] ?? ($cfg['redcap']['api_url'] ?? null);
$token  = $cfg['token']  ?? ($cfg['redcap']['token']  ?? null);
if (!$apiUrl || !$token) {
    fwrite(STDERR, "ERROR: Emollient REDCap api_url/token not found in config.php\n");
    exit(1);
}

$siteFilter = isset($opts['site']) ? trim((string)$opts['site']) : null;
$includeAll = array_key_exists('all', $opts);

$outPath = $opts['output'] ?? null;
if (!$outPath) {
    $dir = __DIR__ . '/output';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $outPath = $dir . '/nicu_24hr_' . date('Y-m-d') . '.csv';
}

// ── Fetch ─────────────────────────────────────────────────────────────────
$client = new RedcapApiClient($apiUrl, $token);

$fields = [
    'record_id',
    'baby_hosp_code',
    'baby_datetime',
    'baby_datetime_birth',
    'baby_datetime_admission',
    'baby_admitted_nicu_24hrs',
    'baby_nicu_admit_time_hrs_mins',
];

fwrite(STDERR, "Fetching prescreening data from REDCap...\n");

$out = fopen($outPath, 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM for Excel

$header = [
    'record_id',
    'redcap_repeat_instance',
    'baby_hosp_code',
    'baby_datetime',
    'baby_datetime_birth',
    'baby_datetime_admission',
    'baby_nicu_admit_time_hrs_mins',
];
if ($includeAll) $header[] = 'baby_admitted_nicu_24hrs';
fputcsv($out, $header);

$matched = 0;
$scanned = 0;

foreach ($client->stream($fields, ['baby_prescreening_and_screening_form'], ['day0_arm_1'], 200) as $row) {
    // Only the repeating prescreening instances carry these fields.
    $repeatForm = $row['redcap_repeat_instrument'] ?? '';
    if ($repeatForm !== 'baby_prescreening_and_screening_form') continue;

    $scanned++;

    $flag = trim($row['baby_admitted_nicu_24hrs'] ?? '');
    if (!$includeAll && $flag !== 'Yes') continue;

    $site = trim($row['baby_hosp_code'] ?? '');
    if ($siteFilter !== null && $site !== $siteFilter) continue;

    $line = [
        $row['record_id']                     ?? '',
        $row['redcap_repeat_instance']         ?? '',
        $site,
        $row['baby_datetime']                  ?? '',
        $row['baby_datetime_birth']            ?? '',
        $row['baby_datetime_admission']        ?? '',
        $row['baby_nicu_admit_time_hrs_mins']  ?? '',
    ];
    if ($includeAll) $line[] = $flag;

    fputcsv($out, $line);
    $matched++;
}

fclose($out);

fwrite(STDERR, sprintf(
    "Done. Scanned %d prescreening instances, wrote %d rows.\nOutput: %s (%d bytes)\n",
    $scanned, $matched, $outPath, filesize($outPath)
));

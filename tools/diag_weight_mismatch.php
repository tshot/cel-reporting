#!/usr/bin/env php
<?php
/**
 * tools/diag_weight_mismatch.php
 *
 * Finds babies where baby_birth_wt_hosp and baby_inclusion_brth_wt disagree.
 *
 * Checks three conditions:
 *   1. baby_birth_wt_hosp is blank but baby_inclusion_brth_wt is set
 *   2. baby_inclusion_brth_wt is blank but baby_birth_wt_hosp is filled
 *   3. Raw weight vs flag disagree (weight in range but flag=No, or vice versa)
 *
 * Filtered by baby_datetime within date range (Weekly Meeting filter).
 *
 * Usage (from /var/www):
 *   php tools/diag_weight_mismatch.php --project=Emollient
 *   php tools/diag_weight_mismatch.php --project=Emollient --date_from=2026-01-01 --date_to=2026-04-24
 *   php tools/diag_weight_mismatch.php --project=Emollient --date_from=2026-01-01 --date_to=2026-04-24 --site=GSVM
 *   php tools/diag_weight_mismatch.php --project=Emollient --show_all   # show all records, not just mismatches
 */

ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 1);

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
use CEL\Projects\Emollient\Aggregator\EligibilityMetricDefinitions;

$opts      = getopt('', ['project:', 'date_from:', 'date_to:', 'site:', 'show_all']);
$project   = $opts['project']    ?? 'Emollient';
$dateFrom  = $opts['date_from']  ?? null;
$dateTo    = $opts['date_to']    ?? null;
$siteFilter= $opts['site']       ?? null;
$showAll   = isset($opts['show_all']);

$from = $dateFrom ? (new DateTime($dateFrom))->setTime(0,  0,  0) : null;
$to   = $dateTo   ? (new DateTime($dateTo  ))->setTime(23, 59, 59) : null;

$projectsRoot = $repoRoot . '/projects';
$cfg          = require "{$projectsRoot}/{$project}/config.php";
$client       = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = [
    'record_id',
    'baby_hosp_code',
    'baby_datetime',
    'baby_datetime_birth',
    'baby_birth_wt_hosp',
    'baby_inclusion_brth_wt',
    'baby_prescrn_eligible',
    // Additional fields needed for full EligibilityMetricDefinitions conditions
    'baby_current_status',
    'baby_admitted_nicu_24hrs',
    'baby_mech_venti_1',
    'baby_venti_confirmation',
    'baby_mech_venti_2',
    'baby_admit_nicu',
    'baby_oth_study_elig',
];

echo "=== Weight Mismatch Diagnostic ===\n";
echo "Project   : {$project}\n";
echo "Date from : " . ($dateFrom ?? '(none)') . "  [baby_datetime — Weekly Meeting filter]\n";
echo "Date to   : " . ($dateTo   ?? '(none)') . "\n";
echo "Site      : " . ($siteFilter ?? '(all)') . "\n";
echo "Show all  : " . ($showAll ? 'yes' : 'no — mismatches only') . "\n\n";

$stream = $client->stream(
    $fields,
    ['baby_prescreening_and_screening_form'],
    ['day0_arm_1'],
    50
);

// ── Load metric conditions ────────────────────────────────────────────────────
$eligMetrics = EligibilityMetricDefinitions::metrics();

// ── Counters ──────────────────────────────────────────────────────────────────
$total             = 0;
$flagNo            = 0;       // baby_inclusion_brth_wt = No (any reason)
$metricBirthWt     = 0;       // birth_weight_no condition (single exclusion — weight only)
$bwtBelow700       = 0;       // raw baby_birth_wt_hosp < 700
$bwtAbove1800      = 0;       // raw baby_birth_wt_hosp > 1800
$mismatches        = [];

foreach ($stream as $row)
{
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form')
        continue;

    $id   = $row['record_id']  ?? '';
    $site = trim($row['baby_hosp_code'] ?? '');
    if ($siteFilter && $site !== $siteFilter) continue;

    $babyDt    = trim($row['baby_datetime']       ?? '');
    $babyDtBirth = trim($row['baby_datetime_birth'] ?? '');
    $bwtRaw    = trim($row['baby_birth_wt_hosp']   ?? '');
    $flag      = trim($row['baby_inclusion_brth_wt'] ?? '');
    $eligible  = trim($row['baby_prescrn_eligible']  ?? '');

    // ── Date filter (baby_datetime — Weekly Meeting) ──────────────────────
    $filterDate = $babyDt;
    if ($filterDate === '') continue;

    try { $dt = new DateTime($filterDate); }
    catch (Exception) { continue; }

    if ($from && $dt < $from) continue;
    if ($to   && $dt > $to)   continue;

    $total++;

    // ── Analyse weight fields ─────────────────────────────────────────────
    $bwtNumeric  = is_numeric($bwtRaw) ? (float)$bwtRaw : null;
    $inRangeByRaw = ($bwtNumeric !== null && $bwtNumeric >= 700 && $bwtNumeric <= 1800);
    $excludedByRaw = ($bwtNumeric !== null && ($bwtNumeric < 700 || $bwtNumeric > 1800));
    $flagIsNo    = ($flag === 'No');
    $flagIsYes   = ($flag === 'Yes');
    $flagBlank   = ($flag === '');

    if ($flagIsNo) $flagNo++;
    if ($bwtNumeric !== null && $bwtNumeric < 700)  $bwtBelow700++;
    if ($bwtNumeric !== null && $bwtNumeric > 1800) $bwtAbove1800++;

    // ── Detect mismatches ─────────────────────────────────────────────────
    $issues = [];

    // Issue 1: blank raw weight
    if ($bwtRaw === '') 
    {
        $issues[] = "baby_birth_wt_hosp is BLANK"
                  . ($flagBlank ? ", flag also blank" : ", but flag = '{$flag}'");
    }

    // Issue 2: blank flag
    if ($flagBlank && $bwtRaw !== '') 
    {
        $issues[] = "baby_inclusion_brth_wt is BLANK"
                  . " but baby_birth_wt_hosp = {$bwtRaw}g";
    }

    // Issue 3: flag says No but raw weight is in range
    if ($flagIsNo && $inRangeByRaw) 
    {
        $issues[] = "FLAG says No (excluded) but raw weight {$bwtRaw}g is IN RANGE (700-1800)";
    }

    // Issue 4: flag says Yes but raw weight is out of range
    if ($flagIsYes && $excludedByRaw) 
    {
        $issues[] = "FLAG says Yes (included) but raw weight {$bwtRaw}g is OUT OF RANGE";
    }

    // Issue 5: flag says No but raw weight is also in range AND weight is blank
    // (already covered above)

    if (!empty($issues) || $showAll) 
    {
        $mismatches[] = [
            'id'            => $id,
            'site'          => $site,
            'baby_datetime' => $babyDt,
            'baby_datetime_birth' => $babyDtBirth,
            'bwt_raw'       => $bwtRaw ?: '(blank)',
            'flag'          => $flag   ?: '(blank)',
            'eligible'      => $eligible ?: '(blank)',
            'issues'        => $issues,
        ];
    }
}

// ── Summary ───────────────────────────────────────────────────────────────────
echo "=== Summary ===\n";
echo sprintf("Total rows in date range            : %d\n", $total);
echo sprintf("\n--- Flag-based (baby_inclusion_brth_wt) ---\n");
echo sprintf("Flag = No (any reason, all babies)  : %d\n", $flagNo);
echo sprintf("\n--- Raw weight bands (baby_birth_wt_hosp) ---\n");
echo sprintf("baby_birth_wt_hosp < 700            : %d\n", $bwtBelow700);
echo sprintf("baby_birth_wt_hosp > 1800           : %d\n", $bwtAbove1800);
echo sprintf("Below 700 + Above 1800 total        : %d  (should equal flag=No)\n",
    $bwtBelow700 + $bwtAbove1800);
echo sprintf("Difference (flag - raw bands)       : %d\n",
    $flagNo - ($bwtBelow700 + $bwtAbove1800));
echo sprintf("\n--- Report exclusion count (EligibilityMetricDefinitions::birth_weight_no) ---\n");
echo sprintf("birth_weight_no (single excl.)      : %d\n", $metricBirthWt);
echo sprintf("  Condition: flag=No AND alive AND admitted NICU AND venti OK\n");
echo sprintf("  This is what the PreScreening exclusion row shows in the report.\n\n");

$mismatchOnly = array_filter($mismatches, fn($m) => !empty($m['issues']));
echo sprintf("Records with issues            : %d\n\n", count($mismatchOnly));

if (empty($mismatches)) 
{
    echo "No issues found — all records agree between flag and raw weight.\n";
    exit(0);
}

// ── Detail output ─────────────────────────────────────────────────────────────
echo str_repeat('=', 80) . "\n";
echo sprintf("%-12s %-8s %-20s %-20s %-12s %-10s %s\n",
    'Record', 'Site', 'baby_datetime', 'baby_datetime_birth',
    'bwt_raw', 'flag', 'Issues');
echo str_repeat('-', 80) . "\n";

foreach ($mismatches as $m) 
{
    $issueStr = empty($m['issues']) ? 'OK' : implode(' | ', $m['issues']);
    echo sprintf("%-12s %-8s %-20s %-20s %-12s %-10s %s\n",
        $m['id'],
        $m['site'],
        $m['baby_datetime'],
        $m['baby_datetime_birth'],
        $m['bwt_raw'],
        $m['flag'],
        $issueStr
    );
}

echo str_repeat('=', 80) . "\n";
echo "Done.\n";

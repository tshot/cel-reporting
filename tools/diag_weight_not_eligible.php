#!/usr/bin/env php
<?php
/**
 * tools/diag_weight_not_eligible.php
 *
 * Lists all prescreening form instances where:
 *   baby_prescrn_eligible != 'Yes'  (No or blank)
 *   AND baby_weight_nicu is present
 *
 * Usage:
 *   php tools/diag_weight_not_eligible.php --project=Emollient \
 *       --from=2026-04-02 --to=2026-04-08
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

$opts    = getopt('', ['project:', 'from:', 'to:']);
$project = $opts['project'] ?? 'Emollient';
$from    = $opts['from']    ?? null;
$to      = $opts['to']      ?? null;

$cfg    = require $repoRoot . '/projects' . "/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = [
    'record_id', 'baby_hosp_code', 'baby_dc_id',
    'baby_datetime', 'baby_prescrn_eligible',
    'baby_weight_nicu',
];

$dtFrom = $from ? (new DateTime($from))->setTime(0, 0, 0)    : null;
$dtTo   = $to   ? (new DateTime($to))->setTime(23, 59, 59)   : null;

$stream = $client->stream($fields, [], ['day0_arm_1'], 200);

$found = [];
foreach ($stream as $row) 
{
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form')
        continue;

    // Date filter
    $raw = trim($row['baby_datetime'] ?? '');
    if ($dtFrom || $dtTo) {
        if ($raw === '') continue;
        try { $dt = new DateTime($raw); } catch (Exception) { continue; }
        if ($dtFrom && $dt < $dtFrom) continue;
        if ($dtTo   && $dt > $dtTo)   continue;
    }

    $eligible = trim($row['baby_prescrn_eligible'] ?? '');
    $weight   = trim($row['baby_weight_nicu']      ?? '');

    // Only interested in NOT eligible + weight present
    if ($eligible === 'Yes') continue;
    if ($weight === '' || !is_numeric($weight)) continue;

    $found[] = [
        'record_id' => $row['record_id']         ?? '',
        'event'     => $row['redcap_event_name'] ?? '',
        'instance'  => $row['redcap_repeat_instance'] ?? '',
        'site'      => trim($row['baby_hosp_code'] ?? ''),
        'dc'        => trim($row['baby_dc_id']     ?? ''),
        'datetime'  => $raw,
        'eligible'  => $eligible === '' ? '(blank)' : $eligible,
        'weight'    => $weight,
    ];
}

$total = count($found);
echo "=== Babies: eligible != Yes AND weight recorded ===\n";
echo "Date range: " . ($from ?? 'all') . " to " . ($to ?? 'all') . "\n";
echo "Total: {$total}\n\n";

if ($total === 0) 
{
    echo "None found.\n";
    exit(0);
}

// Sort by site then record_id
usort($found, fn($a,$b) => [$a['site'],$a['record_id']] <=> [$b['site'],$b['record_id']]);

echo str_pad('record_id', 14)
   . str_pad('inst', 6)
   . str_pad('site', 8)
   . str_pad('dc', 12)
   . str_pad('datetime', 22)
   . str_pad('event', 16)
   . str_pad('eligible', 10)
   . "weight\n"
   . str_repeat('-', 96) . "\n";

foreach ($found as $r) {
    echo str_pad($r['record_id'], 14)
       . str_pad($r['instance'],  6)
       . str_pad($r['site'],      8)
       . str_pad($r['dc'],        12)
       . str_pad($r['datetime'],  22)
       . str_pad($r['event'],    16)
       . str_pad($r['eligible'],  10)
       . $r['weight'] . "\n";
}

// Summary by eligibility value
echo "\nBy eligibility value:\n";
$byElig = [];
foreach ($found as $r) $byElig[$r['eligible']] = ($byElig[$r['eligible']] ?? 0) + 1;
foreach ($byElig as $val => $n) echo "  '{$val}': {$n}\n";

// Summary by site
echo "\nBy site:\n";
$bySite = [];
foreach ($found as $r) $bySite[$r['site']] = ($bySite[$r['site']] ?? 0) + 1;
ksort($bySite);
foreach ($bySite as $site => $n) echo "  {$site}: {$n}\n";

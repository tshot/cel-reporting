#!/usr/bin/env php
<?php
/**
 * tools/diag_emol_sessions.php
 *
 * Diagnoses emolliation sessions for one or all babies.
 * Shows exactly what REDCap returns for:
 *   - day0_interventionemolliation_module  (non-repeating, fi_emol_perid)
 *   - daily_interventionemolliation_form   (repeating, int_emoliate_baby)
 *
 * This confirms whether Day 0 repeating sessions are being returned
 * correctly and whether int_emoliate_baby is populated on them.
 *
 * Usage (from /var/www):
 *   php tools/diag_emol_sessions.php --project=Emollient
 *   php tools/diag_emol_sessions.php --project=Emollient --record=307-9
 *   php tools/diag_emol_sessions.php --project=Emollient --record=307-9,310-2
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

$opts    = getopt('', ['project:', 'record:']);
$project = $opts['project'] ?? 'Emollient';
$records = isset($opts['record'])
    ? array_map('trim', explode(',', $opts['record']))
    : [];

$projectsRoot = $repoRoot . '/projects';
$cfg          = require "{$projectsRoot}/{$project}/config.php";
$client       = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = [
    'record_id',
    'enr_study_arm',
    'enr_hosp_code',
    // Day 0 module (non-repeating)
    'fi_emol_perid',
    'day0_interventionemolliation_module_complete',
    // Daily repeating form
    'int_emoliate_baby',
    'int_no_emol_reason',
    'daily_interventionemolliation_form_complete',
];

$events = array_merge(
    ['day0_arm_1'],
    array_map(fn($d) => "day{$d}_arm_1", range(1, 28))
);

// ── Fetch ─────────────────────────────────────────────────────────────────────
echo "=== Fetching emolliation session data ===\n";
echo "Project : {$project}\n";
echo "Records : " . (empty($records) ? '(all — first 10 shown)' : implode(', ', $records)) . "\n\n";

$stream   = $client->stream($fields, [], $events, 50);
$buffered = iterator_to_array($stream, false);
echo "Total rows fetched: " . count($buffered) . "\n\n";

if (empty($buffered)) 
{
    echo "*** NO ROWS RETURNED — check forms/events in config ***\n";
    exit(1);
}

// ── Group by record ───────────────────────────────────────────────────────────
$byRecord = [];
foreach ($buffered as $row) 
{
    $id = $row['record_id'] ?? '';
    if ($id === '') continue;
    // If specific records requested, filter
    if (!empty($records) && !in_array($id, $records, true)) continue;
    $byRecord[$id][] = $row;
}

if (empty($byRecord)) 
{
    echo "*** No matching records found. Check --record value. ***\n";
    exit(1);
}

// If no record filter, limit to first 10 records
if (empty($records)) 
{
    $byRecord = array_slice($byRecord, 0, 10, true);
}

// ── Per-record report ─────────────────────────────────────────────────────────
foreach ($byRecord as $id => $rows)
{
    echo str_repeat('=', 70) . "\n";
    echo "Record: {$id}\n";
    echo str_repeat('=', 70) . "\n";

    // Find enrollment info from non-repeating day0 row
    $arm  = '';
    $site = '';
    $fiEmolPerid = '';
    $day0ModComplete = '';

    foreach ($rows as $row) {
        if (($row['redcap_event_name'] ?? '') === 'day0_arm_1'
            && ($row['redcap_repeat_instrument'] ?? '') === '')
        {
            $arm             = $row['enr_study_arm']   ?? '';
            $site            = $row['enr_hosp_code']   ?? '';
            $fiEmolPerid     = $row['fi_emol_perid']   ?? '';
            $day0ModComplete = $row['day0_interventionemolliation_module_complete'] ?? '';
            break;
        }
    }

    echo "Site            : " . ($site ?: '(blank)') . "\n";
    echo "Study Arm       : " . ($arm  ?: '(blank)') . "\n";
    echo "fi_emol_perid   : " . ($fiEmolPerid ?: '(blank)') . "\n";
    echo "Day0 mod complete: " . ($day0ModComplete !== '' ? $day0ModComplete : '(blank)') . "\n\n";

    // ── Session rows ──────────────────────────────────────────────────────────
    $sessionRows = array_filter(
        $rows,
        fn($r) => ($r['redcap_repeat_instrument'] ?? '') === 'daily_interventionemolliation_form'
    );

    $given    = 0;
    $notGiven = 0;
    $blank    = 0;

    echo sprintf(
        "%-20s %-8s %-18s %-3s %s\n",
        'Event', 'Instance', 'int_emoliate_baby', 'Cmp', 'Reason'
    );
    echo str_repeat('-', 70) . "\n";

    // Sort by event then instance
    usort($sessionRows, function($a, $b) {
        $eventCmp = strcmp($a['redcap_event_name'] ?? '', $b['redcap_event_name'] ?? '');
        if ($eventCmp !== 0) return $eventCmp;
        return (int)($a['redcap_repeat_instance'] ?? 0) <=> (int)($b['redcap_repeat_instance'] ?? 0);
    });

    foreach ($sessionRows as $row) 
    {
        $event    = $row['redcap_event_name']       ?? '';
        $instance = $row['redcap_repeat_instance']  ?? '-';
        $given_v  = $row['int_emoliate_baby']       ?? '';
        $complete = $row['daily_interventionemolliation_form_complete'] ?? '';
        $reason   = $row['int_no_emol_reason']      ?? '';

        if ($given_v === 'Y')      $given++;
        elseif ($given_v === 'N')  $notGiven++;
        else                       $blank++;

        $givenLabel = match($given_v) 
        {
            'Y'     => 'Y (Given)',
            'N'     => 'N (Not given)',
            ''      => '(blank)',
            default => $given_v,
        };

        echo sprintf(
            "%-20s %-8s %-18s %-3s %s\n",
            $event, $instance, $givenLabel, $complete, $reason
        );
    }

    echo str_repeat('-', 70) . "\n";
    $total = $given + $notGiven + $blank;
    echo "Sessions total  : {$total}\n";
    echo "  Given (Y)     : {$given}\n";
    echo "  Not given (N) : {$notGiven}\n";
    echo "  Blank         : {$blank}\n\n";

    // ── Day 0 breakdown ───────────────────────────────────────────────────────
    $day0Sessions = array_filter(
        $sessionRows,
        fn($r) => ($r['redcap_event_name'] ?? '') === 'day0_arm_1'
    );
    $day0Count = count($day0Sessions);

    echo "Day 0 analysis                               :\n";
    echo "  First Emolliation (fi_emol_perid)          : " . ($fiEmolPerid ?: '(blank)') . "\n";

    $expectedDay0 = match(strtoupper(trim($fiEmolPerid))) 
    {
        'MOR'   => 2,
        'AFT'   => 1,
        'EVE'   => 0,
        default => 0,
    };
    
    echo "  Expected Regular Day 0 sessions: {$expectedDay0}\n";
    echo "  Actual Regular Day 0 sessions  : {$day0Count}\n";

    if ($day0Count === $expectedDay0) 
    {
        echo "  Status: OK — matches expected\n";
    } 
    else 
    {
        echo "  Status: MISMATCH — expected {$expectedDay0}, got {$day0Count}\n";
    }
    echo "\n";
}

echo "Done.\n";

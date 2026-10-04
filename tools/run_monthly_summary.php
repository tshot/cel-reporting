<?php
/**
 * run_monthly_summary.php — run MonthlySiteSummaryAggregator against live
 * REDCap and print the result, before the report view exists.
 *
 *   cd /var/www/reports
 *   php tools/run_monthly_summary.php
 *   php tools/run_monthly_summary.php --from=2026-04-01 --to=2026-09-30
 *   php tools/run_monthly_summary.php --sites=JSS,SNMC
 *   php tools/run_monthly_summary.php --csv=/tmp/tsc          # writes 3 CSVs
 *   php tools/run_monthly_summary.php --cohort                # cohort basis
 *
 * One REDCap call. Nothing is written unless --csv is given.
 *
 * This exists so the numbers can be checked against what sites believe before
 * any of it reaches a slide. A figure nobody has reconciled is not ready for
 * a steering committee.
 */

require '/var/www/reports/vendor/autoload.php';
require '/var/www/reports/projects/Emollient/Aggregator/MonthlySiteSummaryAggregator.php';

use CEL\Projects\Emollient\Aggregator\MonthlySiteSummaryAggregator as Agg;

// ── arguments ────────────────────────────────────────────────────────────
$opt = getopt('', ['from::', 'to::', 'sites::', 'csv::', 'file::', 'html::', 'save::', 'cohort', 'post28']);
$from   = $opt['from']  ?? '2025-09-25';
$to     = $opt['to']    ?? date('Y-m-d');
$sites  = isset($opt['sites']) && $opt['sites'] !== ''
        ? array_map('trim', explode(',', $opt['sites'])) : [];
$csvDir = $opt['csv']   ?? null;
$basis  = isset($opt['cohort']) ? 'cohort' : 'event';
$post28 = isset($opt['post28']);

// ── records: from a saved JSON export, or live from REDCap ───────────────
// --file= lets the same run be repeated without another REDCap call, which
// matters when reconciling a figure with a site over several days.
$srcFile = $opt['file'] ?? null;

$FIELDS = [
    'record_id',
    'enr_datetime', 'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
    'dis_datetime', 'dis_discharge_type', 'dis_in_hosp', 'dis_hosp_code',
    'sae_start_date', 'sae_datetime', 'sae_hosp_code',
];
if ($post28) {
    $FIELDS[] = 'dis_post_28_discharge_type';
    $FIELDS[] = 'dis_post_28_datetime';
}
$EVENTS = ['day0_arm_1', 'discharge_arm_1', 'other_forms_arm_1'];

if ($srcFile !== null && $srcFile !== '') {
    if (!is_file($srcFile)) exit("no such file: {$srcFile}\n");
    $raw  = file_get_contents($srcFile);
    $rows = json_decode($raw, true);
    if (!is_array($rows)) exit("{$srcFile} is not a JSON array of records\n");
    fwrite(STDERR, sprintf("read %s rows from %s\n\n", number_format(count($rows)), $srcFile));
} else {
    Dotenv\Dotenv::createImmutable('/var/www/reports')->safeLoad();
    $url   = $_ENV['EMOLLIENT_REDCAP_URL']   ?? '';
    $token = $_ENV['EMOLLIENT_REDCAP_TOKEN'] ?? '';
    if ($url === '' || $token === '') exit("EMOLLIENT_REDCAP_URL / _TOKEN not set in .env\n");

    fwrite(STDERR, "fetching from REDCap ...\n");
    $t0 = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 300,
        CURLOPT_POSTFIELDS => http_build_query([
            'token' => $token, 'content' => 'record', 'format' => 'json',
            'type'  => 'flat', 'returnFormat' => 'json',
            'events' => $EVENTS, 'fields' => $FIELDS,
        ]),
    ]);
    $raw  = curl_exec($ch);
    $secs = microtime(true) - $t0;
    $rows = json_decode($raw, true);

    if (!is_array($rows) || isset($rows['error'])) {
        exit("REDCap said: " . substr((string)$raw, 0, 400) . "\n");
    }
    fwrite(STDERR, sprintf("  %s rows, %.1f MB, %.1fs\n\n",
        number_format(count($rows)), strlen($raw) / 1048576, $secs));
}

// ── run ──────────────────────────────────────────────────────────────────
$labels = require '/var/www/reports/projects/Emollient/site_labels.php';
$agg = new Agg('record_id', $sites, $from, $to, $labels, $post28);
$out = $agg->aggregate($rows);

// --save= keeps the raw export so later runs can use --file= and skip REDCap.
if (!empty($opt['save'])) {
    file_put_contents($opt['save'], json_encode($rows));
    fwrite(STDERR, "saved raw export to {$opt['save']}\n");
}

// --html= writes the full report through the real exporter, so the page can be
// seen before any of it is registered with the engine.
if (!empty($opt['html'])) {
    $exp = '/var/www/reports/projects/Emollient/Export/MonthlySiteSummaryHtmlExporter.php';
    if (!is_file($exp)) {
        fwrite(STDERR, "!! exporter not found at {$exp}\n");
    } else {
        require $exp;
        (new \CEL\Projects\Emollient\Export\MonthlySiteSummaryHtmlExporter(true))
            ->export($out, $opt['html']);
        fwrite(STDERR, "wrote {$opt['html']}\n");
    }
}

// ── print ────────────────────────────────────────────────────────────────
$months   = $out['months'];
$mlabels  = $out['month_labels'];
$siteList = $out['sites'];
$grid     = $out[$basis];
$mTotals  = $out[$basis === 'event' ? 'event_month_totals'  : 'cohort_month_totals'];
$sTotals  = $out[$basis === 'event' ? 'event_site_totals'   : 'cohort_site_totals'];
$grand    = $out[$basis === 'event' ? 'event_grand'         : 'cohort_grand'];

$W = 78;
echo str_repeat('=', $W), "\n";
printf("Monthly Site Summary — %s basis\n", strtoupper($basis));
printf("%s to %s   ·   generated %s\n", $from, $to, $out['period']['generated']);
echo str_repeat('=', $W), "\n\n";

/** One measure: sites down, months across. */
function grid_block(string $measure, string $title, array $siteList, array $months,
                    array $mlabels, array $grid, array $sTotals, array $labels): void
{
    echo "── ", $title, " ", str_repeat('─', max(0, 60 - strlen($title))), "\n";
    printf("%-10s", '');
    foreach ($mlabels as $ml) printf("%7s", substr($ml, 0, 3) . substr($ml, -2));
    printf("%9s\n", 'TOTAL');

    foreach ($siteList as $s) {
        printf("%-10s", substr($s, 0, 10));
        foreach ($months as $m) {
            $v = $grid[$s][$m][$measure] ?? 0;
            printf("%7s", $v === 0 ? '-' : $v);
        }
        printf("%9d\n", $sTotals[$s][$measure] ?? 0);
    }
    echo str_repeat(' ', 10), str_repeat('-', 7 * count($months) + 9), "\n";
    printf("%-10s", 'TOTAL');
    $tot = 0;
    foreach ($months as $m) {
        $v = 0;
        foreach ($siteList as $s) $v += $grid[$s][$m][$measure] ?? 0;
        $tot += $v;
        printf("%7s", $v === 0 ? '-' : $v);
    }
    printf("%9d\n\n", $tot);
}

foreach (Agg::MEASURES as $key => $title) {
    if ($key === 'unplanned') continue;             // shown in the summary below
    grid_block($key, $title, $siteList, $months, $mlabels, $grid, $sTotals, $labels);
}

// ── summary ──────────────────────────────────────────────────────────────
echo str_repeat('=', $W), "\n";
printf("%-28s %10s %10s %10s\n", 'Site', 'Enrolled', 'LAMA+DOPR', 'SAE');
echo str_repeat('-', $W), "\n";
foreach ($siteList as $s) {
    $t = $sTotals[$s];
    printf("%-28s %10d %10d %10d   %s\n",
        substr($labels[$s] ?? $s, 0, 28),
        $t['enrolled'], $t['unplanned'], $t['sae'],
        $t['enrolled'] > 0
            ? sprintf('(%.1f%% unplanned)', $t['unplanned'] / $t['enrolled'] * 100) : '');
}
echo str_repeat('-', $W), "\n";
printf("%-28s %10d %10d %10d\n", 'ALL SITES',
    $grand['enrolled'], $grand['unplanned'], $grand['sae']);

// ── data notes ───────────────────────────────────────────────────────────
$d = $out['diagnostics'];
echo "\n", str_repeat('=', $W), "\nDATA NOTES — things NOT in the numbers above\n", str_repeat('=', $W), "\n";
printf("  records in export                %6d\n", $d['babies_seen']);
printf("  no site, never enrolled          %6d   (expected - prescreening only)\n",
    $d['no_site_not_enrolled'] ?? 0);
printf("  no site BUT enrolled             %6d%s\n",
    $d['no_site_enrolled'] ?? 0,
    ($d['no_site_enrolled'] ?? 0) > 0 ? '   <-- chase these' : '');
if ($sites) printf("  excluded by --sites              %6d\n", $d['filtered_out_by_site']);
printf("  enrolled, no enr_datetime        %6d\n", $d['enrolled_no_date']);
printf("  enrolled, unparseable date       %6d\n", $d['enrolled_bad_date']);
printf("  enrolment outside the period     %6d\n", $d['outside_period']);

echo "\n  discharge type recorded but no dis_datetime (so not counted):\n";
if (empty($d['discharge_no_date'])) {
    echo "    none\n";
} else {
    arsort($d['discharge_no_date']);
    foreach ($d['discharge_no_date'] as $type => $n) {
        $flag = in_array($type, ['TYP_LAMA', 'TYP_DOPR'], true) ? '   <-- affects this report' : '';
        printf("    %-12s %5d%s\n", $type, $n, $flag);
    }
}

echo "\n  SAE form — which onset date is actually filled:\n";
printf("    forms present                  %6d\n", $d['sae_form_present']);
printf("    Q8 and Q5 both                 %6d\n", $d['sae_both']);
printf("    Q8 only (sae_start_date)       %6d\n", $d['sae_q8_only']);
printf("    Q5 only (sae_datetime)         %6d   <-- NOT counted, Q8 is blank\n", $d['sae_q5_only']);
printf("    Q8 blank, so uncounted         %6d\n", $d['sae_q8_missing']);
printf("    Q8 present but unparseable     %6d\n", $d['sae_q8_bad_date']);
if ($d['sae_q5_only'] > 0) {
    echo "\n    NOTE: Q5 is filled and Q8 is blank on ", $d['sae_q5_only'], " form(s). Those SAEs are\n";
    echo "    absent from the counts above. If that number is large, Q8 is not the field\n";
    echo "    sites are actually filling, and the dating field should be reconsidered.\n";
}

echo "\n  site code disagrees with enrolment form:\n";
printf("    dis_hosp_code differs          %6d\n", $d['site_mismatch_discharge']);
printf("    sae_hosp_code differs          %6d\n", $d['site_mismatch_sae']);

echo "\n  countable on event basis, unplaceable on cohort basis:\n";
foreach ($d['cohort_unplaceable'] as $k => $n) printf("    %-12s %5d\n", $k, $n);
printf("    (this is exactly the gap between the two bases' totals)\n");

echo "\n  excluded by design:\n";
foreach ($out['exclusions'] as $n) echo "    - ", wordwrap($n, 68, "\n      "), "\n";

// ── optional CSVs ────────────────────────────────────────────────────────
if ($csvDir !== null && $csvDir !== '') {
    if (!is_dir($csvDir)) mkdir($csvDir, 0775, true);

    $write = function (string $path, array $rows) {
        $fh = fopen($path, 'w');
        foreach ($rows as $r) fputcsv($fh, $r, ',', '"', '\\');
        fclose($fh);
        echo "  wrote ", $path, "\n";
    };

    foreach (['event', 'cohort'] as $b) {
        $rowsOut = [array_merge(['Site', 'Site name', 'Measure'], $out['month_labels'], ['TOTAL'])];
        foreach ($out['sites'] as $s) {
            foreach (Agg::MEASURES as $k => $title) {
                $line = [$s, $labels[$s] ?? $s, $title];
                $sum  = 0;
                foreach ($months as $m) {
                    $v = $out[$b][$s][$m][$k] ?? 0;
                    $line[] = $v; $sum += $v;
                }
                $line[] = $sum;
                $rowsOut[] = $line;
            }
        }
        $write(rtrim($csvDir, '/') . "/monthly_site_summary_{$b}.csv", $rowsOut);
    }

    // All seven discharge types — not on the report, available here.
    $rowsOut = [array_merge(['Site', 'Site name', 'Discharge type', 'Code'], $out['month_labels'], ['TOTAL'])];
    foreach ($out['sites'] as $s) {
        foreach ($out['discharge_type_labels'] as $code => $title) {
            $line = [$s, $labels[$s] ?? $s, $title, $code];
            $sum  = 0;
            foreach ($months as $m) {
                $v = $out['discharge_types'][$s][$m][$code] ?? 0;
                $line[] = $v; $sum += $v;
            }
            $line[] = $sum;
            $rowsOut[] = $line;
        }
    }
    $write(rtrim($csvDir, '/') . '/discharge_types_all.csv', $rowsOut);
}

echo "\n";

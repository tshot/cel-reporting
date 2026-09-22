<?php
/**
 * verify_los.php — run from anywhere:  php tools/verify_los.php
 *
 * 1. Prints the enrolment audit per site and per arm, and checks every row
 *    reconciles (enrolled = sum of outcomes).
 * 2. Prints the data-issue breakdown.
 * 3. Cross-checks 'Regular discharge form overdue' against the Pending column
 *    of DischargeFormCompletion, site by site. They must match.
 *
 * Makes two report runs, so two REDCap fetches. Run it once, not in a loop.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

use CEL\Reporting\Application\ReportFacade;

$f   = new ReportFacade();
$los = $f->generate('Emollient', 'LengthOfStay', []);

$short = [
    'withdrawn' => 'SW', 'protocol_deviation' => 'PD', 'still_in_study' => 'InStudy',
    'regular_overdue' => 'RegOvd', 'awaiting_post28' => 'Await28',
    'death' => 'Death', 'lama' => 'LAMA', 'abscond' => 'Absc', 'dopr' => 'DOPR',
    'referral' => 'Ref', 'data_issue' => 'DataIss', 'los_computed' => 'LOS',
];

function audit(string $title, array $tbl, array $short): bool
{
    echo "\n== $title ==\n";
    printf("%-10s %8s", 'group', 'Enrolled');
    foreach ($short as $s) printf(" %7s", $s);
    echo "   check\n";
    $ok = true;
    foreach ($tbl as $g => $r) {
        printf("%-10s %8d", $g === '' ? '(blank)' : $g, $r['enrolled']);
        foreach ($short as $k => $_) printf(" %7d", $r[$k]);
        echo $r['reconciles'] ? "   ok\n" : "   *** DOES NOT ADD UP ***\n";
        $ok = $ok && $r['reconciles'];
    }
    return $ok;
}

$okSite = audit('Audit by site', $los['audit_by_site'], $short);
$okArm  = audit('Audit by arm',  $los['audit_by_arm'],  $short);

echo "\n== Data issues ==\n";
if (empty($los['data_issues'])) {
    echo "none\n";
} else {
    foreach ($los['data_issues'] as $reason => $bySite) {
        printf("%4d  %s\n", $bySite['Total'], $los['data_issue_labels'][$reason]);
        foreach ($bySite as $s => $n) if ($s !== 'Total') printf("        %-8s %d\n", $s, $n);
    }
}

echo "\n== LOS (babies with LOS computed) ==\n";
printf("%-10s %6s %6s %7s %6s %5s %5s %6s\n", 'group', 'n', 'mean', 'median', 'sd', 'min', 'max', 'other');
foreach ($los['by_site'] as $g => $s) {
    printf("%-10s %6d %6s %7s %6s %5s %5s %6d\n", $g, $s['count'],
        $s['mean'] ?? '-', $s['median'] ?? '-', $s['std'] ?? '-',
        $s['min'] ?? '-', $s['max'] ?? '-', $s['count_other']);
}

echo "\n== Cross-check: Regular discharge form overdue  vs  DischargeFormCompletion Pending ==\n";
$dfc     = $f->generate('Emollient', 'DischargeFormCompletion', []);
$summary = $dfc['site_summary'] ?? [];
$okCross = true;
foreach ($los['audit_by_site'] as $g => $r) {
    $key     = ($g === 'Total') ? 'TOTAL' : $g;
    $pending = $summary[$key]['pending'] ?? null;
    $match   = ($pending === $r['regular_overdue']);
    $okCross = $okCross && $match;
    printf("%-10s overdue %4d   pending %4s   %s\n", $g, $r['regular_overdue'],
        $pending ?? 'n/a', $match ? 'ok' : '*** MISMATCH ***');
}

echo "\n", ($okSite && $okArm && $okCross)
    ? "ALL CHECKS PASSED\n"
    : "*** ONE OR MORE CHECKS FAILED — see above ***\n";

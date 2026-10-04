<?php
/**
 * Harness for MonthlySiteSummaryAggregator — runs it against hand-built
 * records whose correct answer is known, and asserts each one.
 */

namespace CEL\Shared\Domain\Aggregator {
    interface AggregatorInterface { public function aggregate(iterable $r): array; }
    abstract class AbstractAggregator implements AggregatorInterface {}
}

namespace {

require '/var/www/reports/projects/Emollient/Aggregator/MonthlySiteSummaryAggregator.php';

use CEL\Projects\Emollient\Aggregator\MonthlySiteSummaryAggregator as Agg;

$pass = 0; $fail = 0;
function check(string $what, $got, $want) {
    global $pass, $fail;
    $ok = $got === $want;
    $ok ? $pass++ : $fail++;
    printf("  [%s] %-56s got %-10s want %s\n",
        $ok ? 'ok' : 'FAIL', $what,
        is_scalar($got) ? var_export($got, true) : json_encode($got),
        is_scalar($want) ? var_export($want, true) : json_encode($want));
}

// ---------------------------------------------------------------- fixtures
function day0($id, $site, $consent, $enr, $arm = 'Control') {
    return ['record_id'=>$id, 'redcap_event_name'=>'day0_arm_1',
            'enr_hosp_code'=>$site, 'enr_consent_granted'=>$consent,
            'enr_datetime'=>$enr, 'enr_study_arm'=>$arm];
}
function disc($id, $type, $date, $site = '', $inHosp = 'N') {
    return ['record_id'=>$id, 'redcap_event_name'=>'discharge_arm_1',
            'dis_hosp_code'=>$site, 'dis_discharge_type'=>$type,
            'dis_datetime'=>$date, 'dis_in_hosp'=>$inHosp];
}
function sae($id, $q8, $q5 = '', $site = '') {
    return ['record_id'=>$id, 'redcap_event_name'=>'other_forms_arm_1',
            'sae_hosp_code'=>$site, 'sae_start_date'=>$q8, 'sae_datetime'=>$q5];
}

$labels = ['GTB'=>'GTB Delhi', 'JSS'=>'JSS Hospital', 'SNMC'=>'SNMC'];

$records = [
    // 1. GTB, enrolled Sep, LAMA in Nov  -> event Nov, cohort Sep
    day0('1', 'GTB', '1', '2025-09-10 10:00'),
    disc('1', 'TYP_LAMA', '2025-11-04 09:00', 'GTB'),

    // 2. GTB, enrolled Sep, DOPR but NO discharge date -> uncounted, diagnosed
    day0('2', 'GTB', 'Y', '2025-09-20'),
    disc('2', 'TYP_DOPR', '', 'GTB', 'Y'),

    // 3. JSS, enrolled Oct, SAE with Q8 -> counted in Q8's month (Dec)
    day0('3', 'JSS', '1', '2025-10-02'),
    sae ('3', '2025-12-15', '2025-12-10'),

    // 4. JSS, enrolled Oct, SAE with Q5 only -> present but NOT counted
    day0('4', 'JSS', '1', '2025-10-05'),
    sae ('4', '', '2025-11-01'),

    // 5. no site -> dropped, counted in diagnostics
    day0('5', '', '1', '2025-10-09'),

    // 6. consent refused -> not enrolled, but a LAMA with a date still counts
    //    on the event basis and cannot be placed on the cohort basis
    day0('6', 'SNMC', 'N', ''),
    disc('6', 'TYP_LAMA', '2025-10-20', 'SNMC'),

    // 7. SNMC, enrolled Nov, discharged per protocol -> no LAMA/DOPR
    day0('7', 'SNMC', '1', '2025-11-11'),
    disc('7', 'TYP_FP', '2025-11-30', 'SNMC'),

    // 8. site mismatch between enrolment and discharge
    day0('8', 'GTB', '1', '2025-09-15'),
    disc('8', 'TYP_DOPR', '2025-09-28', 'JSS'),
];

$agg = new Agg('record_id', [], '2025-09-01', '2025-12-31', $labels);
$out = $agg->aggregate($records);

echo "\n=== month axis (no gaps) ===\n";
check('months', $out['months'], ['2025-09','2025-10','2025-11','2025-12']);
check('month labels', $out['month_labels'], ['Sep 2025','Oct 2025','Nov 2025','Dec 2025']);
check('sites in site_labels order', $out['sites'], ['GTB','JSS','SNMC']);

echo "\n=== event basis ===\n";
check('GTB enrolled Sep',  $out['event']['GTB']['2025-09']['enrolled'], 3);  // 1,2,8
check('GTB LAMA Nov',      $out['event']['GTB']['2025-11']['lama'],     1);
check('GTB DOPR Sep (#8)', $out['event']['GTB']['2025-09']['dopr'],     1);
check('GTB unplanned Sep', $out['event']['GTB']['2025-09']['unplanned'],1);
check('JSS SAE Dec',       $out['event']['JSS']['2025-12']['sae'],      1);
check('JSS SAE Nov is 0',  $out['event']['JSS']['2025-11']['sae'],      0);
check('SNMC LAMA Oct',     $out['event']['SNMC']['2025-10']['lama'],    1);
check('SNMC enrolled Oct', $out['event']['SNMC']['2025-10']['enrolled'],0);

echo "\n=== cohort basis (back to enrolment month) ===\n";
check('GTB LAMA -> Sep',   $out['cohort']['GTB']['2025-09']['lama'],    1);
check('GTB LAMA not Nov',  $out['cohort']['GTB']['2025-11']['lama'],    0);
check('JSS SAE -> Oct',    $out['cohort']['JSS']['2025-10']['sae'],     1);
check('SNMC LAMA unplaceable (no enrolment month)',
                           $out['cohort']['SNMC']['2025-10']['lama'],   0);

echo "\n=== margins ===\n";
check('event grand enrolled',  $out['event_grand']['enrolled'],  6);
check('cohort grand enrolled', $out['cohort_grand']['enrolled'], 6);
check('event grand lama',      $out['event_grand']['lama'],      2);
check('cohort grand lama',     $out['cohort_grand']['lama'],     1);  // #6 unplaceable
check('event grand dopr',      $out['event_grand']['dopr'],      1);
check('event grand sae',       $out['event_grand']['sae'],       1);
check('GTB site total enrolled', $out['event_site_totals']['GTB']['enrolled'], 3);
check('Sep month total enrolled',$out['event_month_totals']['2025-09']['enrolled'], 3);

echo "\n=== all seven discharge types (CSV only) ===\n";
check('SNMC TYP_FP Nov', $out['discharge_types']['SNMC']['2025-11']['TYP_FP'], 1);
check('GTB TYP_LAMA Nov',$out['discharge_types']['GTB']['2025-11']['TYP_LAMA'],1);

echo "\n=== diagnostics ===\n";
check('babies seen',        $out['diagnostics']['babies_seen'],        8);
check('no site',            $out['diagnostics']['no_site'],            1);
check('DOPR with no date',  $out['diagnostics']['discharge_no_date']['TYP_DOPR'] ?? 0, 1);
check('SAE forms present',  $out['diagnostics']['sae_form_present'],   2);
check('SAE Q8 missing',     $out['diagnostics']['sae_q8_missing'],     1);
check('SAE both dates',     $out['diagnostics']['sae_both'],           1);
check('SAE Q5 only',        $out['diagnostics']['sae_q5_only'],        1);
check('site mismatch disch',$out['diagnostics']['site_mismatch_discharge'], 1);

echo "\n=== site filter ===\n";
$agg2 = new Agg('record_id', ['JSS'], '2025-09-01', '2025-12-31', $labels);
$o2   = $agg2->aggregate($records);
check('filtered sites',     $o2['sites'],                              ['JSS']);
check('filtered enrolled',  $o2['event_grand']['enrolled'],            2);
check('filtered out count', $o2['diagnostics']['filtered_out_by_site'], 5);

echo "\n=== date parsing ===\n";
$agg3 = new Agg('record_id', [], null, null, $labels);
$o3 = $agg3->aggregate([
    day0('a', 'GTB', '1', '2026-01-05 14:30'),   // Y-m-d H:i
    day0('b', 'GTB', '1', '2026-01-06'),         // Y-m-d
    day0('c', 'GTB', '1', '06/01/2026'),         // d/m/Y
    day0('d', 'GTB', '1', 'not a date'),         // junk
]);
check('three dates parsed, one rejected', $o3['event_grand']['enrolled'], 3);
check('bad date counted',                 $o3['diagnostics']['enrolled_bad_date'], 1);

echo "\n=== invariant: the two bases differ by exactly the unplaceable count ===\n";
foreach (array_keys(Agg::MEASURES) as $m) {
    check("event - cohort == unplaceable [$m]",
        $out['event_grand'][$m] - $out['cohort_grand'][$m],
        $out['diagnostics']['cohort_unplaceable'][$m]);
}

printf("\n%s  %d passed, %d failed\n", $fail ? 'FAILURES' : 'ALL PASS', $pass, $fail);
exit($fail ? 1 : 0);
}

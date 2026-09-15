<?php
/**
 * tools/diag_mech_venti.php
 * Lists all records satisfying the baby_mech_venti condition for a given site.
 * Shows the raw field values so we can verify which record is extra.
 */
ini_set('memory_limit', '256M');
$engineRoot = realpath(__DIR__ . '/../reporting-engine');
require $engineRoot . '/vendor/autoload.php';
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['project:', 'site:']);
$project = $opts['project'] ?? 'Emollient';
$site    = $opts['site']    ?? '';

$cfg    = require realpath($engineRoot . '/../projects') . "/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$fields = [
    'record_id', 'baby_hosp_code', 'baby_orderno',
    'baby_admit_nicu', 'baby_inclusion_brth_wt',
    'baby_admitted_nicu_24hrs',
    'baby_mech_venti_1', 'baby_venti_confirmation', 'baby_mech_venti_2',
    'baby_prescrn_eligible',
];

$stream = $client->stream($fields, [], ['day0_arm_1'], 200);

$found = [];
foreach ($stream as $row) {
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form') continue;
    if ($site && trim($row['baby_hosp_code'] ?? '') !== $site) continue;

    // baby_mech_venti condition (exactly as in EligibilityMetricDefinitions)
    $cond = (
        (
            (($row['baby_mech_venti_1']       ?? '') === 'Y')
            && (($row['baby_venti_confirmation'] ?? '') === 'Y')
        )
        || (($row['baby_mech_venti_2'] ?? '') === 'Y')
    )
    && (($row['baby_admitted_nicu_24hrs'] ?? '') === 'Yes')
    && (($row['baby_inclusion_brth_wt']   ?? '') === 'Yes');

    if (!$cond) continue;

    $found[] = [
        'record_id'      => $row['record_id'] ?? '',
        'instance'       => $row['redcap_repeat_instance'] ?? '',
        'orderno'        => $row['baby_orderno'] ?? '',
        'site'           => trim($row['baby_hosp_code'] ?? ''),
        'wt_incl'        => $row['baby_inclusion_brth_wt']   ?? '',
        'nicu_24hrs'     => $row['baby_admitted_nicu_24hrs']  ?? '',
        'venti_1'        => $row['baby_mech_venti_1']         ?? '',
        'venti_conf'     => $row['baby_venti_confirmation']   ?? '',
        'venti_2'        => $row['baby_mech_venti_2']         ?? '',
        'eligible'       => $row['baby_prescrn_eligible']     ?? '',
    ];
}

usort($found, fn($a,$b) => [$a['site'],$a['record_id']] <=> [$b['site'],$b['record_id']]);

echo "=== baby_mech_venti matches" . ($site ? " (site: $site)" : " (all sites)") . " ===\n";
echo "Total: " . count($found) . "\n\n";

$hdr = str_pad('record_id',14).str_pad('inst',5).str_pad('ord',5)
     . str_pad('site',8).str_pad('wt_incl',9).str_pad('nicu24',8)
     . str_pad('v1',5).str_pad('vconf',7).str_pad('v2',5)."eligible\n"
     . str_repeat('-',80)."\n";
echo $hdr;

foreach ($found as $r) {
    echo str_pad($r['record_id'],14).str_pad($r['instance'],5)
       . str_pad($r['orderno'],5).str_pad($r['site'],8)
       . str_pad($r['wt_incl'],9).str_pad($r['nicu_24hrs'],8)
       . str_pad($r['venti_1'],5).str_pad($r['venti_conf'],7)
       . str_pad($r['venti_2'],5).$r['eligible']."\n";
}

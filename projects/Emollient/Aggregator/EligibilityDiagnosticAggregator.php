<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * EligibilityDiagnosticAggregator — NOT for production dashboards.
 *
 * Outputs one CSV row per baby (repeat instance) showing:
 *  - all raw REDCap field values used in eligibility logic
 *  - which metrics the row matched
 *  - whether it was included or excluded by the date filter
 *  - the reason it was excluded (if applicable)
 *
 * Run with:
 *   php cli.php --project=Emollient --report=EligibilityDiagnosticAgg --output=elig_diag.csv
 */
class EligibilityDiagnosticAggregator extends AbstractAggregator
{
    private string    $primaryKey;
    private ?\DateTime $from = null;
    private ?\DateTime $to   = null;

    public function __construct(
        string $primaryKey,
        string $dateFrom = '',
        string $dateTo   = ''
    ) {
        $this->primaryKey = $primaryKey;
        $this->from = $dateFrom ? (new \DateTime($dateFrom))->setTime(0,  0,  0)  : null;
        $this->to   = $dateTo   ? (new \DateTime($dateTo  ))->setTime(23, 59, 59) : null;
    }

    public function aggregate(iterable $records): array
    {
        $output = [];

        foreach ($records as $row) {

            // ── Only process repeat instances (each baby is one instance) ──
            $instance = (int)($row['redcap_repeat_instance'] ?? 0);
            $recordId = $row[$this->primaryKey] ?? '';
            $hosp     = trim($row['baby_hosp_code'] ?? '');

            // ── Date filtering on baby_start_time ──────────────────────────
            $startTimeRaw    = $row['baby_start_time'] ?? '';
            $startTimeParsed = null;
            $dateStatus      = 'NO_DATE';
            $dateExclReason  = '';

            if (!empty($startTimeRaw)) {
                try {
                    $startTimeParsed = new \DateTime($startTimeRaw);
                    $dateStatus      = 'IN_RANGE';

                    if ($this->from && $startTimeParsed < $this->from) {
                        $dateStatus     = 'EXCLUDED';
                        $dateExclReason = 'DATE_BEFORE_FROM ('
                            . $startTimeParsed->format('Y-m-d H:i:s') . ' < '
                            . $this->from->format('Y-m-d H:i:s') . ')';
                    }

                    if ($this->to && $startTimeParsed > $this->to) {
                        $dateStatus     = 'EXCLUDED';
                        $dateExclReason = 'DATE_AFTER_TO ('
                            . $startTimeParsed->format('Y-m-d H:i:s') . ' > '
                            . $this->to->format('Y-m-d H:i:s') . ')';
                    }

                } catch (\Exception $e) {
                    $dateStatus     = 'EXCLUDED';
                    $dateExclReason = 'DATE_PARSE_FAILED: ' . $e->getMessage();
                }
            } elseif ($this->from || $this->to) {
                $dateStatus     = 'EXCLUDED';
                $dateExclReason = 'MISSING_BABY_START_TIME';
            }

            // ── Evaluate every metric against this row ─────────────────────
            $matchedMetrics = [];

            if ($instance > 0) {
                $matchedMetrics[] = 'babies_prescreened';
            }

            $checks = [
                'baby_admit_nicu' => fn($r) =>
                    ($r['baby_admit_nicu'] ?? '') === 'N',

                'birth_weight_no' => fn($r) =>
                    ($r['baby_inclusion_brth_wt'] ?? '') === 'No'
                    && ($r['baby_current_status'] ?? '') === 'PRESCRN_ALIVE',

                'baby_current_status' => fn($r) =>
                    ($r['baby_current_status']     ?? '') === 'PRESCRN_DEAD'
                    && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes',

                'prescrn_dead_brtwt_ineligble' => fn($r) =>
                    ($r['baby_current_status']     ?? '') === 'PRESCRN_DEAD'
                    && ($r['baby_inclusion_brth_wt'] ?? '') === 'No',

                'baby_admitted_nicu_24hrs' => fn($r) =>
                    ($r['baby_admitted_nicu_24hrs'] ?? '') === 'No'
                    && (
                        ($r['baby_mech_venti_1'] ?? '') === 'N'
                        || ($r['baby_mech_venti_2'] ?? '') === 'N'
                    )
                    && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes',

                'baby_mech_venti' => fn($r) =>
                    (
                        (
                            ($r['baby_mech_venti_1']       ?? '') === 'Y'
                            && ($r['baby_venti_confirmation'] ?? '') === 'Y'
                        )
                        || ($r['baby_mech_venti_2'] ?? '') === 'Y'
                    )
                    && ($r['baby_admitted_nicu_24hrs'] ?? '') === 'Yes'
                    && ($r['baby_inclusion_brth_wt']   ?? '') === 'Yes',

                'baby_other_study' => fn($r) =>
                    ($r['baby_oth_study_elig'] ?? '') === 'No',

                'baby_admitted_nicu_24hrs_venti' => fn($r) =>
                    ($r['baby_admitted_nicu_24hrs'] ?? '') === 'No'
                    && (
                        (
                            ($r['baby_mech_venti_1']       ?? '') === 'Y'
                            && ($r['baby_venti_confirmation'] ?? '') === 'Y'
                        )
                        || ($r['baby_mech_venti_2'] ?? '') === 'Y'
                    )
                    && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes',

                'twins' => fn($r) =>
                    ($r['baby_admitted_nicu_24hrs'] ?? '') === 'Yes'
                    && ($r['baby_inclusion_brth_wt'] ?? '') === 'Yes'
                    && (
                        ($r['baby_oth_study_elig'] ?? '') === 'Yes'
                        || ($r['baby_oth_study_elig'] ?? '') === ''
                    )
                    && (
                        (
                            ($r['baby_mech_venti_1']       ?? '') === 'N'
                            && ($r['baby_venti_confirmation'] ?? '') === 'Y'
                        )
                        || ($r['baby_mech_venti_2'] ?? '') === 'N'
                    )
                    && ($r['baby_prescrn_eligible'] ?? '') === 'No',

                'baby_prescrn_eligible' => fn($r) =>
                    ($r['baby_prescrn_eligible'] ?? '') === 'Yes',

                'baby_consent_administered' => fn($r) =>
                    ($r['baby_consent_administered'] ?? '') === 'N',

                'baby_screen_consent' => fn($r) =>
                    ($r['baby_screen_consent'] ?? '') === 'N',

                'baby_nicu_weight_elig' => fn($r) =>
                    ($r['baby_nicu_weight_elig']       ?? '') === 'No'
                    && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                    && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                    && ($r['baby_newborn_surgery']       ?? '') === 'N'
                    && ($r['baby_shock']                 ?? '') === 'N'
                    && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

                'baby_skin_disease_elig' => fn($r) =>
                    ($r['baby_skin_disease_elig']      ?? '') === 'No'
                    && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                    && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                    && ($r['baby_newborn_surgery']       ?? '') === 'N'
                    && ($r['baby_shock']                 ?? '') === 'N'
                    && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

                'baby_cong_mal_elig' => fn($r) =>
                    ($r['baby_cong_mal_elig']          ?? '') === 'No'
                    && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                    && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                    && ($r['baby_newborn_surgery']       ?? '') === 'N'
                    && ($r['baby_shock']                 ?? '') === 'N'
                    && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

                'baby_newborn_surgery' => fn($r) =>
                    ($r['baby_newborn_surgery']        ?? '') === 'Y'
                    && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                    && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                    && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                    && ($r['baby_shock']                 ?? '') === 'N'
                    && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

                'baby_shock' => fn($r) =>
                    ($r['baby_shock']                  ?? '') === 'Y'
                    && ($r['baby_newborn_surgery']       ?? '') === 'N'
                    && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                    && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                    && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes'
                    && ($r['baby_neurological_eligible'] ?? '') === 'Yes',

                'baby_neurological_eligible' => fn($r) =>
                    ($r['baby_neurological_eligible']  ?? '') === 'No'
                    && ($r['baby_shock']                 ?? '') === 'N'
                    && ($r['baby_newborn_surgery']       ?? '') === 'N'
                    && ($r['baby_cong_mal_elig']         ?? '') === 'Yes'
                    && ($r['baby_skin_disease_elig']     ?? '') === 'Yes'
                    && ($r['baby_nicu_weight_elig']      ?? '') === 'Yes',

                'baby_other_condition' => fn($r) =>
                    ($r['baby_other_condition'] ?? '') === 'Y',

                'baby_eligible_enroll' => fn($r) =>
                    ($r['baby_eligible_enroll'] ?? '') === 'Yes',
            ];

            foreach ($checks as $metric => $fn) {
                if ($fn($row)) {
                    $matchedMetrics[] = $metric;
                }
            }

            // ── Build output row ───────────────────────────────────────────
            $output[] = [
                'record_id'                => $recordId,
                'repeat_instance'          => $instance,
                'site'                     => $hosp,
                'baby_start_time_raw'      => $startTimeRaw,
                'baby_start_time_parsed'   => $startTimeParsed
                                                ? $startTimeParsed->format('Y-m-d H:i:s')
                                                : '',
                'date_status'              => $dateStatus,
                'date_excl_reason'         => $dateExclReason,

                // Raw field values
                'baby_admit_nicu'          => $row['baby_admit_nicu']          ?? '',
                'baby_inclusion_brth_wt'   => $row['baby_inclusion_brth_wt']   ?? '',
                'baby_current_status'      => $row['baby_current_status']       ?? '',
                'baby_admitted_nicu_24hrs' => $row['baby_admitted_nicu_24hrs']  ?? '',
                'baby_mech_venti_1'        => $row['baby_mech_venti_1']         ?? '',
                'baby_venti_confirmation'  => $row['baby_venti_confirmation']   ?? '',
                'baby_mech_venti_2'        => $row['baby_mech_venti_2']         ?? '',
                'baby_oth_study_elig'      => $row['baby_oth_study_elig']       ?? '',
                'baby_prescrn_eligible'    => $row['baby_prescrn_eligible']     ?? '',
                'baby_consent_administered'=> $row['baby_consent_administered'] ?? '',
                'baby_screen_consent'      => $row['baby_screen_consent']       ?? '',
                'baby_nicu_weight_elig'    => $row['baby_nicu_weight_elig']     ?? '',
                'baby_skin_disease_elig'   => $row['baby_skin_disease_elig']    ?? '',
                'baby_cong_mal_elig'       => $row['baby_cong_mal_elig']        ?? '',
                'baby_newborn_surgery'     => $row['baby_newborn_surgery']      ?? '',
                'baby_shock'               => $row['baby_shock']                ?? '',
                'baby_neurological_eligible'=> $row['baby_neurological_eligible'] ?? '',
                'baby_other_condition'     => $row['baby_other_condition']      ?? '',
                'baby_eligible_enroll'     => $row['baby_eligible_enroll']      ?? '',

                // Which metrics this row matched
                'matched_metrics'          => implode(' | ', $matchedMetrics),

                // Overall status for quick filtering
                'counted_in_report'        => ($dateStatus === 'IN_RANGE' && $instance > 0)
                                                ? 'YES' : 'NO',
            ];
        }

        // Sort by site, record_id, instance for easy scanning
        usort($output, fn($a, $b) =>
            [$a['site'], $a['record_id'], $a['repeat_instance']]
            <=>
            [$b['site'], $b['record_id'], $b['repeat_instance']]
        );

        return $output;
    }
}

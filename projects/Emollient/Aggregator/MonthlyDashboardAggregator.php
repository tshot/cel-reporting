<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * MonthlyEnrollmentDashboardAggregator
 *
 * Produces per-site counts for the Monthly Enrollment Dashboard table.
 *
 * Per-site output shape:
 * ┌─────────────────────────────────────────────────────────────────┐
 * │ Enrollment                                                      │
 * │   consent_no              — approached but consent refused      │
 * │   intervention_month      — enrolled to Intervention this month │
 * │   intervention_cumulative — enrolled to Intervention to date    │
 * │   control_month           — enrolled to Control this month      │
 * │   control_cumulative      — enrolled to Control to date         │
 * │   enrolled_month          — total enrolled this month (I + C)   │
 * │   enrolled_cumulative     — total enrolled to date   (I + C)    │
 * │ LAMA / Ref / Abs                                                │
 * │   lama_month / lama_cumulative / lama_percent                   │
 * │ SAE / Death                                                     │
 * │   sae_month  / sae_cumulative  / sae_percent                    │
 * └─────────────────────────────────────────────────────────────────┘
 *
 * Rules:
 *  - consent_no:    enr_consent_granted = '0' or 'N'  (NOT enrolled, counted separately)
 *  - enrolled:      enr_consent_granted = '1' or 'Y'  AND enr_datetime within range
 *  - intervention:  enrolled AND enr_study_arm = 'Intervention'
 *  - control:       enrolled AND enr_study_arm = 'Control'
 *  - lama/sae:      unchanged from previous logic
 */
class MonthlyDashboardAggregator extends AbstractAggregator
{
    private string    $primaryKey;
    private \DateTime $from;
    private \DateTime $to;
    private array     $targets = [];   // site code => monthly enrolment target

    public function __construct(
        string $primaryKey,
        string $dateFrom,
        string $dateTo,
        array  $targets = []
    ) 
    {
        $this->primaryKey = $primaryKey;
        $this->from       = (new \DateTime($dateFrom))->setTime(0,  0,  0);
        $this->to         = (new \DateTime($dateTo  ))->setTime(23, 59, 59);
        $this->targets    = $targets;   // site code => monthly enrolment target
    }

    /**
     * Number of months spanned by the reporting period (inclusive, fractional).
     * e.g. 01 May–30 Jun ≈ 2.0; a single calendar month ≈ 1.0. Used to scale a
     * monthly target to the selected window. Minimum 1 month so a sub-month
     * window never deflates the target below one month's worth.
     */
    private function monthsInPeriod(): float
    {
        $days   = ($this->to->getTimestamp() - $this->from->getTimestamp()) / 86400.0;
        $months = $days / (365.25 / 12.0);   // average month length
        return max(1.0, round($months, 2));
    }

    /**
     * Months from a start date (yyyy-mm-dd) to a given end DateTime, using the
     * average month length. Returns 0 if the start is missing or after the end.
     */
    private function monthsFromTo(?string $startYmd, \DateTime $end): float
    {
        if (empty($startYmd)) return 0.0;
        try { $start = (new \DateTime($startYmd))->setTime(0, 0, 0); }
        catch (\Throwable) { return 0.0; }
        if ($start >= $end) return 0.0;
        $days = ($end->getTimestamp() - $start->getTimestamp()) / 86400.0;
        return round($days / (365.25 / 12.0), 2);
    }

    public function aggregate(iterable $records): array
    {
        /*
        |----------------------------------------------------------------------
        | Step 1 — Group rows by record_id
        |----------------------------------------------------------------------
        */
        $recordsById = [];
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;
            $recordsById[$id][] = $row;
        }

        $sites = [];

        /*
        |----------------------------------------------------------------------
        | Step 2 — Process each participant
        |----------------------------------------------------------------------
        */
        foreach ($recordsById as $rows)
        {
            $site      = null;
            $enrDate   = null;
            $disDate   = null;
            $consentNo = false;   // approached but refused
            $consent   = false;   // consented and enrolled
            $studyArm  = null;    // 'Intervention' | 'Control' | null
            $lama      = false;
            $sae       = false;

            foreach ($rows as $row)
            {
                $event = $row['redcap_event_name'] ?? null;

                /*
                |--------------------------------------------------------------
                | Enrollment event (day0)
                |--------------------------------------------------------------
                */
                if ($event === 'day0_arm_1')
                {
                    $siteVal = $row['enr_hosp_code'] ?? null;
                    if (!empty($siteVal)) $site = $siteVal;

                    $consentVal = $row['enr_consent_granted'] ?? null;

                    if ($consentVal === '1' || $consentVal === 'Y')
                    {
                        $consent = true;
                    }
                    elseif ($consentVal === '0' || $consentVal === 'N')
                    {
                        $consentNo = true;
                    }

                    if (!empty($row['enr_datetime']))
                    {
                        $enrDate = new \DateTime($row['enr_datetime']);
                    }

                    // Study arm — REDCap coded values: 'Intervention' / 'Control'
                    $armVal = $row['enr_study_arm'] ?? null;
                    if (!empty($armVal)) $studyArm = $armVal;

                    // SAE flagged from intervention event
                    if (($row['int_no_emol_reason'] ?? null) === 'SAE')
                    {
                        $sae = true;
                    }
                }

                /*
                |--------------------------------------------------------------
                | Discharge event
                |--------------------------------------------------------------
                */
                if ($event === 'discharge_arm_1')
                {
                    if (!empty($row['dis_datetime']))
                    {
                        $disDate = new \DateTime($row['dis_datetime']);
                    }

                    $disType = $row['dis_discharge_type'] ?? null;

                    if (in_array($disType, ['TYP_LAMA', 'TYP_REF', 'TYP_ABS']))
                    {
                        $lama = true;
                    }

                    if ($disType === 'TYP_DEA')
                    {
                        $sae = true;
                    }
                }
            }

            // Bucket records with no site under null — skip silently
	    //
	    //
	    //
	    if ($site === null or $site === '')
	    {
		    continue ;
	    }

	    if (!isset($sites[$site]))
            {
                $sites[$site] = $this->emptySiteBucket();
            }

            /*
            |------------------------------------------------------------------
            | Consent refused — count regardless of date (it happened)
            |------------------------------------------------------------------
            */
            if ($consentNo)
            {
                $sites[$site]['consent_no']++;
            }

            /*
            |------------------------------------------------------------------
            | Enrollment counting — only consented records with a valid date
            |------------------------------------------------------------------
            */
            if ($consent && $enrDate)
            {
                // Cumulative: any enrollment up to end of reporting period
                if ($enrDate <= $this->to)
                {
                    $sites[$site]['enrolled_cumulative']++;

                    if ($studyArm === 'Intervention')
                        $sites[$site]['intervention_cumulative']++;
                    elseif ($studyArm === 'Control')
                        $sites[$site]['control_cumulative']++;
                }

                // This month: enrollment falls within the reporting window
                if ($enrDate >= $this->from && $enrDate <= $this->to)
                {
                    $sites[$site]['enrolled_month']++;

                    if ($studyArm === 'Intervention')
                        $sites[$site]['intervention_month']++;
                    elseif ($studyArm === 'Control')
                        $sites[$site]['control_month']++;
                }
            }

            /*
            |------------------------------------------------------------------
            | LAMA / Ref / Abs
            |------------------------------------------------------------------
            */
            if ($lama && $disDate)
            {
                if ($disDate <= $this->to)
                    $sites[$site]['lama_cumulative']++;

                if ($disDate >= $this->from && $disDate <= $this->to)
                    $sites[$site]['lama_month']++;
            }

            /*
            |------------------------------------------------------------------
            | SAE / Death
            |------------------------------------------------------------------
            */
            if ($sae && $disDate)
            {
                if ($disDate <= $this->to)
                    $sites[$site]['sae_cumulative']++;

                if ($disDate >= $this->from && $disDate <= $this->to)
                    $sites[$site]['sae_month']++;
            }
        }

        /*
        |----------------------------------------------------------------------
        | Step 3 — Compute percentages per site
        |----------------------------------------------------------------------
        */
        $months = $this->monthsInPeriod();

        foreach ($sites as $code => &$data)
        {
            $enrCumul = $data['enrolled_cumulative'];

            $data['lama_percent'] = $enrCumul > 0
                ? round(($data['lama_cumulative'] / $enrCumul) * 100, 2) : 0;

            $data['sae_percent'] = $enrCumul > 0
                ? round(($data['sae_cumulative'] / $enrCumul) * 100, 2) : 0;

            // ── Enrolment vs target ───────────────────────────────────────────
            $cfg            = $this->targets[$code] ?? null;
            $monthlyTarget  = (float)($cfg['monthly'] ?? 0);
            $firstColl      = $cfg['first_collection'] ?? null;
            $siteStart      = $cfg['site_start'] ?? null;

            // Period target: monthly scaled to the selected window.
            $targetPeriod   = $monthlyTarget * $months;

            // Cumulative target: monthly scaled by months from the site's first
            // data-collection date to the end of the reporting period — so a
            // newer site is only judged over the time it has been active.
            $cumMonths      = $this->monthsFromTo($firstColl, $this->to);
            $targetCum      = $monthlyTarget * $cumMonths;

            $data['target_monthly']     = $monthlyTarget;
            $data['first_collection']   = $firstColl;
            $data['site_start']         = $siteStart;

            $data['target_period']      = round($targetPeriod, 1);
            $data['target_pct']         = $targetPeriod > 0
                ? round(($data['enrolled_month'] / $targetPeriod) * 100, 1) : null;
            $data['target_gap']         = round($data['enrolled_month'] - $targetPeriod, 1);

            $data['target_cumulative']  = round($targetCum, 1);
            $data['cum_months']         = round($cumMonths, 2);
            $data['target_cum_pct']     = $targetCum > 0
                ? round(($data['enrolled_cumulative'] / $targetCum) * 100, 1) : null;
            $data['target_cum_gap']     = round($data['enrolled_cumulative'] - $targetCum, 1);
        }
        unset($data);

        return $this->addTotals($sites);
    }

    // ── Empty per-site counter bucket ─────────────────────────────────────────

    private function emptySiteBucket(): array
    {
        return [
            'consent_no'               => 0,
            'intervention_month'       => 0,
            'intervention_cumulative'  => 0,
            'control_month'            => 0,
            'control_cumulative'       => 0,
            'enrolled_month'           => 0,
            'enrolled_cumulative'      => 0,
            'lama_month'               => 0,
            'lama_cumulative'          => 0,
            'lama_percent'             => 0,
            'sae_month'                => 0,
            'sae_cumulative'           => 0,
            'sae_percent'              => 0,
        ];
    }

    // ── Sum all sites into a TOTAL row ────────────────────────────────────────

    private function addTotals(array $sites): array
    {
        $totals = $this->emptySiteBucket();

        // Sum raw counts — percentages are recalculated from totals below
        $countKeys = [
            'consent_no',
            'intervention_month',   'intervention_cumulative',
            'control_month',        'control_cumulative',
            'enrolled_month',       'enrolled_cumulative',
            'lama_month',           'lama_cumulative',
            'sae_month',            'sae_cumulative',
        ];

        foreach ($sites as $data)
        {
            foreach ($countKeys as $key)
            {
                $totals[$key] += $data[$key] ?? 0;
            }
        }

        $enrCumul = $totals['enrolled_cumulative'];

        $totals['lama_percent'] = $enrCumul > 0
            ? round(($totals['lama_cumulative'] / $enrCumul) * 100, 2) : 0;

        $totals['sae_percent'] = $enrCumul > 0
            ? round(($totals['sae_cumulative'] / $enrCumul) * 100, 2) : 0;

        // Target roll-up.
        $months             = $this->monthsInPeriod();
        $totalMonthlyTarget = 0.0;
        $totalTargetCum     = 0.0;
        foreach ($this->targets as $code => $cfg) {
            $mt = (float)($cfg['monthly'] ?? 0);
            $totalMonthlyTarget += $mt;
            $totalTargetCum     += $mt * $this->monthsFromTo($cfg['first_collection'] ?? null, $this->to);
        }
        $totalTargetPeriod = $totalMonthlyTarget * $months;

        $totals['target_monthly']    = $totalMonthlyTarget;
        $totals['first_collection']  = null;
        $totals['site_start']        = null;
        $totals['target_period']     = round($totalTargetPeriod, 1);
        $totals['target_pct']        = $totalTargetPeriod > 0
            ? round(($totals['enrolled_month'] / $totalTargetPeriod) * 100, 1) : null;
        $totals['target_gap']        = round($totals['enrolled_month'] - $totalTargetPeriod, 1);

        $totals['target_cumulative'] = round($totalTargetCum, 1);
        $totals['target_cum_pct']    = $totalTargetCum > 0
            ? round(($totals['enrolled_cumulative'] / $totalTargetCum) * 100, 1) : null;
        $totals['target_cum_gap']    = round($totals['enrolled_cumulative'] - $totalTargetCum, 1);

        $sites['TOTAL'] = $totals;

        return $sites;
    }
}

<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Support\SiteResolver;
use CEL\Projects\Emollient\Aggregator\EligibilityMetricDefinitions;
use CEL\Projects\Emollient\Aggregator\EligibilityWorkflow;

/**
 * WeeklyMeetingAggregator
 *
 * Produces a three-section site-wise summary for the weekly PI meeting.
 *
 * Section 1 — Pre-Screening      (date field: baby_datetime)
 * Section 2 — Screening          (date field: baby_datetime / enr_datetime)
 * Section 3 — Discharge          (date field: per metric — see below)
 *
 * Also produces raw per-record arrays for the Excel download:
 *   rawPrescreening  — one row per pre-screened baby in period
 *   rawDischarge     — one row per discharged baby in period
 *   rawDay28Status   — one row per baby still in hospital / 28-day status
 *   rawFollowup29    — one row per baby with 29-day follow-up in period
 */
class WeeklyMeetingAggregator extends AbstractAggregator
{
    private string       $primaryKey;
    private array        $siteFilter;
    private ?\DateTime   $from;
    private ?\DateTime   $to;
    private SiteResolver $siteResolver;

    // Raw data storage — keyed by record_id, event
    // Each event's data stored separately so we don't mix day0 and discharge
    private array $rawByRecord = [];  // [record_id][event_key] => field values

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    ) {
        $this->primaryKey   = $primaryKey;
        $this->siteFilter   = array_map('trim', $siteFilter);
        $this->from         = $dateFrom ? (new \DateTime($dateFrom))->setTime(0,  0,  0) : null;
        $this->to           = $dateTo   ? (new \DateTime($dateTo  ))->setTime(23, 59, 59) : null;
        $this->siteResolver = new SiteResolver();
    }

    // =========================================================================
    // Aggregate
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        $prescreening = [];
        $screening    = [];
        $discharge    = [];

        $eligMetrics = EligibilityMetricDefinitions::metrics();

        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            $event      = $row['redcap_event_name']        ?? '';
            $repeatForm = $row['redcap_repeat_instrument'] ?? '';

            // ── Resolve site ──────────────────────────────────────────────
            $site = '';
            foreach (['baby_hosp_code', 'enr_hosp_code', 'dis_hosp_code',
                      'pd_hosp_code', 'fu28_hosp_name'] as $f) {
                $v = trim($row[$f] ?? '');
                if ($v !== '') { $site = $v; break; }
            }

            if (!$this->siteOk($site)) continue;

            // =================================================================
            // SECTION 1 & 2 — Pre-Screening + Screening (day0_arm_1 repeating)
            // =================================================================
            if ($event === 'day0_arm_1'
                && $repeatForm === 'baby_prescreening_and_screening_form')
            {
                $raw = trim($row['baby_datetime'] ?? '');
                if ($raw === '') continue;

                // ── Raw storage — store ALL in-range instances ────────────
                // Prescreening is a repeating form — each submission is a
                // separate screening event. Store all, not just the first.
                if ($this->dateOk($raw)) {
                    $this->rawByRecord[$id]['prescreening_rows'][] = [
                        'site'      => $site,
                        'date'      => $raw,
                        'birth_wt'  => trim($row['baby_birth_wt_hosp']   ?? ''),
                        'ga'        => trim($row['baby_ga']               ?? ''),
                        'eligible'  => trim($row['baby_eligible_enroll']  ?? ''),
                    ];
                }

                if (!$this->dateOk($raw)) continue;

                foreach ($eligMetrics as $metric => $condition) {
                    if ($condition($row)) {
                        $this->inc2($prescreening, $site, $metric);
                    }
                }

                $bwt = trim($row['baby_birth_wt_hosp'] ?? '');
                if (is_numeric($bwt)) {
                    $bwt = (float)$bwt;
                    if ($bwt < 700)                    $this->inc2($prescreening, $site, 'bwt_below_700');
                    if ($bwt >= 700  && $bwt <= 999)   $this->inc2($prescreening, $site, 'bwt_700_999');
                    if ($bwt >= 1000 && $bwt <= 1499)  $this->inc2($prescreening, $site, 'bwt_1000_1499');
                    if ($bwt >= 1500 && $bwt <= 1800)  $this->inc2($prescreening, $site, 'bwt_1500_1800');
                    if ($bwt > 1800)                   $this->inc2($prescreening, $site, 'bwt_above_1800');
                }
            }

            // =================================================================
            // SECTION 2 — Enrollment (day0_arm_1 non-repeating)
            // =================================================================
            if ($event === 'day0_arm_1' && $repeatForm === '')
            {
                $enrSite = trim($row['enr_hosp_code'] ?? '');
                if ($enrSite === '' || !$this->siteOk($enrSite)) continue;

                $enrDateRaw = trim($row['enr_datetime'] ?? '');
                $consent    = trim($row['enr_consent_granted'] ?? '');
                $arm        = trim($row['enr_study_arm']       ?? '');

                // ── Raw storage for ALL enrolled (date applied later) ──────
                if ($enrDateRaw !== '' && ($consent === '1' || $consent === 'Y')) {
                    $this->rawByRecord[$id]['enrollment'] = [
                        'site'     => $enrSite,
                        'arm'      => $arm,
                        'date'     => $enrDateRaw,
                        'in_range' => $this->dateOk($enrDateRaw),
                    ];
                }

                if ($enrDateRaw === '') continue;
                if (!$this->dateOk($enrDateRaw)) continue;

                if ($consent === '0' || $consent === 'N')
                {
                    $this->inc2($screening, $enrSite, 'enr_consent_refused');
                }
                elseif ($consent === '1' || $consent === 'Y')
                {
                    $this->inc2($screening, $enrSite, 'enr_enrolled');
                    if ($arm === 'Intervention')
                        $this->inc2($screening, $enrSite, 'enr_intervention');
                    elseif ($arm === 'Control')
                        $this->inc2($screening, $enrSite, 'enr_control');
                }
            }

            // =================================================================
            // SECTION 3 — Discharge (discharge_arm_1)
            // =================================================================
            if ($event === 'discharge_arm_1')
            {
                $disSite  = trim($row['dis_hosp_code'] ?? '');
                if ($disSite === '' || !$this->siteOk($disSite)) continue;

                $disDate  = trim($row['dis_datetime']            ?? '');
                $complete = trim($row['discharge_form_complete']  ?? '');
                $disType  = trim($row['dis_discharge_type']       ?? '');
                $inHosp   = trim($row['dis_in_hosp']              ?? '');
                $disAge   = trim($row['dis_age_discharge']        ?? '');

                // ── Raw storage for ALL discharge records ─────────────────
                $this->rawByRecord[$id]['discharge'] = [
                    'site'      => $disSite,
                    'dis_date'  => $disDate,
                    'dis_type'  => $disType,
                    'in_hosp'   => $inHosp,
                    'age_dis'   => $disAge,
                    'in_range'  => $disDate !== '' && $this->dateOk($disDate),
                ];

                if ($disDate !== '' && $this->dateOk($disDate)) {
                    if ($complete !== '' && $inHosp === 'N')
                        $this->inc2($discharge, $disSite, 'dis_total');
                    if (in_array($disType, ['TYP_LAMA', 'TYP_ABS'], true))
                        $this->inc2($discharge, $disSite, 'dis_lama_abscond');
                    if ($disType === 'TYP_DOPR')
                        $this->inc2($discharge, $disSite, 'dis_dopr');
                    if ($disType === 'TYP_DEA')
                        $this->inc2($discharge, $disSite, 'dis_death');
                }

                if ($inHosp === 'Y'
                    && trim($row['discharge_after_28_days_of_stay_complete'] ?? '0') !== '2')
                {
                    $this->inc2($discharge, $disSite, 'dis_28_days');
                }
            }

            // =================================================================
            // SECTION 3 — Protocol Deviation / SAE / Withdrawal
            // =================================================================
            if ($event === 'other_forms_arm_1')
            {
                $pdSite = trim($row['pd_hosp_code'] ?? '');
                if ($pdSite === '' || !$this->siteOk($pdSite)) continue;

                // ── Track unique stopped record_ids for still-in-hospital ─
                // Store regardless of date filter — cumulative stopped count
                $pdDate2  = trim($row['pd_datetime']  ?? '');
                $saeDate2 = trim($row['sae_datetime'] ?? '');
                $swDate2  = trim($row['sw_datetime']  ?? '');
                if (($pdDate2  !== '' && trim($row['protocol_deviation_form_complete'] ?? '') !== '')
                 || ($saeDate2 !== '')
                 || ($swDate2  !== '')) {
                    if (!isset($this->rawByRecord[$id]['stopped'])) {
                        $this->rawByRecord[$id]['stopped'] = ['site' => $pdSite];
                    }
                }


                $pdDate = trim($row['pd_datetime'] ?? '');
                if ($pdDate !== '' && $this->dateOk($pdDate)) 
                {
                    if (trim($row['protocol_deviation_form_complete'] ?? '') !== '')
                        $this->inc2($discharge, $pdSite, 'dis_protocol_dev');
                }

                $saeDate = trim($row['sae_datetime'] ?? '');
                if ($saeDate !== '' && $this->dateOk($saeDate))
                    $this->inc2($discharge, $pdSite, 'dis_sae');

                $swDate = trim($row['sw_datetime'] ?? '');
                if ($swDate !== '' && $this->dateOk($swDate))
                    $this->inc2($discharge, $pdSite, 'dis_withdrawal');
            }

            // =================================================================
            // SECTION 3 — 29-day Follow-up (day29_arm_1)
            // =================================================================
            if ($event === 'day29_arm_1')
            {
                $fuSite = trim($row['fu28_hosp_name'] ?? '');
                if ($fuSite === '' || !$this->siteOk($fuSite)) continue;

                $fuDate = trim($row['fu28_datetime'] ?? '');
                $fuDone = trim($row['fu28_day28_fup']    ?? '');
                $alive  = trim($row['fu28_alive_day28']  ?? '');

                // ── Raw storage ───────────────────────────────────────────
                if ($fuDate !== '') 
                {
                    $this->rawByRecord[$id]['followup'] = [
                        'site'     => $fuSite,
                        'fu_date'  => $fuDate,
                        'fu_done'  => $fuDone,
                        'alive'    => $alive,
                        'in_range' => $this->dateOk($fuDate),
                    ];
                }

                if ($fuDate !== '' && $this->dateOk($fuDate)) 
                {
                    if ($fuDone === 'DONE')
                        $this->inc2($discharge, $fuSite, 'dis_fu29_done');
                    if ($alive === 'ALV_N')
                        $this->inc2($discharge, $fuSite, 'dis_death_fu29');
                }
            }
        }

        // ── Post-pass: derived eligibility totals ─────────────────────────
        EligibilityWorkflow::applyDerivedTotals($prescreening);

        foreach ($prescreening as $metric => $siteCounts) {
            if (!isset($screening[$metric])) {
                $screening[$metric] = $siteCounts;
            }
        }

        // ── Post-pass: derive still_in_hospital ───────────────────────────
        // Formula: Total enrolled (cumulative, all-time up to date_to)
        //          minus UNIQUE babies with any of:
        //            - discharge form filed (in_hosp = 'Y' OR 'N')
        //            - Protocol Deviation
        //            - SAE
        //            - Withdrawal
        //
        // A baby with multiple stop reasons (e.g. SAE leading to discharge)
        // counts as ONE stopped baby, not two.
        //
        // Breakup by arm uses enr_study_arm from day0_arm_1.

        // Build unique stopped sets per site AND per arm-site combination
        $stoppedIds      = [];  // site => [record_id => true]
        $stoppedIdsArm   = [];  // arm => site => [record_id => true]

        foreach ($this->rawByRecord as $id => $events) 
        {
            $arm  = $events['enrollment']['arm']  ?? '';
            $site = $events['enrollment']['site'] ?? '';

            // Skip if not enrolled (no arm, no site)
            if ($arm === '' || $site === '') continue;

            // Stopped via discharge form (in_hosp = 'Y' or 'N')
            $hasDischarge = isset($events['discharge'])
                && in_array($events['discharge']['in_hosp'], ['Y', 'N'], true);
            // Stopped via PD/SAE/withdrawal
            $hasOtherStop = isset($events['stopped']);

            if ($hasDischarge || $hasOtherStop) 
            {
                $stoppedIds[$site][$id]   = true;
                $stoppedIds['Total'][$id] = true;
                if ($arm === 'Intervention' || $arm === 'Control') 
                {
                    $stoppedIdsArm[$arm][$site][$id]   = true;
                    $stoppedIdsArm[$arm]['Total'][$id] = true;
                }
            }
        }

        // Build enrolled sets per site AND per arm
        $enrolledIds    = [];  // site => [record_id => true]
        $enrolledIdsArm = [];  // arm => site => [record_id => true]

        foreach ($this->rawByRecord as $id => $events) 
        {
            if (!isset($events['enrollment'])) continue;
            $arm  = $events['enrollment']['arm']  ?? '';
            $site = $events['enrollment']['site'] ?? '';
            if ($site === '') continue;

            $enrolledIds[$site][$id]   = true;
            $enrolledIds['Total'][$id] = true;
            if ($arm === 'Intervention' || $arm === 'Control') 
            {
                $enrolledIdsArm[$arm][$site][$id]   = true;
                $enrolledIdsArm[$arm]['Total'][$id] = true;
            }
        }

        // Still in hospital = enrolled - stopped (unique counts)
        $allSites = array_unique(array_merge(
            array_keys($enrolledIds),
            array_keys($stoppedIds)
        ));

        foreach ($allSites as $site) 
        {
            $nEnrolled = count($enrolledIds[$site] ?? []);
            $nStopped  = count($stoppedIds[$site]  ?? []);
            $discharge['dis_still_in_hospital'][$site] = max(0, $nEnrolled - $nStopped);
        }

        // Arm breakup
        foreach (['Intervention' => 'dis_still_in_hospital_intervention',
                  'Control'      => 'dis_still_in_hospital_control'] as $arm => $metric) 
        {
            $sitesForArm = array_unique(array_merge(
                array_keys($enrolledIdsArm[$arm] ?? []),
                array_keys($stoppedIdsArm[$arm]  ?? [])
            ));
            foreach ($sitesForArm as $site) 
            {
                $nEnrolled = count($enrolledIdsArm[$arm][$site] ?? []);
                $nStopped  = count($stoppedIdsArm[$arm][$site]  ?? []);
                $discharge[$metric][$site] = max(0, $nEnrolled - $nStopped);
            }
        }

        // ── Post-pass: derive dis_28_days ──────────────────────────────────
        // 28 days completed — still in Hospital
        // = babies with discharge form filed AND in_hosp = 'Y'
        //   MINUS babies with discharge_after_28_days_of_stay form complete
        //
        // i.e. babies who reached day-28 still admitted but haven't yet
        // completed the formal day-28 discharge form.
        //
        // The dis_28_days counter was already incremented during the loop —
        // here we don't override; that logic is correct as-is. Keeping comment
        // for clarity.

        // ── Build raw arrays for Excel exporter ───────────────────────────
        // Each raw array uses the 'in_range' flag set during the main loop.
        // This way raw data respects the same date filter as the summary counts.
        $rawPrescreening = [];
        $rawDischarge    = [];
        $rawDay28Status  = [];
        $rawFollowup29   = [];

        foreach ($this->rawByRecord as $id => $events) 
        {

            // ── Pre-Screening sheet ───────────────────────────────────────
            // One row per screening instance (repeating form) in date range.
            // Matches exactly what the HTML report counts.
            foreach ($events['prescreening_rows'] ?? [] as $p) 
            {
                $rawPrescreening[] = [
                    'record_id'  => $id,
                    'site'       => $p['site'],
                    'date'       => $p['date'],
                    'birth_wt_g' => $p['birth_wt'],
                    'ga_weeks'   => $p['ga'],
                    'eligible'   => $p['eligible'],
                ];
            }

            // ── Discharge sheet ───────────────────────────────────────────
            // One row per baby discharged (dis_datetime in range).
            // Only babies with dis_in_hosp = 'N' (actually discharged).
            // dis_type is always present for these — in_hosp='N' requires a type.
            if (isset($events['discharge']) && $events['discharge']['in_range']
                && $events['discharge']['in_hosp'] === 'N') 
            {
                $d   = $events['discharge'];
                $enr = $events['enrollment'] ?? [];
                $rawDischarge[] = [
                    'record_id'   => $id,
                    'site'        => $d['site'],
                    'arm'         => $enr['arm']  ?? '',
                    'enr_date'    => $enr['date'] ?? '',
                    'dis_date'    => $d['dis_date'],
                    'dis_type'    => $d['dis_type'],   // TYP_DEA, TYP_LAMA etc.
                    'age_at_dis'  => $d['age_dis'],
                ];
            }

            // ── 28-Day Status sheet ───────────────────────────────────────
            // Shows babies who are STOPPED (any dis_type, in_hosp=N) OR
            // still in hospital at day 28 (in_hosp=Y).
            // Excludes babies with no discharge record at all.
            // No date filter — snapshot of current status.
            if (isset($events['discharge'])) 
            {
                $d   = $events['discharge'];
                $enr = $events['enrollment'] ?? [];
                $status = $d['in_hosp'] === 'N'
                    ? ($d['dis_type'] ?: 'Discharged')
                    : 'Still in Hospital';
                $rawDay28Status[] = [
                    'record_id'  => $id,
                    'site'       => $d['site'],
                    'arm'        => $enr['arm']  ?? '',
                    'enr_date'   => $enr['date'] ?? '',
                    'dis_date'   => $d['dis_date'],
                    'dis_type'   => $d['dis_type'],
                    'in_hosp'    => $d['in_hosp'],
                    'status'     => $status,
                    'age_at_dis' => $d['age_dis'],
                ];
            }

            // ── 29-Day Follow-up sheet ────────────────────────────────────
            // One row per baby with fu28_datetime in range.
            if (isset($events['followup']) && $events['followup']['in_range']) {
                $f   = $events['followup'];
                $enr = $events['enrollment'] ?? [];
                $rawFollowup29[] = [
                    'record_id' => $id,
                    'site'      => $f['site'],
                    'arm'       => $enr['arm']  ?? '',
                    'fu_date'   => $f['fu_date'],
                    'fu_done'   => $f['fu_done'],
                    'alive_d29' => $f['alive'],
                ];
            }
        }

        return [
            'prescreening'    => $prescreening,
            'screening'       => $screening,
            'discharge'       => $discharge,
            'metricMeta'      => EligibilityMetricDefinitions::metricMeta(),
            'rawPrescreening' => $rawPrescreening,
            'rawDischarge'    => $rawDischarge,
            'rawDay28Status'  => $rawDay28Status,
            'rawFollowup29'   => $rawFollowup29,
            'period'          => [
                'date_from' => $this->from?->format('Y-m-d'),
                'date_to'   => $this->to?->format('Y-m-d'),
            ],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function inc2(array &$data, string $site, string $metric): void
    {
        if ($site === '') return;
        $data[$metric][$site]   = ($data[$metric][$site]   ?? 0) + 1;
        $data[$metric]['Total'] = ($data[$metric]['Total'] ?? 0) + 1;
    }

    private function siteOk(string $site): bool
    {
        if ($site === '') return false;
        if (empty($this->siteFilter)) return true;
        return in_array($site, $this->siteFilter, true);
    }

    private function dateOk(string $raw): bool
    {
        if (!$this->from && !$this->to) return true;
        try   { $dt = new \DateTime($raw); }
        catch (\Exception) { return false; }
        if ($this->from && $dt < $this->from) return false;
        if ($this->to   && $dt > $this->to)   return false;
        return true;
    }
}

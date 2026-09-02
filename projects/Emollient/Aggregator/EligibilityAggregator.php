<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Support\SiteResolver;
use CEL\Projects\Emollient\Aggregator\EligibilityMetricDefinitions;
use CEL\Projects\Emollient\Aggregator\EligibilityWorkflow;

/**
 * EligibilityAggregator
 *
 * Single-pass aggregator that produces data for all three report pages
 * from one stream of day0_arm_1 records.
 *
 * Page 1 - Eligibility:   prescreening / screening metrics per site
 * Page 2 - Enrollment:    consent refused + Intervention/Control breakdown
 * Page 3 - Demographics:  baby age, weight, SES fields per eligible baby
 *                         GA is added later via aggregateBaseline()
 *
 * Single-pass means each row is evaluated once as it arrives and then
 * discarded -- no rows are held in memory simultaneously.
 */
class EligibilityAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private ?\DateTime $from       = null;
    private ?\DateTime $to         = null;
    private array      $siteFilter = [];   // empty = all sites

    public function __construct(
        string $primaryKey,
        string $dateFrom   = '',
        string $dateTo     = '',
        array  $siteFilter = []
    ) 
    {
        $this->primaryKey = $primaryKey;
        $this->from       = $dateFrom ? (new \DateTime($dateFrom))->setTime(0,  0,  0) : null;
        $this->to         = $dateTo   ? (new \DateTime($dateTo  ))->setTime(23, 59, 59) : null;
        $this->siteFilter = array_map('trim', $siteFilter);
    }

    // =========================================================================
    // AggregatorInterface
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        // Accumulators — keyed by record_id, then built up row by row
        $eligibility      = [];   // metric => [ site => count ]
        $eligibilityByDc  = [];   // metric => [ dc_id => count ]  — same shape, grouped by data collector
        $enrollment       = [];   // site   => [ consent_refused, intervention, control, enrolled ]
        $demographics     = [];   // record_id => [ field => value ]

        // Track per-record state needed across rows from different forms
        $recordSite      = [];   // record_id => baby_hosp_code
        $recordDc        = [];   // record_id => baby_dc_id (screening form data collector)
        $recordEligible  = [];   // record_id => bool (baby_eligible_enroll = Yes)
        $recordEnrolled  = [];   // record_id => bool (enr_consent_granted = Y/1)
        $recordEnrSite   = [];   // record_id => enr_hosp_code
        $gaByRecord      = [];   // record_id => float (decimal weeks from baseline_arm_1)

        $metrics = EligibilityMetricDefinitions::metrics();

        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            // ── Resolve site from whichever _hosp_code field is present ──
            $rowSite = '';
            foreach (['baby_hosp_code', 'enr_hosp_code', 'base_hosp_code', 'ses_hosp_code'] as $f)
            {
                $v = trim($row[$f] ?? '');
                if ($v !== '') { $rowSite = $v; break; }
            }

            $hosp = trim($row['baby_hosp_code'] ?? '');

            // ── Date filter — Page 1 (prescreening) rows only ────────────
            //
            // The date filter uses baby_datetime_birth (actual birth date).
            // Fallback to baby_datetime (prescreening form time) covers babies
            // whose DCs filled the form the next day after a night delivery.
            //
            // IMPORTANT: The date filter is skipped entirely for non-repeating
            // rows (enrollment, SES, demographics). Those rows have no
            // baby_datetime_birth and no baby_datetime — applying the filter
            // would drop them via the `continue` on blank dates, causing
            // enr_study_arm (Intervention/Control) to always show 0.
            //
            // A row is a prescreening row if redcap_repeat_instrument is set.
            $isRepeatRow = ($row['redcap_repeat_instrument'] ?? '') !== '';

            if ($isRepeatRow && ($this->from || $this->to))
            {
                $raw = trim($row['baby_datetime_birth'] ?? '');
				if ($raw === '') $raw = trim($row['baby_datetime'] ?? '');
                if ($raw === '') continue;   // no date at all — exclude
                try   { $dt = new \DateTime($raw); }
                catch (\Exception) { continue; }
                if ($this->from && $dt < $this->from) continue;
                if ($this->to   && $dt > $this->to)   continue;
            }

            // ── Site filter ───────────────────────────────────────────────
            // $rowSite is resolved from whichever _hosp_code field is present,
            // so a single check covers screening, enrolment and all other forms.
            if (!empty($this->siteFilter) && !empty($rowSite))
            {
                if (!in_array($rowSite, $this->siteFilter, true)) continue;
            }

            // ── Track site from screening form ────────────────────────────
            if (!empty($hosp))           $recordSite[$id]    = $hosp;
            if (!empty($rowSite))        $recordEnrSite[$id] = $rowSite;
            $dc = trim($row['baby_dc_id'] ?? '');
            if (!empty($dc))             $recordDc[$id]      = $dc;

            // ─────────────────────────────────────────────────────────────
            // Page 1 — Eligibility metrics
            // ─────────────────────────────────────────────────────────────
            if (!empty($hosp))
            {
                foreach ($metrics as $metric => $condition)
                {
                    $eligibility[$metric][$hosp]    ??= 0;
                    $eligibility[$metric]['Total']  ??= 0;

                    if ($condition($row))
                    {
                        $eligibility[$metric][$hosp]++;
                        $eligibility[$metric]['Total']++;
                        // Also accumulate by data collector
                        if (!empty($dc)) {
                            $eligibilityByDc[$metric][$dc]      ??= 0;
                            $eligibilityByDc[$metric]['Total']  ??= 0;
                            $eligibilityByDc[$metric][$dc]++;
                            $eligibilityByDc[$metric]['Total']++;
                        }
                    }
                }

                // Track eligibility flag for demographics filter
                if (($row['baby_eligible_enroll'] ?? '') === 'Yes')
                {
                    $recordEligible[$id] = true;
                }
            }

            // ─────────────────────────────────────────────────────────────
            // Baseline — GA from baseline_arm_1 (captured here so we don't
            // depend solely on aggregateBaseline() being called externally)
            // ─────────────────────────────────────────────────────────────
            if (($row['redcap_event_name'] ?? '') === 'baseline_arm_1'
                && ($row['redcap_repeat_instrument'] ?? '') === '')
            {
                $wks  = $row['base_calc_ga_wks']  ?? null;
                $days = $row['base_calc_ga_days'] ?? null;
                if ($wks !== null && $wks !== '') {
                    $daysNum = ($days !== null && $days !== '') ? (float)$days : 0;
                    $gaByRecord[$id] = round((float)$wks + ($daysNum / 7), 2);
                }
            }

            // ─────────────────────────────────────────────────────────────
            // Page 2 — Enrollment detail
            // enr_hosp_code is the site on the enrolment form
            // ─────────────────────────────────────────────────────────────
            $enrSite    = trim($row['enr_hosp_code']  ?? '');
            $consent    = $row['enr_consent_granted'] ?? null;
            $arm        = $row['enr_study_arm']       ?? null;
            // FIX: was enr_baby_dob (DOB, not enrollment date) — now enr_datetime.
            $enrDateRaw = $row['enr_datetime']        ?? null;

            if (!empty($enrSite))
            {
                $recordEnrSite[$id] = $enrSite;
                $enrollment[$enrSite] ??= $this->emptyEnrollmentBucket();
                $enrollment['Total']  ??= $this->emptyEnrollmentBucket();

                // Page 2 filters on enr_datetime — the date the enrollment
                // form was completed. Intentionally different from the
                // baby_datetime_birth filter on Page 1, so counts may differ.
                $enrDateOk = true;
                if (!empty($enrDateRaw))
                {
                    try   { $enrDt = new \DateTime($enrDateRaw); }
                    catch (\Exception) { $enrDt = null; }

                    if ($enrDt && $this->to   && $enrDt > $this->to)   $enrDateOk = false;
                    if ($enrDt && $this->from && $enrDt < $this->from) $enrDateOk = false;
                }

                if ($enrDateOk)
                {
                    if ($consent === '0' || $consent === 'N')
                    {
                        $enrollment[$enrSite]['consent_refused']++;
                        $enrollment['Total']['consent_refused']++;

                        // FIX: use $enrSite not $rowSite — enr_* fields are on
                        // the non-repeating enrolment row, not the prescreening row.
                        // $rowSite may be empty when this row is processed.
                        $eligibility['enr_consent_refused'][$enrSite] = ($eligibility['enr_consent_refused'][$enrSite] ?? 0) + 1;
                        $eligibility['enr_consent_refused']['Total']  = ($eligibility['enr_consent_refused']['Total']  ?? 0) + 1;
                    }
                    elseif ($consent === '1' || $consent === 'Y')
                    {
                        $recordEnrolled[$id] = true;
                        // Track consented separately from arm-assigned so we can
                        // detect incomplete forms (consented but no arm yet).
                        $enrollment[$enrSite]['consented']++;
                        $enrollment['Total']['consented']++;
                        $enrollment[$enrSite]['enrolled']++;
                        $enrollment['Total']['enrolled']++;

                        // enr_enrolled (Study Arm Distribution total) is derived
                        // from enr_study_arm — only babies actually assigned to an
                        // arm count toward the total. Intervention + Control = total.
                        if ($arm === 'Intervention')
                        {
                            $enrollment[$enrSite]['intervention']++;
                            $enrollment['Total']['intervention']++;

                            $eligibility['enr_intervention'][$enrSite] = ($eligibility['enr_intervention'][$enrSite] ?? 0) + 1;
                            $eligibility['enr_intervention']['Total']  = ($eligibility['enr_intervention']['Total']  ?? 0) + 1;

                            // enr_enrolled total = Intervention + Control
                            $eligibility['enr_enrolled'][$enrSite] = ($eligibility['enr_enrolled'][$enrSite] ?? 0) + 1;
                            $eligibility['enr_enrolled']['Total']  = ($eligibility['enr_enrolled']['Total']  ?? 0) + 1;
                        }
                        elseif ($arm === 'Control')
                        {
                            $enrollment[$enrSite]['control']++;
                            $enrollment['Total']['control']++;

                            $eligibility['enr_control'][$enrSite] = ($eligibility['enr_control'][$enrSite] ?? 0) + 1;
                            $eligibility['enr_control']['Total']  = ($eligibility['enr_control']['Total']  ?? 0) + 1;

                            // enr_enrolled total = Intervention + Control
                            $eligibility['enr_enrolled'][$enrSite] = ($eligibility['enr_enrolled'][$enrSite] ?? 0) + 1;
                            $eligibility['enr_enrolled']['Total']  = ($eligibility['enr_enrolled']['Total']  ?? 0) + 1;
                        }
                    }
                }
            }

            // ─────────────────────────────────────────────────────────────
            // Page 3 — Demographics
            // Only collect for records where baby_eligible_enroll = Yes.
            // We accumulate fields per record_id; GA added later via
            // aggregateBaseline(). Fields from both screening and SES forms
            // arrive in the same stream so we just fill what's present.
            // ─────────────────────────────────────────────────────────────
            $demoFields = [
                'enr_study_arm',              // authoritative arm — used first in Demographics
                'baby_nicu_admit_time_hrs_mins',
                'baby_weight_nicu',
                'ses_religion',
                'ses_caste',
                'ses_age_mthr',
                'ses_age_fthr',
                'ses_mthr_edu_qual',
                'ses_head_edu_qual',
                'ses_fmly_incm',
                'ses_study_arm',
            ];

            foreach ($demoFields as $field)
            {
                $val = $row[$field] ?? null;
                if ($val !== null && $val !== '')
                {
                    $demographics[$id][$field] = $val;
                }
            }
        }

        // ── Post-pass: derive incomplete enrollment count ────────────────
        // incomplete = consented (enr_consent_granted=Y) minus enrolled (arm assigned).
        // Any discrepancy means the enrollment form was saved without selecting a study arm.
        foreach ($enrollment as $site => &$bucket) 
        {
            $bucket['incomplete'] = max(0, $bucket['consented'] - $bucket['enrolled']);
        }
        unset($bucket);

        // ── Post-pass: derived eligibility totals ─────────────────────────
        EligibilityWorkflow::applyDerivedTotals($eligibility);

        // ── Post-pass: merge GA into demographics ────────────────────────
        // GA captured from baseline_arm_1 rows in the main stream.
        // aggregateBaseline() may also set this — whichever runs last wins,
        // but both use the same formula so the value will be identical.
        foreach ($gaByRecord as $id => $ga) 
        {
            if (isset($demographics[$id]))
                $demographics[$id]['base_calc_ga_wks'] = $ga;
        }

        // ── Post-pass: filter demographics to ENROLLED records only ──────
        // Page 3 shows only babies who gave consent (enr_consent_granted = Y/1),
        // matching the n in the Enrollment table (Page 2), not the larger
        // screening eligibility pool (baby_eligible_enroll = Yes).
        $eligibleDemographics = [];
        foreach ($demographics as $id => $fields)
        {
            if (!empty($recordEnrolled[$id]))
            {
                $site = $recordSite[$id] ?? ($recordEnrSite[$id] ?? 'Unknown');
                $eligibleDemographics[$id] = array_merge(
                    ['site' => $site],
                    $fields
                );
            }
        }

        // Apply same derived totals to DC breakdown
        EligibilityWorkflow::applyDerivedTotals($eligibilityByDc);

        return [
            'summary'        => $eligibility,
            'summary_by_dc'  => $eligibilityByDc,
            'metricMeta'     => self::metricMeta(),
            'workflow'       => EligibilityWorkflow::workflowCounts($eligibility),
            'enrollment'     => $enrollment,
            'demographics'   => $eligibleDemographics,
        ];
    }

    // =========================================================================
    // aggregateBaseline — called by ReportFacade after the main aggregate()
    // Merges GA (base_calc_ga_wks) from baseline_arm_1 into demographics.
    // Modifies $result in place.
    // =========================================================================

    public function aggregateBaseline(iterable $stream, array &$result): void
    {
        $gaByRecord  = [];  // record_id => 'wks+days' string e.g. '33+4'
        $armByRecord = [];

        foreach ($stream as $row)
        {
            $id   = $row[$this->primaryKey]    ?? null;
            $wks  = $row['base_calc_ga_wks']   ?? null;
            $days = $row['base_calc_ga_days']  ?? null;
            $arm  = $row['base_study_arm']     ?? null;

            if ($id)
            {
                // Combine wks+days into a numeric weeks value for statistics
                // e.g. 33 wks + 4 days = 33.571
                if ($wks !== null && $wks !== '') {
                    $wksNum  = (float)$wks;
                    $daysNum = ($days !== null && $days !== '') ? (float)$days : 0;
                    $gaByRecord[$id] = round($wksNum + ($daysNum / 7), 2);
                }
                if ($arm !== null && $arm !== '') $armByRecord[$id] = $arm;
            }
        }

        foreach ($result['demographics'] as $id => &$fields)
        {
            // Stored as decimal weeks (e.g. 33.57) — exporter formats as needed
            $fields['base_calc_ga_wks'] = $gaByRecord[$id]  ?? null;
            $fields['base_study_arm']   = $armByRecord[$id] ?? null;
        }
        unset($fields);
    }

    // =========================================================================
    // Metric metadata
    // =========================================================================

    /**
     * Delegated to EligibilityMetricDefinitions.
     * Kept here for backwards compatibility — EligibilityHtmlExporter
     * calls EligibilityAggregator::metricMeta() directly.
     */
    public static function metricMeta(): array
    {
        return EligibilityMetricDefinitions::metricMeta();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function emptyEnrollmentBucket(): array
    {
        return [
            'consent_refused' => 0,
            'consented'       => 0,   // enr_consent_granted = Y — attempted enrollments
            'intervention'    => 0,
            'control'         => 0,
            'enrolled'        => 0,   // enr_study_arm assigned (Intervention or Control)
            // 'incomplete' is derived in post-pass: consented - enrolled
        ];
    }
}

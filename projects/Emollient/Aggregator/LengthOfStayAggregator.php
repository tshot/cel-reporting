<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * LengthOfStayAggregator — v2, September 2026
 *
 * LOS is computed for babies discharged as TYP_FP, TYP_OTH or TYP_DOPR, from the ACTUAL
 * discharge datetime:
 *
 *     LOS = floor((discharge − admission) / 86400)      completed days
 *
 *   admission : baby_datetime_admission, on the screening row where
 *               baby_eligible_enroll = 'Yes' (a calculated field, so the
 *               literal is 'Yes', unlike the Y/N flags elsewhere)
 *   discharge : dis_datetime               when dis_in_hosp = 'N'  (regular form)
 *               dis_post_28_datetime       when dis_in_hosp = 'Y'  (post-28 form)
 *
 * AUDIT. Every enrolled baby is assigned exactly one outcome — the FIRST that
 * matches, in this order — so that per site and per arm:
 *
 *     enrolled  =  sum of all outcome counts                (checked, see 'reconciles')
 *
 *   1  withdrawn            sw_datetime present
 *   2  protocol_deviation   pd_datetime present
 *   3  still_in_study       regular discharge form not complete, < 29 days since DOB
 *   4  regular_overdue      regular discharge form not complete, ≥ 29 days since DOB
 *   5  awaiting_post28      dis_in_hosp = 'Y', post-28 form not complete
 *   6  death | lama | abscond | referral            excluded discharge types
 *   7  data_issue           see DATA_ISSUES — reason carried per baby
 *   8  los_computed
 *
 * Withdrawal and deviation come first because a withdrawn baby has no discharge
 * record; checked later, it would be reported as 'still in study' forever.
 * PD/SW are detected by their datetimes, not their _complete flags: a started
 * form means a case exists behind it.
 *
 * CROSS-REPORT CHECK. Outcome 4 uses exactly the rule behind the Pending column
 * of DischargeFormCompletion (OneTimeFormCompletionAggregator with
 * min_age_days = 29): same enrolment test, same age arithmetic, same enrolment-
 * date filter. For any site, regular_overdue here must equal Pending there.
 * If they diverge, one report has a bug.
 *
 * DATE FILTER. date_from / date_to filter on ENROLMENT date (enr_datetime), as
 * every other Emollient report does. The v1 aggregator accepted neither and
 * silently reported all time whatever period was selected.
 */
class LengthOfStayAggregator extends AbstractAggregator
{
    /** Discharge types that produce an LOS. */
    private const INCLUDED_TYPES = ['TYP_FP', 'TYP_OTH', 'TYP_DOPR'];

    /** 'Other' is included but reported separately — it can mean anything. */
    private const OTHER_TYPE = 'TYP_OTH';

    /** DOPR is included (September 2026) and, like Other, reported separately. */
    private const DOPR_TYPE = 'TYP_DOPR';

    /** Discharge types that exclude a baby, and the outcome each maps to. */
    private const EXCLUDED_TYPES = [
        'TYP_DEA'  => 'death',
        'TYP_LAMA' => 'lama',
        'TYP_ABS'  => 'abscond',
        'TYP_REF'  => 'referral',
    ];

    /**
     * Age (days since DOB) at which the regular discharge form falls due.
     * MUST equal min_age_days on DischargeFormCompletion, or the cross-report
     * check stops holding.
     */
    private const REGULAR_FORM_DUE_AGE = 29;

    public const OUTCOMES = [
        'withdrawn'          => 'Withdrawn',
        'protocol_deviation' => 'Protocol deviation',
        'still_in_study'     => 'Still in study',
        'regular_overdue'    => 'Regular discharge form overdue',
        'awaiting_post28'    => 'Awaiting post-28 discharge',
        'death'              => 'Death',
        'lama'               => 'LAMA',
        'abscond'            => 'Abscond',
        'referral'           => 'Referral',
        'data_issue'         => 'Data issue',
        'los_computed'       => 'LOS computed',
    ];

    public const DATA_ISSUES = [
        'dob_missing'          => 'Date of birth missing — cannot tell whether the regular discharge form is due',
        'day28_status_missing' => 'Regular discharge form complete but Day-28 status (dis_in_hosp) not recorded',
        'day28_status_invalid' => 'Day-28 status (dis_in_hosp) has an unrecognised value',
        'both_forms_filled'    => 'Post-28 discharge form filled although dis_in_hosp = N',
        'type_unrecognised'    => 'Discharge type missing or not in the included/excluded lists',
        'multiple_enrolled'    => 'More than one baby marked enrolled on this record',
        'admission_missing'    => 'Admission datetime missing',
        'discharge_missing'    => 'Discharge datetime missing',
        'discharge_before_adm' => 'Discharge datetime earlier than admission',
    ];

    private string     $primaryKey;
    private array      $siteFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    ) {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_values(array_filter(array_map('trim', $siteFilter)));
        $this->from = $dateFrom ? (new \DateTime($dateFrom))->setTime(0, 0, 0)   : null;
        $this->to   = $dateTo   ? (new \DateTime($dateTo))->setTime(23, 59, 59)  : null;
    }

    // =========================================================================
    // Collect
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        $rec = [];

        foreach ($records as $row)
        {
            $id = trim((string)($row[$this->primaryKey] ?? ''));
            if ($id === '') continue;

            if (!isset($rec[$id])) $rec[$id] = ['yes_rows' => 0];
            $event = $row['redcap_event_name'] ?? '';

            if ($event === 'day0_arm_1')
            {
                $this->keep($rec[$id], $row, [
                    'enr_consent_granted', 'enr_hosp_code', 'enr_study_arm',
                    'enr_baby_dob', 'enr_datetime',
                ]);
                // Twins/triplets share a record; only the screening row marked
                // enrolled supplies the admission datetime.
                if (trim((string)($row['baby_eligible_enroll'] ?? '')) === 'Yes')
                {
                    $rec[$id]['yes_rows']++;
                    $adm = trim((string)($row['baby_datetime_admission'] ?? ''));
                    if ($adm !== '' && !isset($rec[$id]['admission'])) {
                        $rec[$id]['admission'] = $adm;
                    }
                }
            }
            elseif ($event === 'other_forms_arm_1')
            {
                $this->keep($rec[$id], $row, ['pd_datetime', 'sw_datetime']);
            }
            elseif ($event === 'discharge_arm_1')
            {
                $this->keep($rec[$id], $row, [
                    'discharge_form_complete', 'dis_in_hosp', 'dis_datetime', 'dis_discharge_type',
                    'discharge_after_28_days_of_stay_complete',
                    'dis_post_28_datetime', 'dis_post_28_discharge_type',
                ]);
            }
        }

        return $this->resolve($rec);
    }

    /** First non-empty value wins, across however many rows an event returns. */
    private function keep(array &$r, array $row, array $fields): void
    {
        foreach ($fields as $f)
        {
            $v = trim((string)($row[$f] ?? ''));
            if ($v !== '' && !isset($r[$f])) $r[$f] = $v;
        }
    }

    // =========================================================================
    // Classify and summarise
    // =========================================================================

    private function resolve(array $rec): array
    {
        $today    = new \DateTime('today');
        $patients = [];

        foreach ($rec as $id => $r)
        {
            // Enrolment test identical to OneTimeFormCompletionAggregator, so
            // regular_overdue reconciles with DischargeFormCompletion's Pending.
            if (!in_array($r['enr_consent_granted'] ?? '', ['Y', '1'], true)) continue;

            $site = $r['enr_hosp_code'] ?? 'Unknown';
            if ($this->siteFilter && !in_array($site, $this->siteFilter, true)) continue;

            $enrDate = $this->toDate($r['enr_datetime'] ?? null);
            if ($this->from || $this->to)
            {
                if ($enrDate === null) continue;   // filter set but no date — excluded, as elsewhere
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $dob     = $this->toDate($r['enr_baby_dob'] ?? null);
            $ageDays = $dob ? (int)$dob->diff($today)->format('%r%a') : null;
            $adm     = $this->toDateTime($r['admission'] ?? null);

            $p = [
                'record_id'          => (string)$id,
                'site'               => $site,
                'study_arm'          => $r['enr_study_arm'] ?? '',
                'dob'                => $dob?->format('Y-m-d'),
                'enrolled_on'        => $enrDate?->format('Y-m-d'),
                'age_days'           => $ageDays,
                'admission_date'     => $adm?->format('Y-m-d H:i:s'),
                'day28_status'       => $r['dis_in_hosp'] ?? '',
                'discharge_source'   => '',
                'discharge_date'     => null,
                'discharge_type'     => '',
                'los_days'           => null,
                'outcome'            => '',
                'outcome_label'      => '',
                'detail'             => '',
            ];

            [$outcome, $issue] = $this->classify($r, $p, $ageDays, $adm);

            $p['outcome']       = $outcome;
            $p['outcome_label'] = self::OUTCOMES[$outcome];
            if ($issue !== '') {
                $p['detail'] = self::DATA_ISSUES[$issue];
                if ($issue === 'admission_missing' && $r['yes_rows'] === 0) {
                    $p['detail'] .= ' (no screening row marked enrolled)';
                }
            } elseif ($outcome === 'los_computed' && $p['discharge_type'] === self::OTHER_TYPE) {
                $p['detail'] = 'Discharge type Other (TYP_OTH)';
            } elseif ($outcome === 'los_computed' && $p['discharge_type'] === self::DOPR_TYPE) {
                $p['detail'] = 'Discharge type DOPR (TYP_DOPR)';
            }
            $p['_issue'] = $issue;

            $patients[$p['record_id']] = $p;
        }

        uasort($patients, fn($a, $b) =>
            [$a['site'], $a['record_id']] <=> [$b['site'], $b['record_id']]);

        return $this->summarise($patients);
    }

    /**
     * Returns [outcome, data_issue_key]. Fills discharge fields on $p as they
     * become known, so excluded babies still show their dates in the audit.
     */
    private function classify(array $r, array &$p, ?int $ageDays, ?\DateTime $adm): array
    {
        if (($r['sw_datetime'] ?? '') !== '') return ['withdrawn', ''];
        if (($r['pd_datetime'] ?? '') !== '') return ['protocol_deviation', ''];

        // Regular discharge form — usable only when marked complete.
        if (($r['discharge_form_complete'] ?? '') !== '2')
        {
            if ($ageDays === null) return ['data_issue', 'dob_missing'];
            return $ageDays >= self::REGULAR_FORM_DUE_AGE
                ? ['regular_overdue', '']
                : ['still_in_study', ''];
        }

        $inHosp       = $r['dis_in_hosp'] ?? '';
        $post28Done   = ($r['discharge_after_28_days_of_stay_complete'] ?? '') === '2';
        $post28Filled = $post28Done || ($r['dis_post_28_datetime'] ?? '') !== '';

        if ($inHosp === '') return ['data_issue', 'day28_status_missing'];

        if ($inHosp === 'N')
        {
            if ($post28Filled) return ['data_issue', 'both_forms_filled'];
            $source = 'Regular';
            $rawDt  = $r['dis_datetime'] ?? '';
            $type   = $r['dis_discharge_type'] ?? '';
        }
        elseif ($inHosp === 'Y')
        {
            if (!$post28Done) return ['awaiting_post28', ''];
            $source = 'After 28 days';
            $rawDt  = $r['dis_post_28_datetime'] ?? '';
            $type   = $r['dis_post_28_discharge_type'] ?? '';
        }
        else
        {
            return ['data_issue', 'day28_status_invalid'];
        }

        $dis = $this->toDateTime($rawDt);
        $p['discharge_source'] = $source;
        $p['discharge_type']   = $type;
        $p['discharge_date']   = $dis?->format('Y-m-d H:i:s');

        if (isset(self::EXCLUDED_TYPES[$type]))             return [self::EXCLUDED_TYPES[$type], ''];
        if (!in_array($type, self::INCLUDED_TYPES, true))   return ['data_issue', 'type_unrecognised'];
        if ($r['yes_rows'] > 1)                             return ['data_issue', 'multiple_enrolled'];
        if ($adm === null)                                  return ['data_issue', 'admission_missing'];
        if ($dis === null)                                  return ['data_issue', 'discharge_missing'];
        if ($dis < $adm)                                    return ['data_issue', 'discharge_before_adm'];

        $p['los_days'] = (int)floor(($dis->getTimestamp() - $adm->getTimestamp()) / 86400);
        return ['los_computed', ''];
    }

    private function summarise(array $patients): array
    {
        $auditSite = [];  $auditArm = [];
        $losSite   = [];  $losArm   = [];
        $otherSite = [];  $otherArm = [];
        $doprSite  = [];  $doprArm  = [];
        $issues    = [];

        foreach ($patients as $p)
        {
            $site    = $p['site'];
            $arm     = $p['study_arm'];
            $outcome = $p['outcome'];

            foreach ([$site, 'Total'] as $key) $this->tally($auditSite, $key, $outcome);
            foreach ([$arm,  'Total'] as $key) $this->tally($auditArm,  $key, $outcome);

            if ($outcome === 'los_computed')
            {
                $isOther = ($p['discharge_type'] === self::OTHER_TYPE);
                $isDopr  = ($p['discharge_type'] === self::DOPR_TYPE);
                foreach ([$site, 'Total'] as $key) {
                    $losSite[$key][] = $p['los_days'];
                    if ($isOther) $otherSite[$key] = ($otherSite[$key] ?? 0) + 1;
                    if ($isDopr)  $doprSite[$key]  = ($doprSite[$key]  ?? 0) + 1;
                }
                foreach ([$arm, 'Total'] as $key) {
                    $losArm[$key][] = $p['los_days'];
                    if ($isOther) $otherArm[$key] = ($otherArm[$key] ?? 0) + 1;
                    if ($isDopr)  $doprArm[$key]  = ($doprArm[$key]  ?? 0) + 1;
                }
            }

            if ($p['_issue'] !== '')
            {
                $i = $p['_issue'];
                $issues[$i][$site]   = ($issues[$i][$site]   ?? 0) + 1;
                $issues[$i]['Total'] = ($issues[$i]['Total'] ?? 0) + 1;
            }
        }

        $auditSite = $this->finaliseAudit($auditSite);
        $auditArm  = $this->finaliseAudit($auditArm);

        $bySite = []; foreach ($auditSite as $k => $_) $bySite[$k] = $this->stats($losSite[$k] ?? [], $otherSite[$k] ?? 0, $doprSite[$k] ?? 0);
        $byArm  = []; foreach ($auditArm  as $k => $_) $byArm[$k]  = $this->stats($losArm[$k]  ?? [], $otherArm[$k]  ?? 0, $doprArm[$k]  ?? 0);

        $dist = [];
        foreach ($auditSite as $k => $_)
        {
            $b = ['lt7' => 0, 'w7_14' => 0, 'w15_27' => 0, 'w28plus' => 0];
            foreach ($losSite[$k] ?? [] as $v) {
                if     ($v < 7)   $b['lt7']++;
                elseif ($v <= 14) $b['w7_14']++;
                elseif ($v <= 27) $b['w15_27']++;
                else              $b['w28plus']++;
            }
            // Pre-v2 exporter charts read the key 'day28' for the fourth band.
            // With real discharge dates that band is now "28 days or more".
            $b['day28']   = $b['w28plus'];
            $b['unknown'] = 0;
            $dist[$k] = $b;
        }

        foreach ($patients as &$p) unset($p['_issue']);
        unset($p);

        return [
            'patients'          => $patients,
            'audit_by_site'     => $auditSite,
            'audit_by_arm'      => $auditArm,
            'data_issues'       => $issues,
            'outcome_labels'    => self::OUTCOMES,
            'data_issue_labels' => self::DATA_ISSUES,
            'by_site'           => $bySite,
            'by_arm'            => $byArm,
            'distribution'      => $dist,
            'period'            => [
                'date_from' => $this->from?->format('Y-m-d'),
                'date_to'   => $this->to?->format('Y-m-d'),
                'basis'     => 'enrolment date',
            ],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function tally(array &$tbl, string $key, string $outcome): void
    {
        if (!isset($tbl[$key])) $tbl[$key] = $this->emptyAudit();
        $tbl[$key]['enrolled']++;
        $tbl[$key][$outcome]++;
    }

    /** Adds the reconciliation check to each row and moves Total last. */
    private function finaliseAudit(array $tbl): array
    {
        foreach ($tbl as $key => $row) {
            $sum = 0;
            foreach (self::OUTCOMES as $k => $_) $sum += $row[$k];
            $tbl[$key]['reconciles'] = ($sum === $row['enrolled']);
        }
        $this->totalLast($tbl);
        return $tbl;
    }

    private function emptyAudit(): array
    {
        $a = ['enrolled' => 0];
        foreach (self::OUTCOMES as $k => $_) $a[$k] = 0;
        return $a;
    }

    private function totalLast(array &$tbl): void
    {
        if (isset($tbl['Total'])) { $t = $tbl['Total']; unset($tbl['Total']); $tbl['Total'] = $t; }
    }

    /**
     * LOS statistics over babies with a computed LOS. The *_incl / *_excl and
     * day28 keys keep the pre-v2 exporter rendering: with actual discharge
     * dates there is no fixed Day-28 value, so both views are identical.
     */
    private function stats(array $vals, int $other, int $dopr = 0): array
    {
        sort($vals);
        $n      = count($vals);
        $mean   = $n ? round(array_sum($vals) / $n, 1) : null;
        $median = null;
        if ($n) {
            $mid    = intdiv($n, 2);
            $median = ($n % 2) ? $vals[$mid] : ($vals[$mid - 1] + $vals[$mid]) / 2;
        }
        $std = null;
        if ($n > 1) {
            $m  = array_sum($vals) / $n;
            $ss = 0.0;
            foreach ($vals as $v) $ss += ($v - $m) ** 2;
            $std = round(sqrt($ss / ($n - 1)), 1);          // sample SD, n − 1
        }
        $min = $n ? min($vals) : null;
        $max = $n ? max($vals) : null;

        return [
            'count' => $n, 'count_other' => $other, 'count_dopr' => $dopr,
            'mean'  => $mean, 'median' => $median, 'std' => $std, 'min' => $min, 'max' => $max,
            // --- compatibility with the pre-v2 exporter ---
            'count_day28' => 0, 'pct_day28' => 0.0, 'count_missing' => 0,
            'mean_incl' => $mean, 'median_incl' => $median, 'std_incl' => $std,
            'min_incl'  => $min,  'max_incl'    => $max,
            'count_excl' => $n,   'mean_excl'   => $mean, 'median_excl' => $median,
            'std_excl'  => $std,  'min_excl'    => $min,  'max_excl'    => $max,
        ];
    }

    private function toDate(?string $raw): ?\DateTime
    {
        if ($raw === null || trim($raw) === '') return null;
        try { return (new \DateTime(trim($raw)))->setTime(0, 0, 0); }
        catch (\Exception) { return null; }
    }

    private function toDateTime(?string $raw): ?\DateTime
    {
        if ($raw === null || trim($raw) === '') return null;
        try { return new \DateTime(trim($raw)); }
        catch (\Exception) { return null; }
    }
}

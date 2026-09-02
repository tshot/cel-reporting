<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;

/**
 * Day29FollowUpAggregator
 *
 * Completion tracking for the Day 29 Follow-up form (day29_arm_1).
 *
 * A baby's Day 29 form is DUE when ALL of the following are true:
 *   1. enrolment_form_complete = '2'       — baby was enrolled
 *   2. today - enr_baby_dob >= 29 days     — baby is at least 29 days old
 *   3. protocol_deviation_form_complete is blank/missing   — no PD
 *   4. study_withdrawal_form_complete is blank/missing     — no withdrawal
 *   5. dis_discharge_type != 'TYP_DEA'     — baby did not die
 *
 * Status per baby:
 *   'completed'  — day_28_follow_up_form_complete = '2' on day29_arm_1
 *   'due'        — all 5 conditions met but form not complete
 *   'not_due'    — one or more conditions not met (too young, died, withdrawn, deviated)
 *
 * Output:
 * [
 *   'participants' => [
 *     record_id => [
 *       'record_id'    => string,
 *       'site'         => string,
 *       'arm'          => string,
 *       'dob'          => string,       // enr_baby_dob
 *       'age_days'     => int|null,     // days since DOB
 *       'status'       => 'completed'|'due'|'not_due',
 *       'not_due_reason' => string,     // why not due (empty if due/completed)
 *       'complete_field' => string,     // raw value of day_28_follow_up_form_complete
 *     ]
 *   ],
 *   'by_site' => [
 *     site => ['completed'=>int, 'due'=>int, 'not_due'=>int, 'total'=>int, 'pct'=>float|null]
 *     'Total' => [...]
 *   ],
 *   'period_label' => string,
 *   'site_labels'  => array,
 * ]
 */
class Day29FollowUpAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private array      $siteFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;

    // ── Per-record accumulators ───────────────────────────────────────────────
    private array $site          = [];  // record_id => enr_hosp_code
    private array $arm           = [];  // record_id => enr_study_arm
    private array $dob           = [];  // record_id => string (enr_baby_dob — display only)
    private array $enrComplete   = [];  // record_id => bool (enrolment_form_complete=2)
    private array $pdComplete    = [];  // record_id => bool (protocol_deviation exists)
    private array $swComplete    = [];  // record_id => bool (study_withdrawal exists)
    private array $disType       = [];  // record_id => string (dis_discharge_type)
    private array $disInHosp     = [];  // record_id => string (dis_in_hosp)
    private array $disAge        = [];  // record_id => string (dis_age_discharge)
    private array $fu28Complete  = [];  // record_id => string (day_28_follow_up_form_complete raw)
    private array $fu28Type      = [];  // record_id => string (fu28_followup_type: TELE|...)
    private array $fu28Status    = [];  // record_id => string (fu28_day28_fup: DONE|NOT_DONE|...)
    private array $enrDate       = [];  // record_id => DateTime (enr_datetime — for date filter)

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    ) {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_map('trim', $siteFilter);
        $this->from = $dateFrom ? (new \DateTime($dateFrom))->setTime(0,  0,  0) : null;
        $this->to   = $dateTo   ? (new \DateTime($dateTo  ))->setTime(23, 59, 59) : null;
    }

    // =========================================================================
    // Streaming pass
    // =========================================================================

    public function aggregate(iterable $records): array
    {
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;

            $event      = $row['redcap_event_name']        ?? '';
            $repeatForm = $row['redcap_repeat_instrument'] ?? '';

            // ── day0_arm_1 non-repeating — enrollment ──────────────────────
            if ($event === 'day0_arm_1' && $repeatForm === '')
            {
                $hosp = trim($row['enr_hosp_code'] ?? '');
                if ($hosp !== '') $this->site[$id] = $hosp;

                $arm = trim($row['enr_study_arm'] ?? '');
                if ($arm !== '') $this->arm[$id] = $arm;

                if (!empty($row['enr_baby_dob']))
                    $this->dob[$id] = trim($row['enr_baby_dob']);

                if (!empty($row['enr_datetime']))
                    $this->enrDate[$id] = $this->parseDate($row['enr_datetime']);

                // Condition 1: enrolment_form_complete = '2'
                if (($row['enrolment_form_complete'] ?? '') === '2')
                    $this->enrComplete[$id] = true;
            }

            // ── discharge_arm_1 — discharge type ───────────────────────────
            if ($event === 'discharge_arm_1')
            {
                $dtype = trim($row['dis_discharge_type'] ?? '');
                if ($dtype !== '') $this->disType[$id] = $dtype;

                $inHosp = trim($row['dis_in_hosp'] ?? '');
                if ($inHosp !== '') $this->disInHosp[$id] = $inHosp;

                $disAge = trim($row['dis_age_discharge'] ?? '');
                if ($disAge !== '') $this->disAge[$id] = $disAge;
            }

            // ── other_forms_arm_1 — PD and withdrawal ──────────────────────
            if ($event === 'other_forms_arm_1')
            {
                // Condition 3: protocol_deviation_form_complete is blank/missing
                $pd = trim($row['protocol_deviation_form_complete'] ?? '');
                if ($pd !== '') $this->pdComplete[$id] = true;

                // Condition 4: study_withdrawal_form_complete is blank/missing
                $sw = trim($row['study_withdrawal_form_complete'] ?? '');
                if ($sw !== '') $this->swComplete[$id] = true;
            }

            // ── day29_arm_1 — the form being tracked ───────────────────────
            if ($event === 'day29_arm_1')
            {
                $val = $row['day_28_follow_up_form_complete'] ?? '';
                $this->fu28Complete[$id] = $val;

                $ftype = trim($row['fu28_followup_type'] ?? '');
                if ($ftype !== '') $this->fu28Type[$id] = $ftype;

                $fstatus = trim($row['fu28_day28_fup'] ?? '');
                if ($fstatus !== '') $this->fu28Status[$id] = $fstatus;
            }
        }

        return $this->resolve();
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    private function resolve(): array
    {
        $participants = [];
        $bySite       = [];

        foreach ($this->site as $id => $site)
        {
            // ── Site filter ───────────────────────────────────────────────
            if (!empty($this->siteFilter)
                && !in_array($site, $this->siteFilter, true)) continue;

            // ── Date filter on enr_datetime ───────────────────────────────
            $enrDate = $this->enrDate[$id] ?? null;
            if ($this->from || $this->to) {
                if ($enrDate === null) continue;
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $dob       = $this->dob[$id]       ?? null;   // enr_baby_dob — for age calc + display
            $disAge    = $this->disAge[$id]    ?? null;   // dis_age_discharge — display only
            $disInHosp = $this->disInHosp[$id] ?? '';     // 'Y'|'N'|''

            // ── Age calculation: today - enr_baby_dob ────────────────────
            $ageDays = null;
            if ($dob !== null) {
                $dobDt   = $this->parseDate($dob);
                $ageDays = $dobDt ? (int)(new \DateTime('today'))->diff($dobDt)->days : null;
            }

            // ── Evaluate due conditions ───────────────────────────────────
            // Due when ALL of:
            //   1. today - enr_baby_dob >= 29 days
            //   2. dis_in_hosp = 'N'  (actually discharged)
            //   3. No protocol deviation
            //   4. No study withdrawal
            //   5. dis_discharge_type != 'TYP_DEA' (not died)
            $reasons = [];

            // 1. Age >= 29 days (today - enr_baby_dob)
            if ($ageDays === null)
                $reasons[] = 'DOB unknown';
            elseif ($ageDays < 29)
                $reasons[] = "age {$ageDays}d < 29";

            // 2. Baby discharged (dis_in_hosp = 'N')
            if ($disInHosp !== 'N')
                $reasons[] = $disInHosp === 'Y' ? 'still in hospital' : 'not yet discharged';

            // 3. No protocol deviation
            if (!empty($this->pdComplete[$id]))
                $reasons[] = 'protocol deviation';

            // 4. No study withdrawal
            if (!empty($this->swComplete[$id]))
                $reasons[] = 'study withdrawal';

            // 5. Baby did not die
            if (($this->disType[$id] ?? '') === 'TYP_DEA')
                $reasons[] = 'died';

            $isDue  = empty($reasons);
            $rawVal = $this->fu28Complete[$id] ?? '';
            $isDone = ($rawVal === '2');

            if ($isDone) {
                $status = 'completed';
            } elseif ($isDue) {
                $status = 'due';
            } else {
                $status = 'not_due';
            }

            $participants[$id] = [
                'record_id'      => $id,
                'site'           => $site,
                'arm'            => $this->arm[$id] ?? '',
                'dob'            => $dob ?? '',
                'age_days'       => $ageDays,
                'age_discharge'  => $disAge,
                'status'         => $status,
                'not_due_reason' => implode('; ', $reasons),
                'complete_field'   => $rawVal,
                'followup_type'    => $this->fu28Type[$id]   ?? '',
                'followup_status'  => $this->fu28Status[$id] ?? '',
                // Telephonic call status
                'is_tele'          => ($this->fu28Type[$id] ?? '') === 'TELE',
                'tele_done'        => ($this->fu28Type[$id] ?? '') === 'TELE'
                                     && ($this->fu28Status[$id] ?? '') === 'DONE',
            ];

            // ── Site summary ──────────────────────────────────────────────
            foreach ([$site, 'Total'] as $grp) {
                if (!isset($bySite[$grp]))
                    $bySite[$grp] = [
                        'completed'    => 0, 'due' => 0, 'not_due' => 0, 'total' => 0,
                        'tele_total'   => 0,  // telephonic calls attempted
                        'tele_done'    => 0,  // telephonic calls completed
                        'tele_not_done'=> 0,  // telephonic calls not done
                    ];
                $bySite[$grp]['total']++;
                $bySite[$grp][$status]++;

                // Telephonic call accumulation
                $isTele     = ($this->fu28Type[$id]   ?? '') === 'TELE';
                $teleStatus = ($this->fu28Status[$id] ?? '');
                if ($isTele) {
                    $bySite[$grp]['tele_total']++;
                    if ($teleStatus === 'DONE')     $bySite[$grp]['tele_done']++;
                    elseif ($teleStatus !== '')      $bySite[$grp]['tele_not_done']++;
                }
            }
        }

        // Percentages: completed / (completed + due) × 100
        foreach ($bySite as &$s) {
            $denominator = $s['completed'] + $s['due'];
            $s['pct'] = $denominator > 0
                ? round($s['completed'] / $denominator * 100, 1)
                : null;
            $s['tele_pct'] = $s['tele_total'] > 0
                ? round($s['tele_done'] / $s['tele_total'] * 100, 1)
                : null;
        }
        unset($s);

        // Move Total to end
        if (isset($bySite['Total'])) {
            $t = $bySite['Total']; unset($bySite['Total']); $bySite['Total'] = $t;
        }

        return [
            'participants' => $participants,
            'by_site'      => $bySite,
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try {
            $dt = new \DateTime($raw);
            $dt->setTime(0, 0, 0);
            return $dt;
        } catch (\Exception) { return null; }
    }
}

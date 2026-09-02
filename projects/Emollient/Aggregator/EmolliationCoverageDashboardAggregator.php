<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Support\EmolliationSessionCalculator;

/**
 * EmolliationCoverageDashboardAggregator
 *
 * Dashboard variant of EmolliationCoverageAggregator. It is the SAME full
 * coverage report (Due / Attempted / Given / Not Given across Days 0-28) with
 * exactly two differences:
 *
 *   1. DAY-0 IS A FLAT 1.
 *      Day 0 contributes at most 1 to Due, Attempted and Given — never the
 *      1/2/3 weighting from fi_emol_perid. "Coverage" on Day 0 means a session
 *      was done: the Day-0 module is complete, OR a Day-0 daily form has
 *      int_emoliate_baby = 'Y'.
 *        - Due(day0)        = 1 always (passed to the calculator as day0Override=1)
 *        - Attempted(day0)  = 1 if module complete OR any Day-0 daily Y/N
 *        - Given(day0)      = 1 if module complete OR any Day-0 daily Y
 *      Days 1-28 are counted exactly as the existing report does.
 *
 *   2. NO-EMOLLIATION REASON BREAKDOWN.
 *      Every Not-Given daily session (int_emoliate_baby = 'N') carries a reason
 *      hierarchy:
 *        master  int_no_emol_reason
 *          MD          -> int_doctor_deny_reason_list  (OTH_DOC_DENY -> int_doctor_deny_othr_reason free text)
 *          SAE         -> int_sae_reasons___*          (checkbox set)
 *          OTH_NOEMOL  -> int_other_no_emoll_reason    (free text)
 *      Reason counts are tallied per site and grand total, and must reconcile
 *      to Not Given (blank master => "Reason not recorded").
 *
 * Filters (all optional, same as the base aggregator):
 *   date_from / date_to  — enr_datetime (enrollment filter only)
 *   site_filter          — site codes to include
 *   dc_filter            — data collector IDs (baby_dc_id) to include
 */
class EmolliationCoverageDashboardAggregator extends AbstractAggregator
{
    private string     $primaryKey;
    private array      $siteFilter;
    private array      $dcFilter;
    private ?\DateTime $from;
    private ?\DateTime $to;

    // ── Per-record accumulators ───────────────────────────────────────────────
    private array $site        = [];  // record_id => enr_hosp_code
    private array $dc          = [];  // record_id => baby_dc_id
    private array $enrolled    = [];  // record_id => true
    private array $enrDate     = [];  // record_id => DateTime
    private array $dob         = [];  // record_id => DateTime
    private array $stopDates   = [];  // record_id => [reason => DateTime]

    // Day-0 flags (CHANGED — booleans, not a session count)
    private array $day0Module  = [];  // record_id => bool (module complete)
    private array $day0Daily   = [];  // record_id => ['attempted' => bool, 'given' => bool]

    // sessions[$id]['rows'] = [['day'=>int,'given'=>bool,'reason'=>?array], ...]
    private array $sessions = [];

    // No-Emolliation reason tally:  byReason[site][masterCode] = ['count'=>int, 'sub'=>[label=>count], 'label'=>string]
    private array $reasonTally = [];

    public function __construct(
        string  $primaryKey,
        array   $siteFilter = [],
        array   $dcFilter   = [],
        ?string $dateFrom   = null,
        ?string $dateTo     = null
    )
    {
        $this->primaryKey = $primaryKey;
        $this->siteFilter = array_map('trim', $siteFilter);
        $this->dcFilter   = array_map('trim', $dcFilter);
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

            // ── Non-repeating day0 — enrollment + Day 0 module ────────────
            if ($event === 'day0_arm_1' && $repeatForm === '')
            {
                $consent = trim($row['enr_consent_granted'] ?? '');
                $arm     = trim($row['enr_study_arm']       ?? '');
                $hosp    = trim($row['enr_hosp_code']       ?? '');

                if (($consent === 'Y' || $consent === '1')
                    && $arm === 'Intervention'
                    && $hosp !== '')
                {
                    $this->enrolled[$id] = true;
                    $this->site[$id]     = $hosp;
                }

                if (!empty($row['enr_datetime']))
                    $this->enrDate[$id] = $this->parseDate($row['enr_datetime']);
                if (!empty($row['enr_baby_dob']))
                    $this->dob[$id] = $this->parseDate($row['enr_baby_dob']);

                // CHANGED: Day-0 module counts as DONE when the form is complete.
                // (No int_emoliate_baby field on this form — completion = session done.)
                // We treat "fi_emol_perid filled" OR "complete flag set" as complete,
                // matching the base aggregator's day0ModFilled test.
                $fiEmolPerid   = strtoupper(trim($row['fi_emol_perid'] ?? ''));
                $day0ModFilled = $fiEmolPerid !== ''
                    || trim($row['day0_interventionemolliation_module_complete'] ?? '') !== '';

                if ($day0ModFilled)
                    $this->day0Module[$id] = true;
            }

            // ── Repeating prescreening form — capture DC ───────────────────
            if ($event === 'day0_arm_1'
                && $repeatForm === 'baby_prescreening_and_screening_form')
            {
                $dc = trim($row['baby_dc_id'] ?? '');
                if ($dc !== '') $this->dc[$id] = $dc;
            }

            // ── Stop dates ─────────────────────────────────────────────────
            if ($event === 'discharge_arm_1' && !empty($row['dis_datetime'])) {
                $dt = $this->parseDate($row['dis_datetime']);
                if ($dt) $this->stopDates[$id]['discharge'] = $dt;
            }

            if ($event === 'other_forms_arm_1')
            {
                foreach (['deviation'  => 'pd_datetime',
                          'withdrawal' => 'sw_datetime',
                          'sae'        => 'sae_datetime'] as $reason => $field) {
                    if (!empty($row[$field])) {
                        $dt = $this->parseDate($row[$field]);
                        if ($dt) $this->stopDates[$id][$reason] = $dt;
                    }
                }
            }

            // ── Daily emolliation sessions ─────────────────────────────────
            if ($repeatForm === 'daily_interventionemolliation_form')
            {
                $emolValue = trim($row['int_emoliate_baby'] ?? '');
                if ($emolValue === '') continue;  // form not filled — skip

                preg_match('/^day(\d+)_arm_/', $event, $m);
                $sessionDay = isset($m[1]) ? (int)$m[1] : null;
                $given      = ($emolValue === 'Y');

                // No-Emolliation reason — only for N rows
                $reason = $given ? null : $this->extractReason($row);

                $this->sessions[$id]['rows'][] = [
                    'day'    => $sessionDay,
                    'given'  => $given,
                    'reason' => $reason,
                ];

                // CHANGED: Day-0 daily contribution is a flag, not a per-form count.
                if ($sessionDay === 0)
                {
                    if (!isset($this->day0Daily[$id]))
                        $this->day0Daily[$id] = ['attempted' => false, 'given' => false];
                    $this->day0Daily[$id]['attempted'] = true;   // Y or N filled
                    if ($given) $this->day0Daily[$id]['given'] = true;
                }
            }
        }

        return $this->resolve();
    }

    // =========================================================================
    // No-Emolliation reason extraction (one N session -> structured reason)
    // =========================================================================

    /**
     * Returns ['master' => code, 'master_label' => string, 'sub' => ?string].
     * Free-text values are returned verbatim as the sub label.
     */
    private function extractReason(array $row): array
    {
        $master = trim($row['int_no_emol_reason'] ?? '');
        if ($master === '')
            return ['master' => '', 'master_label' => 'Reason not recorded', 'sub' => null];

        $sub = null;

        if ($master === 'MD')
        {
            $deny = trim($row['int_doctor_deny_reason_list'] ?? '');
            if ($deny === 'OTH_DOC_DENY')
                $sub = trim($row['int_doctor_deny_othr_reason'] ?? '') ?: 'Other (doctor denied)';
            elseif ($deny !== '')
                $sub = $deny;  // coded value; exporter maps to label
        }
        elseif ($master === 'SAE')
        {
            // int_sae_reasons is a checkbox set: int_sae_reasons___<code> = '1'
            $checked = [];
            foreach ($row as $k => $v) {
                if (strpos($k, 'int_sae_reasons___') === 0 && trim((string)$v) === '1') {
                    $checked[] = substr($k, strlen('int_sae_reasons___'));
                }
            }
            if ($checked) $sub = implode(', ', $checked);  // codes; exporter maps
        }
        elseif ($master === 'OTH_NOEMOL')
        {
            $sub = trim($row['int_other_no_emoll_reason'] ?? '') ?: 'Other';
        }

        return ['master' => $master, 'master_label' => $master, 'sub' => $sub];
    }

    // =========================================================================
    // Resolution
    // =========================================================================

    private function resolve(): array
    {
        $babies = [];
        $bySite = [];
        $calc   = new EmolliationSessionCalculator();

        foreach ($this->enrolled as $id => $__)
        {
            $site    = $this->site[$id]    ?? null;
            $dc      = $this->dc[$id]      ?? '';
            $enrDate = $this->enrDate[$id] ?? null;
            $dob     = $this->dob[$id]     ?? null;

            if ($site === null) continue;

            if (!empty($this->siteFilter)
                && !in_array($site, $this->siteFilter, true)) continue;
            if (!empty($this->dcFilter)
                && !in_array($dc, $this->dcFilter, true)) continue;

            if ($this->from || $this->to)
            {
                if ($enrDate === null) continue;
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $rows      = $this->sessions[$id]['rows'] ?? [];
            $stopDates = $this->stopDates[$id] ?? [];
            $refDate   = $dob ?? $enrDate;

            $enrollmentDay = ($dob !== null && $enrDate !== null)
                ? (int)$enrDate->diff($dob)->days
                : 0;

            $endInfo = $refDate !== null
                ? $calc->computeEndDay($refDate, $stopDates)
                : ['endDay' => 0, 'stopReason' => '', 'lastDayIsToday' => false];

            $endDay         = $endInfo['endDay'];
            $stopReason     = $endInfo['stopReason'];
            $lastDayIsToday = $endInfo['lastDayIsToday'];

            // Remap pre-enrollment daily rows to the enrollment day
            if ($enrollmentDay > 0) {
                foreach ($rows as &$s) {
                    if ($s['day'] !== null && $s['day'] < $enrollmentDay) {
                        $s['day'] = $enrollmentDay;
                    }
                }
                unset($s);
            }

            // ── CHANGED: Day-0 flat, BINARY coverage ──────────────────────
            // Day 0 is a single yes/no checkpoint. It contributes EQUALLY to
            // Attempted and Given (1/1 when covered, 0/0 when not), so it can
            // never create a "Not Given", and its sessions are excluded from
            // the No-Emolliation reason breakdown.
            //   Covered = Day-0 module complete OR a Day-0 daily form = 'Y'.
            $hasDay0Module  = $this->day0Module[$id] ?? false;
            $day0DailyGiven = $this->day0Daily[$id]['given'] ?? false;

            $day0InScope = ($enrollmentDay === 0);     // Day-1 enrollee: no Day 0
            $day0Done    = ($day0InScope && ($hasDay0Module || $day0DailyGiven)) ? 1 : 0;

            // ── DASHBOARD endDay rule (Version A) ─────────────────────────
            // The dashboard ignores the endDay day ENTIRELY — Due, Done, Not
            // Given and reasons are counted strictly for days BEFORE endDay.
            // cutDay is the last counted day (inclusive). Days >= endDay are
            // dropped. (This deliberately differs from the shared calculator /
            // the original EmolliationCoverage report, which counts the last
            // day by formsOnLastDay. Those are left unchanged.)
            $cutDay = max(0, $endDay - 1);

            // ── Count Attempted / Given — Day 0 flat, Days 1..cutDay only ──
            // Sessions on or after endDay are ignored entirely: not attempted,
            // not given, no reason. Day 0 is the flat binary term above.
            $attempted = $day0Done;
            $given     = $day0Done;

            foreach ($rows as $s)
            {
                $day = $s['day'];
                if ($day === 0)     continue;   // Day 0 handled by flat term
                if ($day === null)  continue;   // unparseable day — skip
                if ($day > $cutDay) continue;   // on/after endDay — ignore

                $attempted++;
                if ($s['given']) $given++;
            }

            $notGiven = $attempted - $given;

            // ── Sessions Due (Version A) ──────────────────────────────────
            // Full days 1..cutDay at 3/day, plus the flat Day-0 term. The
            // endDay day contributes nothing. Computed directly so the shared
            // calculator stays untouched.
            $day0Override = $day0InScope ? 1 : 0;     // flat Day-0 Due
            $sessionsDue  = $refDate !== null
                ? ($cutDay * EmolliationSessionCalculator::SESSIONS_PER_DAY) + $day0Override
                : null;

            $fullInterv = ($attempted > 0 && $notGiven === 0);
            $stopped    = !empty($stopDates);

            // ── Per-baby reason list + tally — SAME window as the counts ──
            // Day 0 excluded; days on/after endDay excluded. Keeps reason
            // totals reconciled with Not Given exactly.
            $babyReasons = [];
            foreach ($rows as $s) {
                if ($s['given'] || $s['reason'] === null) continue;
                $day = $s['day'];
                if ($day === 0)     continue;   // Day 0 ignored from reasons
                if ($day === null)  continue;
                if ($day > $cutDay) continue;   // on/after endDay — ignore
                $babyReasons[] = $s['reason'];
                $this->tallyReason($site, $s['reason']);
            }

            $babies[$id] = [
                'site'               => $site,
                'dc'                 => $dc,
                'full_interv'        => $fullInterv,
                'stopped'            => $stopped,
                'stop_reason'        => $stopReason,
                'sessions_due'       => $sessionsDue,
                'sessions_attempted' => $attempted,
                'sessions_given'     => $given,
                'sessions_not_given' => $notGiven,
                'no_emol_reasons'    => $babyReasons,
            ];

            foreach ([$site, 'Total'] as $grp)
            {
                if (!isset($bySite[$grp]))
                    $bySite[$grp] = ['site' => $grp, 'enrolled' => 0, 'full_interv' => 0,
                                     'stopped' => 0, 'not_given' => 0];
                $bySite[$grp]['enrolled']++;
                $bySite[$grp]['not_given'] += $notGiven;
                if ($fullInterv) $bySite[$grp]['full_interv']++;
                if ($stopped)    $bySite[$grp]['stopped']++;
            }
        }

        foreach ($bySite as &$s)
        {
            $n = $s['enrolled'];
            $s['pct_full']    = $n > 0 ? round($s['full_interv'] / $n * 100, 1) : null;
            $s['pct_stopped'] = $n > 0 ? round($s['stopped']     / $n * 100, 1) : null;
        }
        unset($s);

        // Move Total to the end
        if (isset($bySite['Total']))
        {
            $t = $bySite['Total']; unset($bySite['Total']); $bySite['Total'] = $t;
        }

        // Roll site reason tallies into a Total bucket
        $this->rollupReasonTotal();

        return [
            'by_site'      => $bySite,
            'babies'       => $babies,
            'reason_tally' => $this->reasonTally,
        ];
    }

    // =========================================================================
    // Reason tally helpers
    // =========================================================================

    private function tallyReason(string $site, array $reason): void
    {
        $master = $reason['master'] !== '' ? $reason['master'] : '__NONE__';
        foreach ([$site, 'Total'] as $grp) {
            if (!isset($this->reasonTally[$grp][$master])) {
                $this->reasonTally[$grp][$master] = [
                    'count' => 0,
                    'label' => $reason['master_label'],
                    'sub'   => [],
                ];
            }
            $this->reasonTally[$grp][$master]['count']++;
            if (!empty($reason['sub'])) {
                $sub = $reason['sub'];
                $this->reasonTally[$grp][$master]['sub'][$sub] =
                    ($this->reasonTally[$grp][$master]['sub'][$sub] ?? 0) + 1;
            }
        }
    }

    private function rollupReasonTotal(): void
    {
        // Move Total to the end of the tally for stable rendering.
        if (isset($this->reasonTally['Total'])) {
            $t = $this->reasonTally['Total'];
            unset($this->reasonTally['Total']);
            $this->reasonTally['Total'] = $t;
        }
    }

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0, 0, 0); return $dt; }
        catch (\Exception) { return null; }
    }
}

<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Support\EmolliationSessionCalculator;

/**
 * EmolliationCoverageAggregator
 *
 * Produces the PI-presentation summary table:
 *
 *   Site | Enrolled | Received Full Intervention (%) | Stopped (%)
 *
 * Filters (all constructor params, all optional):
 *   date_from / date_to  — filter by enr_datetime (enrollment date only)
 *   site_filter          — array of site codes to include
 *   dc_filter            — array of data collector IDs (baby_dc_id) to include
 *
 * Definitions:
 *   Enrolled    = Intervention arm babies with consent + enr_datetime in range
 *   Attempted   = Day 0 module (1 if fi_emol_perid filled)
 *                 + all daily_interventionemolliation_form instances (no exclusions)
 *   Given       = Day 0 module (1 if fi_emol_perid filled)
 *                 + daily forms where int_emoliate_baby = 'Y' (no exclusions)
 *   Not Given   = Attempted - Given
 *   Due         = see EmolliationSessionCalculator::sessionsFromEndDay()
 *   Full Interv = Attempted > 0 AND Not Given = 0
 *   Stopped     = any stop event (discharge/PD/withdrawal/SAE)
 */
class EmolliationCoverageAggregator extends AbstractAggregator
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
    private array $fiEmolPerid = [];  // record_id => 'MOR'|'AFT'|'EVE'|''
    private array $stopDates   = [];  // record_id => [reason => DateTime]

    // sessions[$id] = [
    //   'day0_attempted' => 0|1,
    //   'rows'           => [['day' => int, 'given' => bool], ...]
    // ]
    private array $sessions = [];

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

                // Always capture dates and fi_emol_perid (before filter)
                if (!empty($row['enr_datetime']))
                    $this->enrDate[$id] = $this->parseDate($row['enr_datetime']);
                if (!empty($row['enr_baby_dob']))
                    $this->dob[$id] = $this->parseDate($row['enr_baby_dob']);

                $fiEmolPerid = strtoupper(trim($row['fi_emol_perid'] ?? ''));
                if ($fiEmolPerid !== '')
                    $this->fiEmolPerid[$id] = $fiEmolPerid;

                // Day 0 module — 1 attempted + 1 given if fi_emol_perid filled
                // or form marked complete (no int_emoliate_baby on this form)
                $day0ModFilled = $fiEmolPerid !== ''
                    || trim($row['day0_interventionemolliation_module_complete'] ?? '') !== '';

                if ($day0ModFilled)
                    $this->sessions[$id]['day0_attempted'] = 1;
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

            // ── Daily emolliation sessions — all days, no exclusions ───────
            // Extract day number from event name: 'day3_arm_1' → 3
            // Only count rows where int_emoliate_baby was actually filled (Y or N).
            // Blank means the form was opened but not completed — session did not happen.
            if ($repeatForm === 'daily_interventionemolliation_form') 
            {
                $emolValue = trim($row['int_emoliate_baby'] ?? '');
                if ($emolValue === '') continue;  // form not filled — skip

                preg_match('/^day(\d+)_arm_/', $event, $m);
                $sessionDay = isset($m[1]) ? (int)$m[1] : null;

                $this->sessions[$id]['rows'][] = [
                    'day'   => $sessionDay,
                    'given' => $emolValue === 'Y',
                ];
            }
        }

        return $this->resolve();
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

            // ── Site filter ───────────────────────────────────────────────
            if (!empty($this->siteFilter)
                && !in_array($site, $this->siteFilter, true)) continue;

            // ── DC filter ─────────────────────────────────────────────────
            if (!empty($this->dcFilter)
                && !in_array($dc, $this->dcFilter, true)) continue;

            // ── Date filter on enr_datetime (enrollment filter only) ──────
            if ($this->from || $this->to) 
            {
                if ($enrDate === null) continue;
                if ($this->from && $enrDate < $this->from) continue;
                if ($this->to   && $enrDate > $this->to)   continue;
            }

            $sessions  = $this->sessions[$id] ?? ['day0_attempted' => 0, 'rows' => []];
            $stopDates = $this->stopDates[$id] ?? [];

            // ── DOB-based reference (event names day0_arm_1 … are DOB-relative) ──
            // Using DOB as the single reference ensures sessionDay from event
            // name always aligns with endDay. Falls back to enrDate if DOB absent.
            $refDate = $dob ?? $enrDate;

            // Enrollment day relative to DOB:
            //   0 → baby enrolled on Day 0 (same day as birth) — normal path
            //   1 → baby enrolled on Day 1 — no Day 0 module expected
            $enrollmentDay = ($dob !== null && $enrDate !== null)
                ? (int)$enrDate->diff($dob)->days
                : 0;

            // ── Compute endDay ────────────────────────────────────────────
            $endInfo        = $refDate !== null
                ? $calc->computeEndDay($refDate, $stopDates)
                : ['endDay' => 0, 'stopReason' => '', 'lastDayIsToday' => false];

            $endDay         = $endInfo['endDay'];
            $stopReason     = $endInfo['stopReason'];
            $lastDayIsToday = $endInfo['lastDayIsToday'];

            // ── Remap sessions from before enrollment to enrollment day ───
            // Case A: a Day-1 enrollee whose site entered daily forms in
            // day0_arm_1 instead of day1_arm_1.  Crediting those to the
            // enrollment day ensures they are counted in formsOnLastDay
            // when endDay = enrollmentDay, and not lost to a phantom Day 0.
            $rows = $sessions['rows'] ?? [];
            if ($enrollmentDay > 0) {
                foreach ($rows as &$s) {
                    if ($s['day'] !== null && $s['day'] < $enrollmentDay) {
                        $s['day'] = $enrollmentDay;
                    }
                }
                unset($s);
            }

            // ── Count Attempted and Given — no exclusions on any day ──────
            $attempted      = (int)($sessions['day0_attempted'] ?? 0);
            $given          = $attempted;  // Day 0 module always given if filled
            $formsOnLastDay = 0;

            foreach ($rows as $s)
            {
                $attempted++;
                if ($s['given']) $given++;

                // Track forms on last day for partial-day Due calculation
                if ($s['day'] !== null && $s['day'] === $endDay)
                    $formsOnLastDay++;
            }

            $notGiven = $attempted - $given;

            // ── Sessions Due ──────────────────────────────────────────────
            // For Day 1+ enrollees: no Day 0 module → day0 sessions due = 0.
            // For Day 0 enrollees:  derive from fi_emol_perid as normal.
            $fiEmolPerid  = $this->fiEmolPerid[$id] ?? '';
            $day0Override = $enrollmentDay > 0 ? 0 : null;

            $sessionsDue = $refDate !== null
                ? EmolliationSessionCalculator::sessionsFromEndDay(
                    $endDay,
                    $fiEmolPerid,
                    $formsOnLastDay,
                    $stopReason,
                    $lastDayIsToday,
                    $day0Override
                )
                : null;

            // ── Full Intervention ─────────────────────────────────────────
            $fullInterv = ($attempted > 0 && $notGiven === 0);

            // ── Stopped flag ──────────────────────────────────────────────
            $stopped = !empty($stopDates);

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
            ];

            foreach ([$site, 'Total'] as $grp) 
            {
                if (!isset($bySite[$grp]))
                    $bySite[$grp] = ['site' => $grp, 'enrolled' => 0, 'full_interv' => 0, 'stopped' => 0];
                $bySite[$grp]['enrolled']++;
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

        if (isset($bySite['Total'])) 
        {
            $t = $bySite['Total']; unset($bySite['Total']); $bySite['Total'] = $t;
        }

        return ['by_site' => $bySite, 'babies' => $babies];
    }

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0, 0, 0); return $dt; }
        catch (\Exception) { return null; }
    }
}

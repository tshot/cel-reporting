<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AbstractAggregator;
use CEL\Projects\Emollient\Support\EmolliationSessionCalculator;
use CEL\Projects\Emollient\Aggregator\FormCompletionConfig;

/**
 * FormCompletionAggregator
 *
 * Calculates per-participant completion for any longitudinal daily form.
 *
 * Supports:
 *   - Single-instance forms (daily_clinical_monitoring, skin_condition_scoring)
 *   - Multi-instance repeating forms (day0_interventionemolliation_module — 3×/day)
 *   - Base conditions (e.g. enr_study_arm = 'Intervention')
 *   - Parent form dependencies (form only due when parent was completed that day)
 *
 * Config keys (set via reports.php):
 *
 *   form_name           string   REDCap form name to check
 *   max_days            int      Maximum study days (default 28)
 *   sessions_per_day    int      Required instances per day (default 1)
 *                                A day is complete only when ALL sessions are complete.
 *   base_conditions     array    Extra enrollment-time conditions beyond consent.
 *                                Format: [['field' => 'enr_study_arm', 'value' => 'Intervention']]
 *   depends_on          array    Parent form dependencies.
 *                                Format: [['form' => 'daily_clinical_monitoring', 'type' => 'daily_parent']]
 *   scheduled_days      array    If set, form is only due on these specific day numbers.
 *                                e.g. [3, 7, 14, 28] for Skin Scoring.
 *                                Days not in this list are treated as 'not_due'.
 *   field_conditions    array    Per-day field-value conditions that must be met (on the same event)
 *                                for the form to be considered due.
 *                                Format: [['field' => 'dcm_vit_status', 'value' => 'SEPSIS_VS_ALIVE']]
 *                                All conditions must pass (AND logic).
 *                                A day where any condition fails is treated as 'not_due'.
 *   date_filter_field   string   Field used to assign participant to a period (default 'enr_datetime')
 *                                Filters which participants fall within date_from/date_to.
 *   birth_date_field    string   Field used to compute study day numbers (default 'enr_baby_dob')
 *                                Used for end_day calculation and stop date arithmetic.
 *   dob_event           string   Event containing both date fields (default 'day0_arm_1')
 *   enrollment_event    string   Event containing consent/site/arm (default 'day0_arm_1')
 *   discharge_event     string   Event containing dis_datetime (default 'discharge_arm_1')
 *   other_forms_event   string   Event containing pd/sw/sae datetimes (default 'other_forms_arm_1')
 *   date_from/date_to   string   Filter participants by DOB
 *
 * Day status values:
 *   'completed' — all required sessions complete (status = 2)
 *   'partial'   — some sessions complete (only when sessions_per_day > 1)
 *   'missing'   — form(s) due but not completed
 *   'blocked'   — parent form not completed on this day (not in denominator)
 *   'not_due'   — beyond the participant's end day (not in denominator)
 */
class FormCompletionAggregator extends AbstractAggregator
{
    private string             $primaryKey;
    private FormCompletionConfig $config;

    public function __construct(string $primaryKey, FormCompletionConfig $config)
    {
        $this->primaryKey = $primaryKey;
        $this->config     = $config;
    }

    public function aggregate(iterable $records): array
    {
        // ── Buffer all rows per record ────────────────────────────────────
        $byRecord = [];
        foreach ($records as $row)
        {
            $id = $row[$this->primaryKey] ?? null;
            if (!$id) continue;
            $byRecord[$id][] = $row;
        }

        // ── Identify parent forms for dependency checking ─────────────────
        $dailyParents = [];
        foreach ($this->config->dependencies as $dep)
        {
            if (($dep['type'] ?? '') === 'daily_parent')
                $dailyParents[] = $dep['form'];
        }

        $today           = new \DateTime('today');
        $completionField = $this->config->formName . '_complete';
        $isEmolliationForm = !empty($this->config->day0FormName);
        $enrollmentDay = 0;
        $multiSession    = $this->config->sessionsPerDay > 1;

        $participants = [];

        foreach ($byRecord as $id => $rows)
        {
            $dob        = null;   // birth date — for day calculations
            $filterDate = null;   // enr_datetime — for period filter
            $enrollmentDate = null; // enr_datetime stored for display
            $site       = null;
            $arm        = null;
            $enrolled   = false;
            $stopDates  = [];

            // Single-session: [dayNum => bool]
            // Multi-session:  [dayNum => int] — count of completed instances
            // dayExpected:    [dayNum => int] — count of instances seen (complete or not)
            $dayComplete    = [];
            $dayExpected    = [];   // [dayNum => count of repeating instances seen]
            $day0Seen       = false; // whether day0_interventionemolliation_module row was seen
            $day0FormDone   = 0;    // 0 or 1 — day0_interventionemolliation_module completion
            $fiEmolPerid    = '';   // MOR / AFT / EVE — determines Day 0 expected count
            $parentComplete = [];
            foreach ($dailyParents as $pf)
                $parentComplete[$pf] = [];

            // Per-day snapshot of fields used in field_conditions
            $dayFieldValues = [];   // [dayNum => [field => value]]

            // Buffered daily-form rows for two-pass processing.
            // We collect them in pass 1 and re-key them in pass 2 once DOB is
            // known, so that dayDateField (e.g. dcm_datetime) can override the
            // event-name day for forms entered into the wrong event slot.
            $pendingDailyRows = [];

            foreach ($rows as $row)
            {
                $event = $row['redcap_event_name'] ?? '';

                // ── DOB and date filter field ─────────────────────────────
                if ($event === $this->config->dobEvent)
                {
                    if (!empty($row[$this->config->birthDateField]))
                        $dob = $this->parseDate($row[$this->config->birthDateField]);
                    if (!empty($row[$this->config->dateFilterField])) 
                    {
                        $filterDate     = $this->parseDate($row[$this->config->dateFilterField]);
                        $enrollmentDate = $filterDate;
                    }
                }

                // ── Enrollment ────────────────────────────────────────────
                if ($event === $this->config->enrollmentEvent)
                {
                    $consent = $row['enr_consent_granted'] ?? null;
                    if ($consent === '1' || $consent === 'Y') $enrolled = true;
                    if (!empty($row['enr_hosp_code'])) $site = $row['enr_hosp_code'];
                    if (!empty($row['enr_study_arm'])) $arm  = $row['enr_study_arm'];
                }

                // ── Stop dates ────────────────────────────────────────────
                if ($event === $this->config->dischargeEvent && !empty($row['dis_datetime']))
                {
                    $dt = $this->parseDate($row['dis_datetime']);
                    if ($dt) $stopDates['discharge'] = $dt;
                }

                if ($event === $this->config->otherFormsEvent)
                {
                    foreach (['deviation' => 'pd_datetime',
                              'withdrawal' => 'sw_datetime',
                              'sae'        => 'sae_datetime'] as $reason => $field)
                    {
                        if (!empty($row[$field]))
                        {
                            $dt = $this->parseDate($row[$field]);
                            if ($dt && (!isset($stopDates[$reason]) || $dt < $stopDates[$reason]))
                                $stopDates[$reason] = $dt;
                        }
                    }
                }

                // ── Day 0 non-repeating emolliation form ──────────────────
                // day0_interventionemolliation_module is always 1 instance, non-repeating,
                // on day0_arm_1. Tracked separately via day0_form_name config key.
                // Its _complete field is day0_interventionemolliation_module_complete.
                if ($multiSession
                    && !empty($this->config->day0FormName)
                    && $event === $this->config->dobEvent)
                {
                    $repeatForm = $row['redcap_repeat_instrument'] ?? '';
                    if ($repeatForm === '') 
                    {
                        $d0Field = $this->config->day0FormName . '_complete';
                        $d0Done  = ($row[$d0Field] ?? '') === '2';
                        // Capture fi_emol_perid to determine Day 0 expected sessions:
                        //   MOR → 3 (1 day0form + 2 repeating)
                        //   AFT → 2 (1 day0form + 1 repeating)
                        //   EVE → 1 (1 day0form + 0 repeating)
                        if (empty($fiEmolPerid) && !empty($row['fi_emol_perid'])) 
                        {
                            $fiEmolPerid = strtoupper(trim($row['fi_emol_perid']));
                        }
                        $day0Seen    = true;
                        $day0FormDone = $d0Done ? 1 : 0;  // always 0 or 1
                    }
                }

                // ── Daily form events (day0_arm_1 repeating + day1-28) ────
                // BUFFERED: we may need to re-map the day based on the form's
                // own date field (dayDateField). Final processing happens in
                // pass 2, after DOB is known.
                if (preg_match('/^day(\d+)_arm_\d+$/', $event, $m))
                {
                    $pendingDailyRows[] = ['row' => $row, 'eventDayNum' => (int)$m[1]];
                }
            }

            // ── Pass 2: process buffered daily-form rows ──────────────────
            // For each row we determine which day to credit:
            //   1. If dayDateField is configured AND DOB is known AND the form
            //      has a parseable date in that field → derive dayNum from
            //      (form_date − DOB).days. This corrects rows entered into
            //      the wrong dayN_arm_1 event slot.
            //   2. Otherwise → use the day from the event name.
            //
            // The `forms_moved_by_date` counter tracks how many rows were
            // re-mapped, useful as a data-quality signal.
            $formsMovedByDate = 0;
            $minDay = $isEmolliationForm ? $enrollmentDay : (empty($this->config->day0FormName) ? 1 : 0);

            foreach ($pendingDailyRows as $entry)
            {
                $row    = $entry['row'];
                $dayNum = $entry['eventDayNum'];

                // Date-field override (e.g. scs_datetime for SkinScoring)
                if ($this->config->dayDateField !== '' && $dob !== null
                    && !empty($row[$this->config->dayDateField]))
                {
                    $formDate = $this->parseDate($row[$this->config->dayDateField]);
                    if ($formDate !== null)
                    {
                        $derivedDay = (int)$formDate->diff($dob)->days;
                        if ($derivedDay >= $minDay && $derivedDay <= $this->config->maxDays
                            && $derivedDay !== $dayNum)
                        {
                            $dayNum = $derivedDay;
                            $formsMovedByDate++;
                        }
                    }
                }

                // Case A remap: for Day-1+ enrollees, any form entered in an event
                // before the enrollment day (e.g. day0_arm_1 for a Day-1 enrollee)
                // is credited to the enrollment day rather than discarded.
                if ($isEmolliationForm && $enrollmentDay > 0 && $dayNum < $enrollmentDay)
                {
                    $dayNum = $enrollmentDay;
                    $formsMovedByDate++;
                }

                if ($dayNum < $minDay || $dayNum > $this->config->maxDays) continue;

                $done = ($row[$completionField] ?? '') === '2';

                if ($multiSession)
                {
                    $repeatForm = $row['redcap_repeat_instrument'] ?? '';
                    if ($repeatForm !== $this->config->formName) continue;

                    if (!isset($dayComplete[$dayNum])) $dayComplete[$dayNum] = 0;
                    if (!isset($dayExpected[$dayNum])) $dayExpected[$dayNum] = 0;
                    $dayExpected[$dayNum]++;
                    if ($done) $dayComplete[$dayNum]++;
                }
                else
                {
                    if ($done || !isset($dayComplete[$dayNum]))
                        $dayComplete[$dayNum] = $done;
                }

                // Parent forms (always single-instance)
                foreach ($dailyParents as $pf)
                {
                    $pDone = ($row[$pf . '_complete'] ?? '') === '2';
                    if ($pDone || !isset($parentComplete[$pf][$dayNum]))
                        $parentComplete[$pf][$dayNum] = $pDone;
                }

                // Capture field_conditions values for this day
                foreach ($this->config->fieldConditions as $cond)
                {
                    $field = $cond['field'] ?? '';
                    if ($field && isset($row[$field]))
                        $dayFieldValues[$dayNum][$field] = $row[$field];
                }
            }

            // ── Base eligibility checks ───────────────────────────────────
            if (!$enrolled || !$dob) continue;

            // Check extra base conditions (e.g. enr_study_arm = 'Intervention')
            foreach ($this->config->baseConditions as $cond)
            {
                $field    = $cond['field']    ?? '';
                $expected = $cond['value']    ?? '';
                $actual   = match($field) {
                    'enr_study_arm'  => $arm,
                    'enr_hosp_code'  => $site,
                    default          => null,
                };
                if ($actual !== $expected) continue 2;
            }

            // ── Site filter ──────────────────────────────────────────────
            if (!empty($this->config->siteFilter)
                && !in_array($site, $this->config->siteFilter, true)) continue;

            // ── Date filter on enr_datetime ───────────────────────────────
            // Assigns participant to a reporting period.
            // Uses filterDate (enr_datetime), not birth date.
            if ($filterDate !== null)
            {
                if ($this->config->dateFrom && $filterDate < new \DateTime($this->config->dateFrom)) continue;
                if ($this->config->dateTo   && $filterDate > (new \DateTime($this->config->dateTo))->setTime(23,59,59)) continue;
            }
            elseif ($this->config->dateFrom || $this->config->dateTo)
            {
                // enr_datetime missing — exclude from filtered reports
                continue;
            }

            // ── Compute end day ───────────────────────────────────────────
            // For emolliation (day0FormName set), use DOB as reference so that
            // the endDay number aligns with REDCap event names (day0_arm_1 = Day 0
            // from DOB, day1_arm_1 = Day 1 from DOB, etc.).
            //
            // enrollmentDay = days from DOB to enr_datetime:
            //   0 → enrolled on Day 0 (same day as birth) — Day 0 module expected
            //   1 → enrolled on Day 1 — no Day 0 module, study starts from Day 1
            $enrollmentDay     = 0;

            if ($isEmolliationForm && $dob !== null) {
                $refDate = $dob;
                if ($enrollmentDate !== null) {
                    $enrollmentDay = (int)$enrollmentDate->diff($dob)->days;
                }
            } else {
                // All other forms: use DOB (or enrollmentDate fallback)
                $refDate = $dob ?? $enrollmentDate;
            }

            $daysElapsed = (int)$today->diff($refDate)->days;
            $endDay      = min($this->config->maxDays, $daysElapsed);
            $endReason   = $daysElapsed >= $this->config->maxDays ? 'day28' : 'ongoing';

            foreach ($stopDates as $reason => $stopDt)
            {
                $stopDay = (int)$stopDt->diff($refDate)->days;
                if ($stopDay < $endDay) { $endDay = $stopDay; $endReason = $reason; }
            }

            $endDay = max(0, $endDay);

            // ── Resolve per-day status ────────────────────────────────────
            // For emolliation: startDay = enrollmentDay (1 for Day-1 enrollees).
            // This prevents Day 0 appearing as 'missing' for babies not enrolled
            // until Day 1, and aligns the day loop with the DOB-based reference.
            $startDay = $isEmolliationForm ? $enrollmentDay : (empty($this->config->day0FormName) ? 1 : 0);
            $daySummary = [];

            for ($d = $startDay; $d <= $this->config->maxDays; $d++)
            {
                // Day 0 bypasses scheduled-day, field-condition and blocked checks.
                // It requires the same sessionsPerDay count as all other days.
                $isDay0 = ($d === 0);

                if (!$isDay0 && $d > $endDay) { $daySummary[$d] = 'not_due'; continue; }

                // Scheduled days: if set, only those days are ever due (Day 0 bypasses this)
                if (!$isDay0 && !empty($this->config->scheduledDays)
                    && !in_array($d, $this->config->scheduledDays, true))
                {
                    $daySummary[$d] = 'not_due';
                    continue;
                }

                // Field conditions (Day 0 bypasses — it happens at enrollment regardless)
                if (!$isDay0) 
                {
                    foreach ($this->config->fieldConditions as $cond)
                    {
                        $field    = $cond['field'] ?? '';
                        $expected = $cond['value'] ?? '';
                        $op       = $cond['op']    ?? 'eq';
                        $actual   = $dayFieldValues[$d][$field] ?? '';
                        $match    = ($actual === $expected);
                        if ($op === 'eq'  && !$match) { $daySummary[$d] = 'not_due'; continue 2; }
                        if ($op === 'neq' &&  $match) { $daySummary[$d] = 'not_due'; continue 2; }
                    }
                }

                // Check parent dependencies (Day 0 bypasses).
                // If the parent form (e.g. daily_clinical_monitoring) wasn't
                // done that day, the child is marked 'blocked' — surfaced in
                // the UI as "Parent Not Done" so it's clearly distinguished
                // from regular missing (where the parent IS done but the child
                // is not). Blocked days stay OUT of the % denominator so a site
                // isn't double-penalised when the parent itself is missing.
                if (!$isDay0) 
                {
                    $parentMissing = false;
                    foreach ($dailyParents as $pf)
                    {
                        if (empty($parentComplete[$pf][$d])) { $parentMissing = true; break; }
                    }
                    if ($parentMissing) { $daySummary[$d] = 'blocked'; continue; }
                }

                // Day 0 requires the same number of sessions as every other day
                $sessionsRequired = $this->config->sessionsPerDay;

                // ── Stop-day rule ───────────────────────────────────────────
                // If today's iteration day d IS the stop day (the day the case
                // ended via discharge/PD/SW/SAE), and the form was not filled
                // that day, treat it as 'not_due' rather than 'missing'.
                // Operational reason: on the discharge day (or PD/SW/SAE day)
                // the team typically does not file a new daily form. The day is
                // only counted in the denominator if the team actually did file
                // a form that day — in which case it falls through to
                // 'completed' below.
                $isStopDay = ($d === $endDay)
                    && in_array($endReason, ['discharge', 'pd', 'sw', 'sae'], true);

                if ($multiSession)
                {
                    $completedSessions = $dayComplete[$d] ?? 0;
                    if ($completedSessions >= $sessionsRequired)
                        $daySummary[$d] = 'completed';
                    elseif ($completedSessions > 0)
                        $daySummary[$d] = 'partial';
                    elseif ($isStopDay)
                        $daySummary[$d] = 'not_due';   // stop day, no sessions filed
                    else
                        $daySummary[$d] = 'missing';
                }
                else
                {
                    if (!empty($dayComplete[$d]))
                        $daySummary[$d] = 'completed';
                    elseif ($isStopDay)
                        $daySummary[$d] = 'not_due';   // stop day, no form filed
                    else
                        $daySummary[$d] = 'missing';
                }
            }

            // ── Compute counts ────────────────────────────────────────────
            $completedDays = $missingDays = $partialDays = $blockedDays = [];

            foreach ($daySummary as $d => $status)
            {
                if ($status === 'completed') $completedDays[] = $d;
                if ($status === 'missing')   $missingDays[]   = $d;
                if ($status === 'partial')   $partialDays[]   = $d;
                if ($status === 'blocked')   $blockedDays[]   = $d;
            }

            // For multi-session forms (emolliation): count is SESSION-based.
            //
            //   expected   = sum of $dayExpected[d] across all due days.
            //                Day 0: 1 (day0 form) + however many daily_interventionemolliation_form
            //                       instances were entered on day0_arm_1 (0, 1 or 2 depending on
            //                       time of enrollment — not predictable, so we count what exists).
            //                Days 1-28: count of repeating instances seen (up to sessionsPerDay).
            //                For days with no instances seen but still due: use sessionsPerDay.
            //
            //   completed  = simple count of _complete = '2' instances across all due days.
            //
            //   incomplete_instances = "DX#Y" for every slot not yet done.
            if ($multiSession)
            {
                $incompleteInstances = [];

                // ── Expected sessions — fully delegated to EmolliationSessionCalculator ──
                // day0Override = 0 for Day-1+ enrollees (no Day 0 module expected).
                // For Day-0 enrollees, null defers to fi_emol_perid as usual.
                $day0Override     = ($isEmolliationForm && $enrollmentDay > 0) ? 0 : null;
                $day0Expected     = EmolliationSessionCalculator::day0Sessions($fiEmolPerid);
                if ($day0Override !== null) $day0Expected = $day0Override;

                $expectedSessions = EmolliationSessionCalculator::sessionsFromEndDay(
                    $endDay, $fiEmolPerid, 0, '', false, $day0Override
                );

                // ── Day 0 repeating instances done ───────────────────────
                $day0RepDone     = $dayComplete[0] ?? 0;
                $day0RepExpected = max(0, $day0Expected - 1);   // MOR→2, AFT→1, EVE→0; Day-1 enrollee→max(0,-1)=0

                // ── Completed = done within expected slots only ───────────
                $completedSessions  = min($day0FormDone, 1);
                $completedSessions += min($day0RepDone, $day0RepExpected);
                for ($d = 1; $d <= $endDay; $d++) 
                {
                    $completedSessions += min($dayComplete[$d] ?? 0, $this->config->sessionsPerDay);
                }

                // ── Incomplete instance list ──────────────────────────────
                // Day-0 module slot — only listed when the Day-0 module IS expected
                // (i.e. baby was enrolled on Day 0). Day-1+ enrollees never have a
                // day0_interventionemolliation_module to fill.
                if ($enrollmentDay === 0 && $day0FormDone < 1)
                {
                    $incompleteInstances[] = 'D0#day0form';
                }
                // daily_interventionemolliation_form slots on day0_arm_1
                if ($enrollmentDay === 0 && $day0RepExpected > 0 && $day0RepDone < $day0RepExpected)
                {
                    for ($inst = $day0RepDone + 1; $inst <= $day0RepExpected; $inst++)
                    {
                        $incompleteInstances[] = "D0#rep" . $inst;
                    }
                }
                // Days 1 to endDay: sessionsPerDay slots each
                for ($d = 1; $d <= $endDay; $d++) 
                {
                    $done     = $dayComplete[$d] ?? 0;
                    $required = $this->config->sessionsPerDay;
                    if ($done < $required) 
                    {
                        for ($inst = $done + 1; $inst <= $required; $inst++) 
                        {
                            $incompleteInstances[] = "D{$d}#" . $inst;
                        }
                    }
                }

                $expected  = $expectedSessions;
                $completed = $completedSessions;
                $missing   = max(0, $expected - $completed);
                $pctRaw    = $expected > 0 ? round($completed / $expected * 100, 1) : null;
                $pct       = $pctRaw !== null ? min(100.0, $pctRaw) : null;
                $pctActual = $pctRaw;

                $participants[$id] = [
                    'record_id'               => $id,
                    'site'                    => $site            ?? 'Unknown',
                    'arm'                     => $arm             ?? 'Unknown',
                    'dob'                     => $dob->format('Y-m-d'),
                    'enrollment_date'         => $enrollmentDate?->format('Y-m-d'),
                    'end_day'                 => $endDay,
                    'end_day_display'         => (!empty($this->config->day0FormName)) ? ($endDay + 1) : $endDay,
                    'end_reason'              => $endReason,
                    'stop_date'               => $this->resolveStopDate($stopDates, $endReason, $dob, $endDay),
                    'expected'                => $expected,
                    'completed'               => $completed,
                    'missing_count'           => $missing,
                    'partial'                 => count($partialDays),
                    'blocked'                 => count($blockedDays),
                    'missing_days'            => $missingDays,
                    'partial_days'            => $partialDays,
                    'forms_moved_by_date'     => $formsMovedByDate,
                    'blocked_days'            => $blockedDays,
                    'completed_days'          => $completedDays,
                    'incomplete_instances'    => $incompleteInstances,
                    'day_summary'             => $daySummary,
                    'session_counts'          => $dayComplete,
                    'pct'                     => $pct,
                    'pct_actual'              => $pctActual,
                ];
            }
            else
            {
                // Single-session: counts are day-based as before
                $expected  = count($completedDays) + count($partialDays) + count($missingDays);
                $completed = count($completedDays);
                $pctRaw    = $expected > 0 ? round($completed / $expected * 100, 1) : null;
                $pct       = $pctRaw !== null ? min(100.0, $pctRaw) : null;
                $pctActual = $pctRaw;

                $participants[$id] = [
                    'record_id'       => $id,
                    'site'            => $site           ?? 'Unknown',
                    'arm'             => $arm            ?? 'Unknown',
                    'dob'             => $dob->format('Y-m-d'),
                    'enrollment_date' => $enrollmentDate?->format('Y-m-d'),
                    'end_day'         => $endDay,
                    'end_day_display' => (!empty($this->config->day0FormName)) ? ($endDay + 1) : $endDay,
                    'end_reason'      => $endReason,
                    'stop_date'       => $this->resolveStopDate($stopDates, $endReason, $dob, $endDay),
                    'expected'        => $expected,
                    'completed'       => $completed,
                    'partial'         => count($partialDays),
                    'blocked'         => count($blockedDays),
                    'missing_days'    => $missingDays,
                    'partial_days'    => $partialDays,
                    'forms_moved_by_date' => $formsMovedByDate,
                    'blocked_days'    => $blockedDays,
                    'completed_days'  => $completedDays,
                    'day_summary'     => $daySummary,
                    'session_counts'  => $multiSession ? $dayComplete : [],
                    'pct'             => $pct,
                    'pct_actual'      => $pctActual,
                ];
            }
        }

        uasort($participants, fn($a, $b) =>
            [$a['site'], $a['record_id']] <=> [$b['site'], $b['record_id']]
        );

        return [
            'participants'   => $participants,
            'site_summary'   => $this->buildSiteSummary($participants),
            'day_frequency'  => $this->buildDayFrequency($participants),
            'form_name'      => $this->config->formName,
            'max_days'       => $this->config->maxDays,
            'sessions_per_day' => $this->config->sessionsPerDay,
            'has_blocked'    => !empty($dailyParents),   // surfaced as "Parent Not Done"
            'has_partial'    => $multiSession,
        ];
    }

    // ── Site summary ──────────────────────────────────────────────────────────

    private function buildSiteSummary(array $participants): array
    {
        $sites = [];
        foreach ($participants as $p)
        {
            $s = $p['site'];

            if (!isset($sites[$s]))
                $sites[$s] = [
                    'site' => $s, 'count' => 0, 'expected' => 0,
                    'completed' => 0, 'partial' => 0, 'blocked' => 0,
                    'pct' => null,
                    'end_reasons' => array_fill_keys(
                        ['day28','discharge','deviation','withdrawal','sae','ongoing'], 0),
                ];

            $sites[$s]['count']++;
            $sites[$s]['expected']  += $p['expected'];
            $sites[$s]['completed'] += $p['completed'];
            $sites[$s]['partial']   += $p['partial'];
            $sites[$s]['blocked']   += $p['blocked'];
            $sites[$s]['end_reasons'][$p['end_reason']]++;
        }

        foreach ($sites as &$s) 
        {
            $raw = $s['expected'] > 0
                ? round($s['completed'] / $s['expected'] * 100, 1) : null;
            $s['pct_actual'] = $raw;                              // may exceed 100%
            $s['pct']        = $raw !== null ? min(100.0, $raw) : null; // capped at 100%
        }
        unset($s);  // break the reference before the next loop

        $t = [
            'site' => 'TOTAL', 'count' => 0, 'expected' => 0,
            'completed' => 0, 'partial' => 0, 'blocked' => 0, 'pct' => null,
            'end_reasons' => array_fill_keys(
                ['day28','discharge','deviation','withdrawal','sae','ongoing'], 0),
        ];

        foreach ($sites as $s) 
        {
            $t['count']     += $s['count'];
            $t['expected']  += $s['expected'];
            $t['completed'] += $s['completed'];
            $t['partial']   += $s['partial'];
            $t['blocked']   += $s['blocked'];
            foreach ($s['end_reasons'] as $r => $n) $t['end_reasons'][$r] += $n;
        }
        $rawTotal = $t['expected'] > 0
            ? round($t['completed'] / $t['expected'] * 100, 1) : null;
        $t['pct_actual'] = $rawTotal;
        $t['pct']        = $rawTotal !== null ? min(100.0, $rawTotal) : null;

        $sites['TOTAL'] = $t;
        return $sites;
    }

    // ── Day frequency ─────────────────────────────────────────────────────────

    private function buildDayFrequency(array $participants): array
    {
        $freq = [];
        // Day 0 is included when a day0_form_name is configured (emolliation)
        $startDay = empty($this->config->day0FormName) ? 1 : 0;
        for ($d = $startDay; $d <= $this->config->maxDays; $d++)
            $freq[$d] = ['day' => $d, 'due' => 0, 'completed' => 0,
                         'partial' => 0, 'blocked' => 0, 'missing' => 0, 'pct' => null];

        foreach ($participants as $p)
        {
            foreach ($p['day_summary'] as $d => $status)
            {
                if ($status === 'not_due') continue;
                if ($status === 'blocked')   { $freq[$d]['blocked']++; continue; }
                $freq[$d]['due']++;
                if ($status === 'completed') $freq[$d]['completed']++;
                elseif ($status === 'partial') $freq[$d]['partial']++;
                else                         $freq[$d]['missing']++;
            }
        }

        foreach ($freq as &$f) 
        {
            $raw = $f['due'] > 0
                ? round($f['completed'] / $f['due'] * 100, 1) : null;
            $f['pct_actual'] = $raw;
            $f['pct']        = $raw !== null ? min(100.0, $raw) : null;
        }

        return $freq;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function parseDate(string $raw): ?\DateTime
    {
        if (empty($raw)) return null;
        try { $dt = new \DateTime($raw); $dt->setTime(0,0,0); return $dt; }
        catch (\Exception) { return null; }
    }

    /**
     * Resolve the human-readable stop date for display in participant table.
     * For discharge/deviation/withdrawal/sae returns the actual form datetime.
     * For day28 returns DOB + endDay.
     * For ongoing returns null.
     */
    private function resolveStopDate(array $stopDates, string $endReason, \DateTime $dob, int $endDay): ?string
    {
        if (isset($stopDates[$endReason])) 
        {
            return $stopDates[$endReason]->format('Y-m-d');
        }
        if ($endReason === 'day28') {
            $d28 = (clone $dob)->modify("+{$endDay} days");
            return $d28->format('Y-m-d');
        }
        return null;   // 'ongoing' — no stop date yet
    }
}

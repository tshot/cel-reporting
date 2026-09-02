<?php

namespace CEL\Projects\Emollient\Support;

/**
 * EmolliationSessionCalculator
 *
 * Single source of truth for emolliation sessions-due logic.
 *
 * Sessions Due formula:
 *
 *   Ongoing / Day 28, last day < today:
 *     sessionsDue = (endDay × 3) + day0Sessions        — all full days
 *
 *   Ongoing / Day 28, last day = today, no forms done yet:
 *     sessionsDue = ((endDay-1) × 3) + day0Sessions    — today not counted
 *
 *   Ongoing / Day 28, last day = today, forms done today:
 *     sessionsDue = ((endDay-1) × 3) + max(1, formsOnLastDay) + day0Sessions
 *
 *   Discharge:
 *     sessionsDue = ((endDay-1) × 3) + max(1, formsOnLastDay) + day0Sessions
 *
 *   Withdrawal / SAE / PD:
 *     sessionsDue = ((endDay-1) × 3) + max(0, formsOnLastDay) + day0Sessions
 *
 * Attempted = Day 0 module (1 if fi_emol_perid filled)
 *           + all daily_interventionemolliation_form instances (all days, no exclusions)
 *
 * Given     = Day 0 module (1 if fi_emol_perid filled)
 *           + daily forms where int_emoliate_baby = 'Y' (all days, no exclusions)
 *
 * Day 0 sessions by fi_emol_perid:
 *   MOR → 3  (Day 0 module + 2 repeating)
 *   AFT → 2  (Day 0 module + 1 repeating)
 *   EVE → 1  (Day 0 module + 0 repeating)
 *   ''  → 1  (safe minimum)
 */
class EmolliationSessionCalculator
{
    public const SESSIONS_PER_DAY = 3;
    public const MAX_DAYS         = 28;

    // =========================================================================
    // endDay computation
    // =========================================================================

    /**
     * Compute endDay and stop metadata.
     *
     * Returns:
     *   'endDay'      => int    — last day (no -1 applied, always actual stop/last day)
     *   'stopReason'  => string — earliest stop reason or '' if ongoing
     *   'lastDayIsToday' => bool — true when last day equals today (ongoing or Day 28 today)
     *
     * @param  \DateTime  $refDate    enr_datetime (or DOB fallback)
     * @param  array      $stopDates  [reason => \DateTime]
     */
    public function computeEndDay(\DateTime $refDate, array $stopDates): array
    {
        $today       = new \DateTime('today');
        $daysElapsed = (int)$today->diff($refDate)->days;
        $endDay      = min(self::MAX_DAYS, $daysElapsed);
        $stopReason  = '';

        // Find earliest stop event
        $earliestDay    = null;
        $earliestReason = '';
        foreach ($stopDates as $reason => $dt) 
        {
            if (!($dt instanceof \DateTime)) continue;
            $sd = (int)$dt->diff($refDate)->days;
            if ($earliestDay === null || $sd < $earliestDay) 
            {
                $earliestDay    = $sd;
                $earliestReason = $reason;
            }
        }

        if ($earliestDay !== null && $earliestDay < $endDay) 
        {
            $endDay     = $earliestDay;
            $stopReason = $earliestReason;
        }

        // Last day is today when ongoing (no stop) and daysElapsed < 28,
        // or when baby hit Day 28 today (daysElapsed = 28)
        $lastDayIsToday = ($stopReason === '' && $daysElapsed <= self::MAX_DAYS
                           && $endDay === $daysElapsed);

        return [
            'endDay'         => $endDay,
            'stopReason'     => $stopReason,
            'lastDayIsToday' => $lastDayIsToday,
        ];
    }

    // =========================================================================
    // Sessions Due formula
    // =========================================================================

    /**
     * Compute sessions due.
     *
     * @param  int     $endDay           Last day (actual, no -1)
     * @param  string  $fiEmolPerid      'MOR'|'AFT'|'EVE'|''
     * @param  int     $formsOnLastDay   Actual forms recorded on endDay
     * @param  string  $stopReason       '' | 'discharge' | 'withdrawal' | 'deviation' | 'sae'
     * @param  bool    $lastDayIsToday   True when ongoing and last day = today
     * @param  int|null $day0Override    When non-null, overrides the fi_emol_perid→day0Sessions
     *                                   derivation. Pass 0 for Day 1+ enrollees (no Day 0 module).
     */
    public static function sessionsFromEndDay(
        int    $endDay,
        string $fiEmolPerid,
        int    $formsOnLastDay = 0,
        string $stopReason     = '',
        bool   $lastDayIsToday = false,
        ?int   $day0Override   = null
    ): int 
    {
        $day0 = ($day0Override !== null)
            ? $day0Override
            : self::day0Sessions($fiEmolPerid);

        // Ongoing / Day 28 and last day is NOT today — all full days
        if ($stopReason === '' && !$lastDayIsToday) 
        {
            return ($endDay * self::SESSIONS_PER_DAY) + $day0;
        }

        // Ongoing and last day IS today but no forms done yet — count up to yesterday
        // DC may not have started today's sessions yet, so today is not counted.
        if ($stopReason === '' && $lastDayIsToday && $formsOnLastDay === 0) 
        {
            return (($endDay - 1) * self::SESSIONS_PER_DAY) + $day0;
        }

        // All other partial last day cases (Discharge, Withdrawal/SAE/PD, Ongoing today with forms)
        $isHardStop   = in_array($stopReason, ['withdrawal', 'deviation', 'sae'], true);
        $lastDayFloor = $isHardStop ? 0 : 1;

        return (($endDay - 1) * self::SESSIONS_PER_DAY)
             + max($lastDayFloor, $formsOnLastDay)
             + $day0;
    }

    // =========================================================================
    // Day 0 lookup
    // =========================================================================

    /**
     * Sessions due on Day 0 based on time of enrollment.
     *
     * MOR (morning)   → 3  (Day 0 module + 2 repeating)
     * AFT (afternoon) → 2  (Day 0 module + 1 repeating)
     * EVE (evening)   → 1  (Day 0 module + 0 repeating)
     * blank/unknown   → 1  (safe minimum)
     */
    public static function day0Sessions(string $fiEmolPerid): int
    {
        return match(strtoupper(trim($fiEmolPerid)))
        {
            'MOR'   => 3,
            'AFT'   => 2,
            'EVE'   => 1,
            default => 1,
        };
    }
}

<?php

namespace CEL\Shared\Domain\Aggregator;

/**
 * WeeklyAggregator
 *
 * Groups consented enrollments by site and week within the date range.
 * Weeks are labelled W1, W2 … relative to date_from.
 *
 * Incomplete weeks at the end of the range are silently excluded so the
 * chart only ever shows full 7-day periods. Each label carries both the
 * start and end date of the week so it is self-contained:
 *
 *   W1 1–7 Mar    W2 8–14 Mar    W3 15–21 Mar    W4 22–28 Mar
 *   (a 29–31 Mar partial week is dropped entirely)
 *
 * All aggregation logic lives in ChartAggregator.
 */
class WeeklyAggregator extends ChartAggregator
{
    protected function periodsKey(): string { return 'weeks'; }

    protected function buildPeriods(): array
    {
        $periods = [];
        $cursor  = clone $this->from;
        $wNum    = 1;

        while ($cursor <= $this->to)
        {
            $start      = clone $cursor;
            $naturalEnd = (clone $cursor)->modify('+6 days')->setTime(23, 59, 59);
            $isPartial  = $naturalEnd > $this->to;

            // Skip incomplete weeks — a partial week at the end of the date
            // range is excluded so the chart only shows full 7-day periods.
            if ($isPartial)
            {
                break;
            }

            // Full week: show both start and end dates so the label is
            // self-contained, e.g. "W1\n1–7 Mar" instead of just "W1\n1 Mar".
            $label = 'W' . $wNum . "\n"
                   . $start->format('d') . '–' . $naturalEnd->format('d M');

            $periods[] = [
                'label' => $label,
                'start' => $start,
                'end'   => $naturalEnd,
            ];

            $cursor->modify('+7 days');
            $wNum++;
        }

        return $periods;
    }
}

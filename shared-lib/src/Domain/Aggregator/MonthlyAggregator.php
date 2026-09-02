<?php

namespace CEL\Shared\Domain\Aggregator;

/**
 * MonthlyAggregator
 *
 * Groups consented enrollments by site and calendar month within the date range.
 * Months are labelled "Jan 2026", "Feb 2026", etc.
 * All aggregation logic lives in EnrollmentChartAggregator.
 */
class MonthlyAggregator extends ChartAggregator
{
    protected function periodsKey(): string { return 'months'; }

    protected function buildPeriods(): array
    {
        $periods = [];
        $cursor  = \DateTime::createFromFormat('Y-m-d', $this->from->format('Y-m-01'))
                            ->setTime(0, 0, 0);

        while ($cursor <= $this->to)
        {
            $start = clone $cursor;
            $end   = (clone $cursor)->modify('last day of this month')->setTime(23, 59, 59);
            if ($end > $this->to) $end = clone $this->to;

            $periods[] = [
                'label' => $start->format('M Y'),
                'start' => $start,
                'end'   => $end,
            ];

            $cursor->modify('+1 month');
        }

        return $periods;
    }
}

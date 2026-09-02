<?php

namespace CEL\Shared\Domain\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * Shared-lib AggregatorFactory
 *
 * Handles only truly generic aggregator types reusable across any project.
 * Project-specific types (eligibility, dashboard, etc.) are handled by
 * each project's own AggregatorFactory.
 */
class AggregatorFactory
{
    public static function create(
        string $type,
        string $primaryKey,
        array  $config
    ): AggregatorInterface
    {
        return match ($type)
        {
            'monthly_enrollment_chart' =>
                new MonthlyAggregator(
                    $primaryKey,
                    $config['date_from']            ?? '',
                    $config['date_to']              ?? '',
                    $config['site_label_overrides'] ?? []
                ),

            'weekly_enrollment' =>
                new WeeklyAggregator(
                    $primaryKey,
                    $config['date_from']            ?? '',
                    $config['date_to']              ?? '',
                    $config['site_label_overrides'] ?? []
                ),

            default =>
                throw new \InvalidArgumentException(
                    "Unknown aggregator type: {$type}"
                )
        };
    }
}

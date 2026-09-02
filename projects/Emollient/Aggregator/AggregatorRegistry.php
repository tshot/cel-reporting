<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * AggregatorRegistry
 *
 * OCP-compliant replacement for the raw match() in AggregatorFactory.
 *
 * Problem with raw match():
 *   Every new aggregator type requires editing AggregatorFactory — a direct
 *   violation of the Open/Closed Principle. The factory is not closed for
 *   modification.
 *
 * Solution — registry of factory closures:
 *   Each type is a callable that receives ($primaryKey, $config) and returns
 *   an AggregatorInterface instance. New types are registered without touching
 *   the resolution logic.
 *
 * Usage:
 *   // Register a new type (done once, e.g. in a bootstrap or the factory itself)
 *   AggregatorRegistry::register('my_type', function(string $pk, array $cfg) {
 *       return new MyAggregator($pk, $cfg['date_from'] ?? null);
 *   });
 *
 *   // Resolve
 *   $aggregator = AggregatorRegistry::make('my_type', $primaryKey, $config);
 *   // Returns null if type not found (engine falls through to shared-lib)
 */
class AggregatorRegistry
{
    /** @var array<string, callable> type => factory closure */
    private static array $registry = [];
    private static bool  $booted   = false;

    /**
     * Register a factory closure for an aggregator type.
     *
     * @param string   $type    The aggregator type string (matches reports.php 'aggregator' key)
     * @param callable $factory fn(string $primaryKey, array $config): AggregatorInterface
     */
    public static function register(string $type, callable $factory): void
    {
        self::$registry[$type] = $factory;
    }

    /**
     * Resolve and instantiate an aggregator by type.
     *
     * Returns null for unknown types so the engine falls through to shared-lib.
     */
    public static function make(
        string $type,
        string $primaryKey,
        array  $config
    ): ?AggregatorInterface {
        self::boot();
        if (!isset(self::$registry[$type])) return null;
        return (self::$registry[$type])($primaryKey, $config);
    }

    /** All registered type names. */
    public static function types(): array
    {
        self::boot();
        return array_keys(self::$registry);
    }

    /**
     * Register all Emollient aggregator types.
     * Called once lazily on first make() call.
     */
    private static function boot(): void
    {
        if (self::$booted) return;
        self::$booted = true;

        self::register('monthly_enrollment', fn($pk, $cfg) =>
            new MonthlyDashboardAggregator(
                $pk,
                $cfg['date_from'] ?? '',
                $cfg['date_to']   ?? '',
                is_file(__DIR__ . '/../site_targets.php')
                    ? require __DIR__ . '/../site_targets.php'
                    : []
            )
        );

        self::register('enrollment_diagnostic', fn($pk, $cfg) =>
            new EnrollmentDiagnosticAggregator(
                $pk,
                $cfg['date_from'] ?? '',
                $cfg['date_to']   ?? ''
            )
        );

        self::register('eligibility', fn($pk, $cfg) =>
            new EligibilityAggregator(
                $pk,
                $cfg['date_from']   ?? '',
                $cfg['date_to']     ?? '',
                $cfg['site_filter'] ?? []
            )
        );

        self::register('eligibility_diagnostic', fn($pk, $cfg) =>
            new EligibilityDiagnosticAggregator(
                $pk,
                $cfg['date_from'] ?? '',
                $cfg['date_to']   ?? ''
            )
        );

        self::register('form_completion', fn($pk, $cfg) =>
            new FormCompletionAggregator($pk, FormCompletionConfig::fromArray($cfg))
        );

        self::register('one_time_form_completion', fn($pk, $cfg) =>
            new OneTimeFormCompletionAggregator($pk, OneTimeFormCompletionConfig::fromArray($cfg))
        );

        self::register('length_of_stay', fn($pk, $cfg) =>
            new LengthOfStayAggregator($pk, $cfg['site_filter'] ?? [])
        );

        self::register('weight_analysis', fn($pk, $cfg) =>
            new WeightAnalysisAggregator($pk, $cfg['site_filter'] ?? [])
        );

        self::register('emolliation_coverage', fn($pk, $cfg) =>
            new EmolliationCoverageAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['dc_filter']   ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        // Dashboard variant: Day-0 counts as a flat 1, plus No-Emolliation
        // reason breakdown. Same filters as emolliation_coverage.
        self::register('emolliation_coverage_dashboard', fn($pk, $cfg) =>
            new EmolliationCoverageDashboardAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['dc_filter']   ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        self::register('data_collector', fn($pk, $cfg) =>
            new DataCollectorAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null,
                (int)($cfg['wt_bin'] ?? 50)
            )
        );

        self::register('sepsis_screening', fn($pk, $cfg) =>
            new SepsisScreeningAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        self::register('discharge_completion', fn($pk, $cfg) =>
            new DischargeCompletionAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        self::register('sao_bottle', fn($pk, $cfg) =>
            new SAOBottleAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        self::register('day29_followup', fn($pk, $cfg) =>
            new Day29FollowUpAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        self::register('weekly_meeting', fn($pk, $cfg) =>
            new WeeklyMeetingAggregator(
                $pk,
                $cfg['site_filter'] ?? [],
                $cfg['date_from']   ?? null,
                $cfg['date_to']     ?? null
            )
        );

        self::register('clinical_outcomes', fn($pk, $cfg) =>
            new ClinicalOutcomesAggregator(
                $pk,
                $cfg['site_filter'] ?? []
            )
        );
    }
}

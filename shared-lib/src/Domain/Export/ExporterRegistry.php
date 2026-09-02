<?php

namespace CEL\Shared\Domain\Export;

/**
 * ExporterRegistry
 *
 * Maps exporter alias keys (declared in reports.php under 'exporter') to
 * concrete exporter class names. Replaces the str_contains / str_starts_with
 * cascade in ReportController::resolveHtmlExporter().
 *
 * H1 fix: ReportController now reads $reportDef['exporter'] and calls
 * ExporterRegistry::make() — no report-name string matching, no engine
 * changes needed when a new project adds a new exporter.
 *
 * Adding a new exporter:
 *   1. Create the exporter class implementing ExporterInterface.
 *   2. Add an entry to the $map below.
 *   3. Declare 'exporter' => 'your_key' in reports.php.
 *   Done — ReportController needs no changes.
 *
 * Each exporter constructor must accept these named parameters
 * (all with defaults so they are optional):
 *   bool   $inline      = false
 *   string $cssPath     = ''
 *   string $toolbar     = ''
 *   string $defaultView = 'stacked'   (only used by chart exporters)
 */
class ExporterRegistry
{
    /**
     * Map of alias → fully qualified class name.
     * All classes must implement ExporterInterface.
     */
    private static array $map = [
        'eligibility'          => \CEL\Projects\Emollient\Export\EligibilityHtmlExporter::class,
        'form_completion'      => \CEL\Projects\Emollient\Export\FormCompletionHtmlExporter::class,
        'one_time_completion'  => \CEL\Projects\Emollient\Export\OneTimeFormCompletionHtmlExporter::class,
        'length_of_stay'       => \CEL\Projects\Emollient\Export\LengthOfStayHtmlExporter::class,
        'weight_analysis'      => \CEL\Projects\Emollient\Export\WeightAnalysisHtmlExporter::class,
        'emol_coverage'        => \CEL\Projects\Emollient\Export\EmolliationCoverageHtmlExporter::class,
        'emol_coverage_dashboard' => \CEL\Projects\Emollient\Export\EmolliationCoverageDashboardHtmlExporter::class,
        'data_collector'       => \CEL\Projects\Emollient\Export\DataCollectorHtmlExporter::class,
        'sepsis_screening'     => \CEL\Projects\Emollient\Export\SepsisScreeningHtmlExporter::class,
        'discharge_completion' => \CEL\Projects\Emollient\Export\DischargeCompletionHtmlExporter::class,
        'sao_bottle'           => \CEL\Projects\Emollient\Export\SAOBottleHtmlExporter::class,
        'day29_followup'       => \CEL\Projects\Emollient\Export\Day29FollowUpHtmlExporter::class,
        'weekly_meeting'       => \CEL\Projects\Emollient\Export\WeeklyMeetingHtmlExporter::class,
        'weekly_meeting_excel'  => \CEL\Projects\Emollient\Export\WeeklyMeetingExcelExporter::class,
        'weekly_meeting_csv'    => \CEL\Projects\Emollient\Export\WeeklyMeetingCsvExporter::class,
        'form_completion_csv'   => \CEL\Projects\Emollient\Export\FormCompletionIncompleteCsvExporter::class,
        'missing_daily_monitoring_csv' => \CEL\Projects\Emollient\Export\MissingDailyMonitoringCsvExporter::class,
        'one_time_completion_csv' => \CEL\Projects\Emollient\Export\OneTimeFormCompletionIncompleteCsvExporter::class,
        'form_completion_excel' => \CEL\Projects\Emollient\Export\FormCompletionIncompleteExcelExporter::class,
        'one_time_completion_excel' => \CEL\Projects\Emollient\Export\OneTimeFormCompletionIncompleteExcelExporter::class,
        'form_completion_participants_csv'   => \CEL\Projects\Emollient\Export\FormCompletionParticipantsCsvExporter::class,
        'one_time_completion_participants_csv' => \CEL\Projects\Emollient\Export\OneTimeFormCompletionParticipantsCsvExporter::class,
        'clinical_outcomes'    => \CEL\Projects\Emollient\Export\ClinicalOutcomesHtmlExporter::class,
        'monthly_dashboard'    => \CEL\Shared\Domain\Export\MonthlyDashboardHtmlExporter::class,
        'monthly_chart'        => \CEL\Shared\Domain\Export\MonthlyChartExporter::class,
        'weekly_chart'         => \CEL\Shared\Domain\Export\WeeklyChartExporter::class,
        'csv'                  => \CEL\Shared\Domain\Export\CsvExporter::class,
    ];

    /**
     * Default CSS path per alias.
     * Used when the caller does not supply a cssPath (the common case).
     * Keeps CSS coupling out of ReportController and into the registry
     * where it belongs alongside the class mapping.
     */
    private static array $cssDefaults = [
        'eligibility'         => '/assets/css/emollient_eligible.css',
        'form_completion'     => '/assets/css/emollient_eligible.css',
        'one_time_completion' => '/assets/css/emollient_eligible.css',
        'length_of_stay'      => '/assets/css/emollient_eligible.css',
        'weight_analysis'     => '/assets/css/emollient_eligible.css',
        'clinical_outcomes'   => '/assets/css/emollient_eligible.css',
        'emol_coverage'       => '/assets/css/emollient_eligible.css',
        'emol_coverage_dashboard' => '/assets/css/emollient_eligible.css',
        'monthly_dashboard'   => '/assets/css/emollient_eligible.css',
        'monthly_chart'       => '/assets/css/weekly_enrollment.css',
        'weekly_chart'        => '/assets/css/weekly_enrollment.css',
    ];

    /**
     * Which aliases accept a $defaultView constructor parameter.
     * Eliminates the ReflectionClass call in make() — the answer is
     * static data that does not change at runtime.
     *
     * When adding a new exporter that accepts $defaultView, add its
     * alias here with true. All others default to false via ?? false.
     */
    private static array $acceptsDefaultView = [
        'monthly_chart' => true,
    ];

    /**
     * Instantiate an exporter by alias.
     *
     * @throws \InvalidArgumentException for unknown alias
     */
    public static function make(
        string $alias,
        bool   $inline      = false,
        string $cssPath     = '',
        string $toolbar     = '',
        string $defaultView = 'stacked'
    ): ExporterInterface 
    {
        if (!isset(self::$map[$alias])) 
        {
            throw new \InvalidArgumentException(
                "Unknown exporter alias '{$alias}'. "
                . "Known aliases: " . implode(', ', array_keys(self::$map)) . "."
            );
        }

        $class = self::$map[$alias];

        // Resolve CSS path — caller-supplied takes priority,
        // otherwise use the per-alias default from $cssDefaults.
        $resolvedCss = $cssPath !== ''
            ? $cssPath
            : (self::$cssDefaults[$alias] ?? '');

        $args = [
            'inline'  => $inline,
            'cssPath' => $resolvedCss,
            'toolbar' => $toolbar,
        ];

        // Only pass defaultView to exporters that declare it.
        // Checked via a static flag — no ReflectionClass overhead.
        if (self::$acceptsDefaultView[$alias] ?? false) {
            $args['defaultView'] = $defaultView;
        }

        return new $class(...$args);
    }

    /**
     * Register a new exporter alias at runtime.
     * Useful for plugins or project-level extensions without editing this file.
     *
     * @throws \InvalidArgumentException if class does not implement ExporterInterface
     */
    public static function register(
        string $alias,
        string $className,
        string $cssDefault         = '',
        bool   $acceptsDefaultView = false
    ): void 
    {
        if (!is_subclass_of($className, ExporterInterface::class)) 
        {
            throw new \InvalidArgumentException(
                "{$className} must implement ExporterInterface."
            );
        }
        self::$map[$alias]                = $className;
        self::$cssDefaults[$alias]        = $cssDefault;
        self::$acceptsDefaultView[$alias] = $acceptsDefaultView;
    }

    /** Return all registered aliases (useful for validation). */
    public static function aliases(): array
    {
        return array_keys(self::$map);
    }
}

<?php

namespace CEL\Projects\Emollient\Facade;

use CEL\Shared\Domain\Report\ProjectReportFacadeInterface;
use CEL\Shared\Domain\Aggregator\AggregatorInterface;
use CEL\Shared\Domain\Aggregator\SecondPassAggregatorInterface;
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

/**
 * Emollient Project Report Facade
 *
 * Handles all post-aggregation enrichment specific to the Emollient project:
 *
 *   1. site_labels  — code → display name map for column headers / row labels
 *   2. label_map    — REDCap option code → human label map for demographics
 *   3. baseline_fetch — second REDCap stream for baseline_arm_1 fields (GA etc.)
 *
 * The engine ReportFacade does not need to know about any of these.
 */
class ReportFacade implements ProjectReportFacadeInterface
{
    /** Absolute path to this project's root directory */
    private string $projectRoot;

    public function __construct(string $projectRoot)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
    }

    public function preAggregate(array $definition): array
    {
        // Inject site_label_overrides so chart aggregators (WeeklyAggregator,
        // MonthlyAggregator) receive site labels via their constructor.
        // This must happen before AggregatorFactory::create() is called.
        $siteLabels = $this->loadSiteLabels($definition);

        if (!empty($siteLabels))
        {
            $definition['site_label_overrides'] = $siteLabels;
        }

        return $definition;
    }

    public function postAggregate(
        array                $result,
        array                $definition,
        RedcapApiClient      $client,
        AggregatorInterface  $aggregator
    ): array
    {
        // ── 1. Site labels ─────────────────────────────────────────────────
        // Load the code → display name map.
        // Also back-fill into $definition so chart aggregator constructors
        // that read $config['site_label_overrides'] continue to work.
        $siteLabels = $this->loadSiteLabels($definition);

        if (!empty($siteLabels))
        {
            $result['site_labels'] = $siteLabels;
        }

        // ── 2. Label map ───────────────────────────────────────────────────
        // REDCap option code → human label, used by demographics renderer.
        $labelMap = $this->loadLabelMap($definition);

        if (!empty($labelMap))
        {
            $result['label_map'] = $labelMap;
        }

        // ── 3. Baseline fetch ──────────────────────────────────────────────
        // Second REDCap stream for fields that live on a different event
        // (e.g. base_calc_ga_wks on baseline_arm_1).
        // Only runs when the report definition includes 'baseline_fetch'.
        if (!empty($definition['baseline_fetch']))
        {
            $result = $this->runBaselineFetch($result, $definition, $client, $aggregator);
        }

        // ── 4. Reorder site keys to match site_labels.php sequence ──────
        // MonthlyDashboard result is keyed by site code in insertion order.
        // Reorder here so every report shows sites in the same sequence.
        // Skip completion reports which have a 'participants' key instead.
        if (!empty($siteLabels) && !isset($result['participants']))
        {
            $result = $this->reorderSiteKeys($result, $siteLabels);
        }

        return $result;
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function loadSiteLabels(array $definition): array
    {
        // Explicit path in definition takes precedence;
        // fall back to the project's canonical site_labels.php.
        $path = $definition['site_labels_path']
             ?? $this->projectRoot . '/site_labels.php';

        return (file_exists($path)) ? (require $path) : [];
    }

    private function loadLabelMap(array $definition): array
    {
        // Only load if the report explicitly opts in via label_map_path.
        // Reports that don't need label resolution (e.g. enrollment charts)
        // simply omit this key and pay no loading cost.
        if (empty($definition['label_map_path'])) return [];

        $path = $definition['label_map_path'];
        return file_exists($path) ? (require $path) : [];
    }

    private function runBaselineFetch(
        array                $result,
        array                $definition,
        RedcapApiClient      $client,
        AggregatorInterface  $aggregator
    ): array
    {
        $bf = $definition['baseline_fetch'];

        $baseStream = $client->stream(
            $bf['fields'] ?? [],
            $bf['forms']  ?? [],
            $bf['events'] ?? []
        );

        if ($aggregator instanceof SecondPassAggregatorInterface)
        {
            $aggregator->aggregateBaseline($baseStream, $result);
        }

        return $result;
    }
    // ── Reorder top-level site-keyed result by site_labels sequence ───────────

    private function reorderSiteKeys(array $result, array $siteLabels): array
    {
        // Identify which top-level keys are site codes (not meta keys)
        $metaKeys  = ['period', 'site_labels', 'label_map', 'TOTAL'];
        $siteCodes = array_diff(array_keys($result), $metaKeys);

        // Build ordered list: site_labels order first, then any unlabelled
        $ordered = [];
        foreach (array_keys($siteLabels) as $code)
        {
            if (in_array($code, $siteCodes, true))
                $ordered[] = $code;
        }
        foreach ($siteCodes as $code)
        {
            if (!in_array($code, $ordered, true))
                $ordered[] = $code;
        }

        // Rebuild result with sites in correct order
        $reordered = [];
        foreach ($ordered as $code)
            $reordered[$code] = $result[$code];

        // Re-append meta keys in original order
        foreach ($metaKeys as $key)
        {
            if (isset($result[$key]))
                $reordered[$key] = $result[$key];
        }

        return $reordered;
    }
}

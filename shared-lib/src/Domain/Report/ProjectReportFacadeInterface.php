<?php

namespace CEL\Shared\Domain\Report;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

/**
 * ProjectReportFacadeInterface
 *
 * Implemented by each project's own Facade/ReportFacade.php.
 *
 * The engine calls:
 *   1. preAggregate()  — before the aggregator is created, allowing the
 *                        project to enrich $definition (e.g. inject
 *                        site_label_overrides so chart aggregators receive
 *                        site labels via their constructor).
 *   2. postAggregate() — after the aggregator runs, to enrich the result
 *                        (e.g. inject site_labels, label_map, run
 *                        baseline_fetch).
 *
 * The engine remains responsible for:
 *   - Loading config and overrides
 *   - Connecting to REDCap
 *   - Streaming records
 *   - Running the aggregator
 *   - Injecting the generic 'period' key
 *
 * M2 fix: the aggregator is now passed explicitly to postAggregate() instead
 * of being smuggled through the $definition array under the reserved key
 * '_aggregator'. Projects that need a second pass check instanceof
 * SecondPassAggregatorInterface — no method_exists() duck-typing.
 */
interface ProjectReportFacadeInterface
{
    /**
     * Called before the aggregator is instantiated.
     * Return the (optionally enriched) $definition array.
     *
     * @param  array  $definition  Merged report config + runtime overrides
     * @return array               Enriched definition
     */
    public function preAggregate(array $definition): array;

    /**
     * Called after the aggregator has run.
     * Return the (optionally enriched) $result array.
     *
     * @param  array               $result      Raw aggregator output
     * @param  array               $definition  Merged report config + runtime overrides
     * @param  RedcapApiClient     $client      Live REDCap client for secondary fetches
     * @param  AggregatorInterface $aggregator  The aggregator that produced $result.
     *                                          Check instanceof SecondPassAggregatorInterface
     *                                          before calling aggregateBaseline().
     * @return array                            Enriched result ready for the exporter
     */
    public function postAggregate(
        array              $result,
        array              $definition,
        RedcapApiClient    $client,
        AggregatorInterface $aggregator
    ): array;
}

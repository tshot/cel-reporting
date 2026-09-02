<?php

namespace CEL\Shared\Domain\Aggregator;

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

/**
 * AbstractAggregator
 *
 * Base class for all aggregators. Provides the no-op secondPass() default
 * so that:
 *   - Callers always call secondPass() unconditionally — no instanceof needed
 *   - Aggregators that need a second fetch override secondPass()
 *   - Aggregators that don't need it inherit the no-op automatically
 *
 * All concrete aggregators should extend this class rather than implementing
 * AggregatorInterface directly. This ensures LSP compliance at the class
 * level while keeping the interface pure.
 *
 * Pattern: Template Method / Null Object combined.
 */
abstract class AbstractAggregator implements AggregatorInterface
{
    /**
     * Default no-op second pass — returns result unchanged.
     *
     * Override in aggregators that need a second REDCap fetch
     * (e.g. EligibilityAggregator fetching baseline data).
     */
    public function secondPass(array $result, RedcapApiClient $client): array
    {
        return $result;
    }
}

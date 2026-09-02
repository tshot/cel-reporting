<?php

namespace CEL\Shared\Domain\Aggregator;

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

/**
 * AggregatorInterface
 *
 * All aggregators consume a lazy REDCap record stream and return a
 * structured result array.
 *
 * secondPass() is declared here so callers never need instanceof.
 * The no-op default lives in AbstractAggregator. Aggregators that
 * need a second REDCap fetch extend AbstractAggregator and override
 * secondPass(). Aggregators that don't need it extend AbstractAggregator
 * and inherit the no-op — fully LSP-compliant.
 */
interface AggregatorInterface
{
    public function aggregate(iterable $records): array;

    public function secondPass(array $result, RedcapApiClient $client): array;
}

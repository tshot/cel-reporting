<?php

namespace CEL\Projects\Emollient\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * AggregatorFactory
 *
 * Thin wrapper over AggregatorRegistry.
 *
 * Previously this class contained a large match() statement that had to be
 * edited every time a new aggregator type was added — an OCP violation.
 *
 * Now it delegates entirely to AggregatorRegistry where new types are
 * registered as closures. Adding a new aggregator type:
 *   1. Create the aggregator class
 *   2. Call AggregatorRegistry::register('type', fn(...) => new MyAggregator(...))
 *   3. Done — this file never needs to change
 *
 * Returns null for unrecognised types so the engine factory falls through
 * to shared-lib.
 */
class AggregatorFactory
{
    public static function create(
        string $type,
        string $primaryKey,
        array  $config
    ): ?AggregatorInterface 
    {
        return AggregatorRegistry::make($type, $primaryKey, $config);
    }
}

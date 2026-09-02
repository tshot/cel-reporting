<?php
namespace CEL\Projects\iKMC\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * AggregatorFactory — iKMC project.
 *
 * The reporting engine's AggregatorFactory resolves project-specific
 * aggregators by looking for a class named exactly:
 *
 *     CEL\Projects\{Project}\Aggregator\AggregatorFactory
 *
 * with a static create(string $type, string $primaryKey, array $config).
 *
 * iKMC's aggregator mapping already lives in AggregatorRegistry; this class is
 * the engine-facing entry point and simply delegates to it, so there is a
 * single place (the registry) to register new aggregators.
 *
 * Returning null (rather than throwing) for an unknown type lets the engine
 * fall through to the shared-lib factory, per the engine factory contract.
 */
class AggregatorFactory
{
    public static function create(
        string $type,
        string $primaryKey,
        array  $config = []
    ): ?AggregatorInterface {
        try {
            return AggregatorRegistry::create($type, $primaryKey, $config);
        } catch (\InvalidArgumentException $e) {
            // Unknown to iKMC — let the engine try shared-lib next.
            return null;
        }
    }
}

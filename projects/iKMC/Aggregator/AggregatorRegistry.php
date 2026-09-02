<?php
namespace CEL\Projects\iKMC\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;

/**
 * AggregatorRegistry — iKMC project
 *
 * Maps report alias → factory closure that creates an aggregator instance.
 * Each alias is referenced from reports.php under the 'aggregator' key.
 */
class AggregatorRegistry
{
    private static array $registry = [];
    private static bool  $bootstrapped = false;

    private static function bootstrap(): void
    {
        if (self::$bootstrapped) return;
        self::$bootstrapped = true;

        self::register('consort', fn($pk, $cfg) =>
            new ConsortAggregator(
                $pk,
                $cfg['date_from'] ?? null,
                $cfg['date_to']   ?? null
            )
        );
    }

    public static function register(string $alias, \Closure $factory): void
    {
        self::$registry[$alias] = $factory;
    }

    public static function create(string $alias, string $primaryKey, array $config = []): AggregatorInterface
    {
        self::bootstrap();
        if (!isset(self::$registry[$alias])) {
            throw new \InvalidArgumentException("iKMC aggregator '{$alias}' not registered.");
        }
        return (self::$registry[$alias])($primaryKey, $config);
    }
}

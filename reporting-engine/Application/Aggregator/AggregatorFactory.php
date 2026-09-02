<?php

namespace CEL\Reporting\Application\Aggregator;

use CEL\Shared\Domain\Aggregator\AggregatorInterface;
use CEL\Shared\Domain\Aggregator\AggregatorFactory as SharedAggregatorFactory;

/**
 * Engine-level aggregator factory.
 *
 * Resolution order:
 *   1. Engine-level types (e.g. baseline_summary)
 *   2. Project-specific factory (e.g. CEL\Projects\Emollient\Aggregator\AggregatorFactory)
 *   3. Shared-lib factory (generic types: weekly_enrollment, eligibility, etc.)
 *
 * To add aggregators for a new project, create:
 *   projects/{Project}/Aggregator/AggregatorFactory.php
 * with a static create() that returns the aggregator or null.
 */
class AggregatorFactory
{
    public static function create(
        string $type,
        string $primaryKey,
        array  $config,
        string $project      = '',
        string $projectsRoot = ''
    ): AggregatorInterface
    {
        // 1️⃣ Engine-level aggregators
        if ($type === 'baseline_summary')
        {
            return new BaselineSummaryAggregator($primaryKey);
        }

        // 2️⃣ Project-specific factory — works with or without composer autoload.
        //    Registers the project namespace on-the-fly if the composer autoloader
        //    doesn't know it yet. Uses spl_autoload_register so PHP's own class
        //    loading handles the file — no manual require_once needed, no redeclare risk.
        if ($project && $projectsRoot)
        {
            $aggregatorDir  = "{$projectsRoot}/{$project}/Aggregator";
            $factoryFile    = "{$aggregatorDir}/AggregatorFactory.php";
            $projectFactory = 'CEL\\Projects\\' . $project . '\\Aggregator\\AggregatorFactory';

            if (file_exists($factoryFile) && !class_exists($projectFactory, false))
            {
                // Register a one-time PSR-4 autoloader for this project's namespace.
                // This is safe to call multiple times — PHP deduplicates autoloaders
                // and the closure captures $projectsRoot/$project so each project
                // gets its own loader with its own path.
                $projectNsPrefix = 'CEL\\Projects\\' . $project . '\\';
                $projectRoot     = "{$projectsRoot}/{$project}/";

                spl_autoload_register(
                    function (string $class) use ($projectNsPrefix, $projectRoot) {
                        if (strpos($class, $projectNsPrefix) !== 0) return;
                        $relative = substr($class, strlen($projectNsPrefix));
                        $file     = $projectRoot . str_replace('\\', '/', $relative) . '.php';
                        if (file_exists($file)) require_once $file;
                    },
                    true,    // throw on error
                    true     // prepend — checked before the composer autoloader
                );

                // Trigger the newly registered loader
                class_exists($projectFactory);
            }

            if (class_exists($projectFactory, false))
            {
                $aggregator = $projectFactory::create($type, $primaryKey, $config);

                if ($aggregator !== null)
                {
                    return $aggregator;
                }
            }
        }

        // 3️⃣ Fall through to shared-lib for generic types
        return SharedAggregatorFactory::create($type, $primaryKey, $config);
    }
}

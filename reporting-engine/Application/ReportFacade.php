<?php

namespace CEL\Reporting\Application;

use Exception;
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;
use CEL\Shared\Domain\Metadata\WideColumnBlueprintBuilder;
use CEL\Shared\Domain\Transformers\TransformerFactory;
use CEL\Shared\Domain\Report\ProjectReportFacadeInterface;
use CEL\Reporting\Application\Aggregator\AggregatorFactory;

/**
 * Engine ReportFacade — thin orchestrator.
 *
 * Responsibilities:
 *   1. Load report config + merge runtime overrides
 *   2. Connect to REDCap
 *   3. Stream records
 *   4. Run aggregator
 *   5. Inject generic 'period' key
 *   6. Delegate all project-specific post-processing to the project's
 *      own Facade/ReportFacade.php (via ProjectReportFacadeInterface)
 *
 * Project-specific concerns (site_labels, label_map, baseline_fetch, etc.)
 * are handled entirely by the project facade — this class knows nothing of them.
 */
class ReportFacade
{
    /**
     * Root path where project folders live (parallel to reporting-engine/).
     * Override via constructor for testing or alternate layouts.
     */
    private string $projectsRoot;

    public function __construct(?string $projectsRoot = null)
    {
        $this->projectsRoot = $projectsRoot
            ?? realpath(__DIR__ . '/../..') . '/projects';
    }

    public function generate(string $project, string $report, array $overrides = []): iterable
    {
        // ── 1. Load and merge report config ───────────────────────────────
        $reportConfigPath = "{$this->projectsRoot}/{$project}/reports.php";

        if (!file_exists($reportConfigPath))
        {
            throw new Exception("Report config not found for project: {$project}");
        }

        $reportDefinitions = require $reportConfigPath;

        if (!isset($reportDefinitions[$report]))
        {
            throw new Exception("Report '{$report}' not defined.");
        }

        // Runtime overrides (CLI / query string) win over static config
        $definition = array_merge($reportDefinitions[$report], $overrides);

        // ── Validate date range ───────────────────────────────────────────
        // Both dates must be parseable, and date_from must not exceed date_to.
        // We validate here — before any REDCap call — so the error is clear
        // and no API quota is wasted.
        $this->validateDateRange(
            $definition['date_from'] ?? null,
            $definition['date_to']   ?? null
        );

        $mode   = $definition['mode']   ?? 'wide';
        $forms  = $definition['forms']  ?? null;
        $fields = $definition['fields'] ?? null;

        // ── 2. Connect to REDCap ──────────────────────────────────────────
        $projectConfigPath = "{$this->projectsRoot}/{$project}/config.php";

        if (!file_exists($projectConfigPath))
        {
            throw new Exception("Project config not found.");
        }

        $projectSettings = require $projectConfigPath;

        // Build the data-source client based on the project's configured driver.
        // REDCap projects use RedcapApiClient; projects with a local SQL mirror
        // (e.g. iKMC, synced MSSQL -> MySQL) use their own client that exposes
        // the same streaming interface. See createDataClient().
        $client = $this->createDataClient($project, $projectSettings);

        $t0  = microtime(true);
        $lap = function (string $label) use ($t0)
        {
            error_log(sprintf('[ReportFacade] [%.2fs] %s', microtime(true) - $t0, $label));
        };

        $filterFields = $definition['fields']  ?? [];
        $filterForms  = $definition['forms']   ?? [];
        $filterEvents = $definition['events']  ?? [];

        // ── 3+4. Aggregate mode ───────────────────────────────────────────
        if ($mode === 'aggregate')
        {
            if (empty($definition['aggregator']))
            {
                throw new \InvalidArgumentException("Aggregate mode requires 'aggregator'.");
            }

            $lap('fetchMetadata start');
            $metadata   = $client->fetchMetadata();
            $lap('fetchMetadata done');

            // REDCap exposes the primary key as metadata[0]; SQL-backed clients
            // (empty metadata) expose it via getPrimaryKey() from config.
            $primaryKey = $metadata[0]['field_name'] ?? null;
            if (!$primaryKey && method_exists($client, 'getPrimaryKey'))
            {
                $primaryKey = $client->getPrimaryKey();
            }
            if (!$primaryKey)
            {
                throw new \RuntimeException("Unable to detect primary key.");
            }

            $lap('stream start');
            $chunkSize = (int)($definition['chunk_size'] ?? 200);
            $stream = $client->stream($filterFields, $filterForms, $filterEvents, $chunkSize);
            $lap('stream generator created');

            // ── 6a. Pre-aggregate hook — project enriches $definition ─────
            // Allows the project facade to inject constructor-level config
            // before the aggregator is created (e.g. site_label_overrides).
            $projectFacade = $this->resolveProjectFacade($project);

            if ($projectFacade !== null)
            {
                $definition = $projectFacade->preAggregate($definition);
            }

            $aggregator = AggregatorFactory::create(
                $definition['aggregator'],
                $primaryKey,
                $definition,
                $project,
                $this->projectsRoot
            );

            $lap('aggregate start');
            $result = $aggregator->aggregate($stream);
            $lap('aggregate done');

            // ── 5. Inject generic period key ──────────────────────────────
            if (is_array($result))
            {
                $result['period'] = [
                    'date_from'   => $definition['date_from']   ?? null,
                    'date_to'     => $definition['date_to']     ?? null,
                    'site_filter' => $definition['site_filter'] ?? [],
                ];
            }

            // ── 6b. Post-aggregate hook — project enriches $result ────────
            // Aggregator passed explicitly — no hidden _aggregator array key.
            if ($projectFacade !== null && is_array($result))
            {
                $lap('project postAggregate start');
                $result = $projectFacade->postAggregate($result, $definition, $client, $aggregator);
                $lap('project postAggregate done');
            }

            return $result;
        }

        // ── Transformer modes (raw / flat / wide) ─────────────────────────
        $metadata      = $client->fetchMetadata();
        $projectEvents = $client->fetchEvents();
        $formEventMap  = $client->fetchFormEventMapping();

        $primaryKey = $metadata[0]['field_name'] ?? null;

        if (!$primaryKey)
        {
            throw new \RuntimeException("Unable to detect primary key.");
        }

        $chunkSize = (int)($definition['chunk_size'] ?? 200);
        $stream = $client->stream($filterFields, $filterForms, $filterEvents, $chunkSize);

        $blueprint = [];

        // A carton-/repeat-heavy project can make full wide mode buffer the
        // entire dataset (to count repeat instances). When the report opts out
        // of repeating forms ('skip_repeats' => true, or mode 'wide_norepeat'),
        // we treat every form as non-repeating: no instance columns are built,
        // repeat-instance rows are skipped by the transformer, and NO buffering
        // is needed — the pivot streams one row per subject.
        $skipRepeats = ($mode === 'wide_norepeat')
                    || !empty($definition['skip_repeats']);

        if ($mode === 'wide' || $mode === 'wide_norepeat')
        {
            if ($skipRepeats)
            {
                // Empty repeat map => blueprint emits only non-repeating
                // (event_form_field) columns. No data pass required.
                $lap('wide (no-repeat): skipping repeat detection / buffering');
                $repeatMap = [];
            }
            else
            {
                // ── Build repeat map ───────────────────────────────────────
                // We need to know how many instances each repeating form has
                // before building the column blueprint, which means a full
                // pass through the data. It does NOT mean holding that data.
                //
                // detectRepeatStructure() reads only redcap_event_name,
                // redcap_repeat_instrument and redcap_repeat_instance, so the
                // detection pass does not need the data. It does, however,
                // need REDCap to EMIT the repeat rows, and REDCap only emits
                // a repeating form's instance rows when a field belonging to
                // that form is requested.
                //
                // Requesting the primary key alone is therefore NOT enough:
                // record_id lives on a non-repeating form, so no instance rows
                // come back and every repeat map is empty. Ask for one field
                // per form instead — tens of fields rather than hundreds, so
                // rows stay small and memory stays flat.
                //
                // Buffering the full-width rows here previously exhausted 8 GB
                // on a 10,649-record project with all forms selected.
                $lap('repeat detection pass (one field per form)');
                $repeatMap = $this->detectRepeatStructure(
                    $client->stream(
                        $this->detectionFields($metadata, $filterForms, $primaryKey),
                        $filterForms,
                        $filterEvents,
                        $chunkSize
                    ),
                    $filterForms ?: null
                );
                $lap('repeat map built: ' . count($repeatMap) . ' event(s) with repeats');
            }

            $builder   = new WideColumnBlueprintBuilder();
            $blueprint = $builder->build(
                $metadata,
                $projectEvents,
                $formEventMap,
                $filterForms  ?: null,
                $filterFields ?: null,
                $repeatMap,
                $primaryKey
            );

            $lap('blueprint built: ' . count($blueprint['columns']) . ' columns');

            // $stream (created above) is a lazy generator that has not been
            // iterated — the detection pass used its own separate stream — so
            // the transform below consumes it fresh. Nothing is buffered.
        }

        $transformer = TransformerFactory::create(
            ($mode === 'wide_norepeat') ? 'wide' : $mode,
            [
                'primaryKey'     => $primaryKey,
                'columns'        => $blueprint['columns']     ?? [],
                'fieldToForm'    => $blueprint['fieldToForm'] ?? [],
                'filterNonempty' => $definition['filter_nonempty'] ?? [],
                'selectFields'   => $definition['select_fields']   ?? [],
                'skipRepeats'    => $skipRepeats,
            ]
        );

        return $transformer->transform($stream);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Build the data-source client for a project.
     *
     * The driver is read from the project's config.php 'driver' key:
     *   - 'redcap_api' (default)  -> RedcapApiClient (shared REDCap client)
     *   - 'mysql_pdo'             -> the project's own MySQL client, which
     *                                exposes the same streaming interface
     *                                (stream / fetchMetadata / fetchEvents /
     *                                 fetchFormEventMapping / getPrimaryKey).
     *
     * Projects whose config has no 'driver' key keep the previous behaviour
     * (REDCap), so existing REDCap projects are unaffected.
     *
     * @return object A client exposing the engine's data-source method surface.
     */
    private function createDataClient(string $project, array $projectSettings): object
    {
        $driver = $projectSettings['driver'] ?? 'redcap_api';

        if ($driver === 'redcap_api')
        {
            if (empty($projectSettings['api_url']) || empty($projectSettings['token']))
            {
                throw new \RuntimeException(
                    "REDCap config missing for project '{$project}': "
                    . "'api_url' and 'token' are required when driver = 'redcap_api'."
                );
            }

            return new RedcapApiClient(
                $projectSettings['api_url'],
                $projectSettings['token']
            );
        }

        if ($driver === 'mysql_pdo')
        {
            // Resolve the project's MySQL client class. Convention:
            //   projects/{Project}/Infrastructure/{Project}MysqlClient.php
            //   namespace CEL\Projects\{Project}\Infrastructure
            $clientClass = 'CEL\\Projects\\' . $project . '\\Infrastructure\\'
                         . ucfirst(strtolower($project)) . 'MysqlClient';

            if (!class_exists($clientClass, false))
            {
                // Register the same project-scoped autoloader pattern used by
                // resolveProjectFacade(), so this works whether or not composer
                // dump-autoload has been re-run.
                $nsPrefix = 'CEL\\Projects\\' . $project . '\\';
                $nsRoot   = "{$this->projectsRoot}/{$project}/";

                spl_autoload_register(
                    function (string $class) use ($nsPrefix, $nsRoot)
                    {
                        if (strpos($class, $nsPrefix) !== 0) return;
                        $relative = substr($class, strlen($nsPrefix));
                        $file     = $nsRoot . str_replace('\\', '/', $relative) . '.php';
                        if (file_exists($file)) require_once $file;
                    },
                    true,
                    true
                );

                class_exists($clientClass);
            }

            if (!class_exists($clientClass, false))
            {
                throw new \RuntimeException(
                    "MySQL driver selected for project '{$project}' but client "
                    . "class '{$clientClass}' was not found."
                );
            }

            return new $clientClass($projectSettings);
        }

        throw new \InvalidArgumentException(
            "Unknown data-source driver '{$driver}' for project '{$project}'. "
            . "Expected 'redcap_api' or 'mysql_pdo'."
        );
    }

    /**
     * Load the project-specific ReportFacade if one exists.
     *
     * Looks for:   projects/{Project}/Facade/ReportFacade.php
     * Namespace:   CEL\Projects\{Project}\Facade\ReportFacade
     *
     * Uses the same spl_autoload pattern as AggregatorFactory so it works
     * with or without composer dump-autoload having been re-run.
     *
     * Returns null if the project has no custom facade.
     */
    private function resolveProjectFacade(string $project): ?ProjectReportFacadeInterface
    {
        $facadeFile  = "{$this->projectsRoot}/{$project}/Facade/ReportFacade.php";
        $facadeClass = 'CEL\\Projects\\' . $project . '\\Facade\\ReportFacade';

        if (!file_exists($facadeFile))
        {
            return null;
        }

        if (!class_exists($facadeClass, false))
        {
            // Register a one-time autoloader for this project's Facade namespace
            $nsPrefix = 'CEL\\Projects\\' . $project . '\\';
            $nsRoot   = "{$this->projectsRoot}/{$project}/";

            spl_autoload_register(
                function (string $class) use ($nsPrefix, $nsRoot)
                {
                    if (strpos($class, $nsPrefix) !== 0) return;
                    $relative = substr($class, strlen($nsPrefix));
                    $file     = $nsRoot . str_replace('\\', '/', $relative) . '.php';
                    if (file_exists($file)) require_once $file;
                },
                true,   // throw on error
                true    // prepend — checked before composer autoloader
            );

            class_exists($facadeClass);
        }

        if (!class_exists($facadeClass, false))
        {
            return null;
        }

        // The engine's pre/post-aggregate hooks require the project facade to
        // implement ProjectReportFacadeInterface. Some projects (e.g. iKMC)
        // ship a facade with a different shape (createClient/createAggregator)
        // that is NOT used by this code path. Skip any facade that doesn't
        // implement the engine interface so we don't trip the return type-hint
        // or call methods that don't exist.
        if (!is_subclass_of($facadeClass, ProjectReportFacadeInterface::class))
        {
            return null;
        }

        $projectRoot = "{$this->projectsRoot}/{$project}";
        return new $facadeClass($projectRoot);
    }

    private function validateDateRange(?string $dateFrom, ?string $dateTo): void
    {
        // Nothing to validate if neither date is set
        if ($dateFrom === null && $dateTo === null) return;

        $fmt = 'Y-m-d';

        if ($dateFrom !== null)
        {
            $dt = \DateTime::createFromFormat($fmt, $dateFrom);
            if (!$dt || $dt->format($fmt) !== $dateFrom)
            {
                throw new \InvalidArgumentException(
                    "Invalid date_from value: '{$dateFrom}'. Expected format: YYYY-MM-DD."
                );
            }
        }

        if ($dateTo !== null)
        {
            $dt = \DateTime::createFromFormat($fmt, $dateTo);
            if (!$dt || $dt->format($fmt) !== $dateTo)
            {
                throw new \InvalidArgumentException(
                    "Invalid date_to value: '{$dateTo}'. Expected format: YYYY-MM-DD."
                );
            }
        }

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo)
        {
            throw new \InvalidArgumentException(
                "date_from ({$dateFrom}) must not be later than date_to ({$dateTo})."
            );
        }
    }

    /**
     * One field per form, for the repeat-detection pass.
     *
     * REDCap only returns a repeating form's instance rows when a field from
     * that form is in fields[]. Requesting just the primary key silently
     * yields an empty repeat map and a blueprint with no instance columns.
     *
     * Taking the first field of every form keeps the request narrow (tens of
     * fields, not hundreds) while guaranteeing each repeating form is
     * represented.
     *
     * @param  array       $metadata     REDCap data dictionary
     * @param  array|null  $filterForms  Forms the report restricts itself to
     * @param  string      $primaryKey   Always included
     * @return string[]
     */
    private function detectionFields(array $metadata, ?array $filterForms, string $primaryKey): array
    {
        $fields   = [$primaryKey];
        $seenForm = [];

        foreach ($metadata as $m)
        {
            $form  = $m['form_name']  ?? null;
            $field = $m['field_name'] ?? null;

            if (!$form || !$field) continue;
            if (isset($seenForm[$form])) continue;
            if ($filterForms && !in_array($form, $filterForms, true)) continue;

            $seenForm[$form] = true;
            $fields[]        = $field;
        }

        return array_values(array_unique($fields));
    }

    private function detectRepeatStructure(iterable $stream, ?array $selectedForms): array
    {
        $repeatMap = [];

        foreach ($stream as $row)
        {
            $event    = $row['redcap_event_name']       ?? null;
            $form     = $row['redcap_repeat_instrument'] ?? null;
            $instance = (int)($row['redcap_repeat_instance'] ?? 0);

            if (!$event || !$form || !$instance) continue;

            if ($selectedForms && !in_array($form, $selectedForms)) continue;

            $repeatMap[$event][$form] = max(
                $repeatMap[$event][$form] ?? 0,
                $instance
            );
        }

        return $repeatMap;
    }
}

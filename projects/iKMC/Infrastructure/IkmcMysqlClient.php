<?php
namespace CEL\Projects\iKMC\Infrastructure;

use CEL\Projects\iKMC\Support\VariablePrefixResolver;

/**
 * IkmcMysqlClient
 *
 * MySQL data source for the iKMC project — implements the same streaming
 * interface as RedcapApiClient so the reporting engine doesn't care whether
 * data comes from REDCap or MySQL.
 *
 * Reads from iKMC_ver2_Views first (pre-joined views) and falls back to
 * iKMC_ver2_Raw for fields not exposed in views.
 *
 * Each table has a `deleted` column. All queries filter `WHERE deleted = 0`.
 *
 * Migration note:
 *   When iKMC moves to AWS REDCap, swap config['driver'] from 'mysql_pdo'
 *   to 'redcap_api' and the engine will use RedcapApiClient instead. No
 *   aggregator or exporter code changes are needed.
 */
class IkmcMysqlClient
{
    private array $config;
    private VariablePrefixResolver $resolver;

    private ?\PDO $pdoRaw   = null;
    private ?\PDO $pdoViews = null;

    public function __construct(array $config)
    {
        $this->config   = $config;
        $this->resolver = new VariablePrefixResolver($config);
    }

    // =========================================================================
    // Stream — matches RedcapApiClient::stream() signature
    // =========================================================================

    /**
     * Stream records as a flat sequence of associative arrays.
     *
     * For iKMC, "events" are not used (no event model in MySQL) — kept in
     * signature for engine compatibility.
     *
     * @param string[] $fields    Field names — resolver groups them by table
     * @param string[] $forms     Ignored for MySQL
     * @param string[] $events    Ignored for MySQL
     * @param int      $chunkSize Rows per fetch
     */
    public function stream(
        array $fields = [],
        array $forms  = [],
        array $events = [],
        int   $chunkSize = 500
    ): iterable {
        if (empty($fields)) {
            // No fields requested → just stream record IDs
            $fields = [$this->resolver->getPrimaryKey()];
        }

        $sql = $this->buildJoinQuery($fields);
        $stmt = $this->getReadPdo()->prepare($sql);
        $stmt->execute();

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            yield $row;
        }
    }

    // =========================================================================
    // Query building — JOIN only the tables referenced by the requested fields
    // =========================================================================

    private function buildJoinQuery(array $fields): string
    {
        $pk     = $this->resolver->getPrimaryKey();
        $delCol = $this->resolver->getSoftDeleteColumn();
        $delVal = $this->resolver->getSoftDeleteActive();
        $groups = $this->resolver->groupByTable($fields);

        if (empty($groups)) {
            throw new \RuntimeException("No source tables could be resolved from fields.");
        }

        // Anchor on the scr_ table (eligibility registration) — every baby
        // has a screening record, and other tables LEFT JOIN onto it.
        $anchorTable = $this->resolver->getPrefixMap()['scr_']
            ?? throw new \RuntimeException("No scr_ prefix mapping configured.");

        // Always include primary key from anchor table
        $select = ["a.{$pk} AS {$pk}"];

        // Add fields from anchor table
        foreach ($groups[$anchorTable] ?? [] as $f) {
            if ($f === $pk) continue;
            $select[] = "a.{$f} AS {$f}";
        }

        // Build joins for other tables
        $joins      = [];
        $aliasIdx   = 1;
        $aliasMap   = [$anchorTable => 'a'];

        foreach ($groups as $table => $tableFields) {
            if ($table === $anchorTable) continue;

            $alias               = 't' . $aliasIdx++;
            $aliasMap[$table]    = $alias;

            $joins[] = "LEFT JOIN `{$table}` AS {$alias} "
                     . "ON {$alias}.{$pk} = a.{$pk} "
                     . "AND {$alias}.{$delCol} = {$delVal}";

            foreach ($tableFields as $f) {
                if ($f === $pk) continue;
                $select[] = "{$alias}.{$f} AS {$f}";
            }
        }

        $selectClause = implode(",\n    ", $select);
        $joinClause   = implode("\n", $joins);

        return "SELECT\n    {$selectClause}\nFROM `{$anchorTable}` AS a\n{$joinClause}\nWHERE a.{$delCol} = {$delVal}";
    }

    // =========================================================================
    // Engine-compatible methods (stubs / minimal implementations)
    // =========================================================================

    public function getPrimaryKey(): string
    {
        return $this->resolver->getPrimaryKey();
    }

    public function fetchMetadata(): array
    {
        // For MySQL we don't have REDCap-style metadata — return empty.
        // Aggregators should not rely on metadata for the MySQL driver.
        return [];
    }

    public function fetchEvents(): array
    {
        return [];  // No events in MySQL model
    }

    public function fetchFormEventMapping(): array
    {
        return [];  // No form/event mapping in MySQL model
    }

    public function fetchRecordIds(): array
    {
        $pk     = $this->resolver->getPrimaryKey();
        $delCol = $this->resolver->getSoftDeleteColumn();
        $delVal = $this->resolver->getSoftDeleteActive();
        $table  = $this->resolver->getPrefixMap()['scr_'];

        $sql  = "SELECT `{$pk}` FROM `{$table}` WHERE `{$delCol}` = {$delVal}";
        $stmt = $this->getReadPdo()->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN, 0);
    }

    // =========================================================================
    // Direct query helpers — for cases where prefix-based SQL isn't enough
    // =========================================================================

    /**
     * Run a SQL query against the Views database.
     * Use when a pre-joined view simplifies a complex aggregation.
     */
    public function queryView(string $sql, array $params = []): array
    {
        $stmt = $this->getViewsPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Run a SQL query against the Raw database.
     */
    public function queryRaw(string $sql, array $params = []): array
    {
        $stmt = $this->getReadPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getResolver(): VariablePrefixResolver
    {
        return $this->resolver;
    }

    // =========================================================================
    // PDO connections (lazy)
    // =========================================================================

    private function getReadPdo(): \PDO
    {
        if ($this->pdoRaw === null) {
            $mc = $this->config['mysql'];
            $dsn = "mysql:host={$mc['host']};port={$mc['port']};dbname={$mc['db_raw']};charset={$mc['charset']}";
            $this->pdoRaw = new \PDO($dsn, $mc['username'], $mc['password'], $mc['options'] ?? []);
        }
        return $this->pdoRaw;
    }

    private function getViewsPdo(): \PDO
    {
        if ($this->pdoViews === null) {
            $mc = $this->config['mysql'];
            $dsn = "mysql:host={$mc['host']};port={$mc['port']};dbname={$mc['db_views']};charset={$mc['charset']}";
            $this->pdoViews = new \PDO($dsn, $mc['username'], $mc['password'], $mc['options'] ?? []);
        }
        return $this->pdoViews;
    }
}

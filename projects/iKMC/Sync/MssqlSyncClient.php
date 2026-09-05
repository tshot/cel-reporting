<?php

namespace CEL\Projects\IKMC\Sync;

/**
 * MssqlSyncClient
 *
 * Reads from a SQL Server database and writes to MySQL.
 * Both connections use PDO — no extension-specific API leaks into callers.
 *
 * Usage:
 *   $client = new MssqlSyncClient($mssqlDsn, $mssqlUser, $mssqlPass,
 *                                 $mysqlDsn, $mysqlUser, $mysqlPass);
 *   $client->sync('source_table', 'target_table', $columnMap, $options);
 */
class MssqlSyncClient
{
    private \PDO   $src;
    private \PDO   $dst;
    private Logger $log;

    /** Source columns of type uniqueidentifier, keyed by name. See detectColumns(). */
    private array $guidCols = [];

    /** Source date/time columns => the CONVERT style that yields ISO output. */
    private array $dateCols = [];

    // =========================================================================
    // Construction
    // =========================================================================

    public function __construct(
        string  $mssqlDsn,
        string  $mssqlUser,
        string  $mssqlPass,
        string  $mysqlDsn,
        string  $mysqlUser,
        string  $mysqlPass,
        ?Logger $logger = null
    ) {
        $this->log = $logger ?? new Logger();

        $this->src = $this->connect($mssqlDsn, $mssqlUser, $mssqlPass, 'MSSQL');
        $this->dst = $this->connect($mysqlDsn,  $mysqlUser, $mysqlPass,  'MySQL');
    }

    private function connect(
    string $dsn,
    string $user,
    string $pass,
    string $label
	): \PDO 
	{
		try 
		{
			$isSqlSrv = str_starts_with($dsn, 'sqlsrv:');

			$options = [
				\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
				\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
			];

			// ATTR_TIMEOUT is not supported by the sqlsrv driver —
			// use LoginTimeout=30 in the DSN instead
			if (!$isSqlSrv) 
			{
				$options[\PDO::ATTR_TIMEOUT] = 30;
			}

			$pdo = new \PDO($dsn, $user, $pass, $options);
			$this->log->info("{$label} connected.");
			return $pdo;
		} 
		catch (\PDOException $e) 
		{
			throw new \RuntimeException(
				"{$label} connection failed: " . $e->getMessage(), 0, $e
			);
		}
	}

    // =========================================================================
    // Main sync method
    // =========================================================================

    /**
     * Sync a source table to a target table.
     *
     * @param string   $srcTable   MSSQL source table (may include schema: dbo.patients)
     * @param string   $dstTable   MySQL target table
     * @param array    $columnMap  [ 'src_col' => 'dst_col', ... ]
     *                             Empty = use all source columns with same names.
     * @param array    $options    See defaults below.
     */
    public function sync(
        string $srcTable,
        string $dstTable,
        array  $columnMap = [],
        array  $options   = []
    ): SyncResult {
        $opts = array_merge([
            'mode'          => 'upsert',    // 'upsert' | 'replace' | 'insert_ignore'
            'primary_key'   => 'id',        // column used for upsert conflict detection
            'chunk_size'    => 500,         // rows per transaction
            'where'         => '',          // optional WHERE clause on source query
            'order_by'      => '',          // optional ORDER BY on source query
            'truncate_first'=> false,       // wipe target before insert (replace mode)
            'dry_run'       => false,       // parse and count, but do not write
            'transform'     => null,        // callable($row): $row — row transformer
        ], $options);

        $result = new SyncResult($srcTable, $dstTable);
        $this->log->info("Sync {$srcTable} → {$dstTable} [{$opts['mode']}]");

        // ── Resolve column map ────────────────────────────────────────────────
        if (empty($columnMap)) {
            $columnMap = $this->detectColumns($srcTable);
            $this->log->info("Auto-detected " . count($columnMap) . " column(s).");
        }

        // ── Optional truncate ─────────────────────────────────────────────────
        if ($opts['truncate_first'] && !$opts['dry_run']) {
            $this->dst->exec("TRUNCATE TABLE `{$dstTable}`");
            $this->log->info("Target table truncated.");
        }

        // ── Build INSERT / UPSERT statement ───────────────────────────────────
        $dstCols     = array_values($columnMap);
        $srcCols     = array_keys($columnMap);
        $colList     = implode(', ', array_map(fn($c) => "`{$c}`", $dstCols));
        $placeholders= implode(', ', array_map(fn($c) => ":{$c}", $dstCols));

        $sql = match($opts['mode']) {
            'upsert' => $this->buildUpsertSql($dstTable, $colList, $placeholders, $dstCols),
            'replace'=> "REPLACE INTO `{$dstTable}` ({$colList}) VALUES ({$placeholders})",
            default  => "INSERT IGNORE INTO `{$dstTable}` ({$colList}) VALUES ({$placeholders})",
        };

        $stmt = $opts['dry_run'] ? null : $this->dst->prepare($sql);

        // ── Stream source in chunks ───────────────────────────────────────────
        $offset = 0;
        $chunk  = (int)$opts['chunk_size'];

        do {
            $rows = $this->fetchChunk($srcTable, $srcCols, $opts, $offset, $chunk);
            if (empty($rows)) break;

            $result->addFetched(count($rows));

            if (!$opts['dry_run']) {
                $written = $this->writeChunk(
                    $stmt, $rows, $columnMap, $opts['transform'], $result
                );
                $this->log->info(sprintf(
                    "  Chunk offset=%d fetched=%d written=%d",
                    $offset, count($rows), $written
                ));
            }

            $offset += $chunk;

        } while (count($rows) === $chunk);

        $this->log->info(sprintf(
            "Done. fetched=%d written=%d errors=%d",
            $result->fetched, $result->written, $result->errors
        ));

        return $result;
    }

    // =========================================================================
    // Chunk fetch
    // =========================================================================

    private function fetchChunk(
        string $table,
        array  $srcCols,
        array  $opts,
        int    $offset,
        int    $chunk
    ): array {
        $cols   = empty($srcCols)
            ? '*'
            : implode(', ', array_map(
                function ($c) {
                    if (isset($this->guidCols[$c])) {
                        return "CONVERT(CHAR(36), [{$c}]) AS [{$c}]";
                    }
                    if (isset($this->dateCols[$c])) {
                        [$cast, $style] = $this->dateCols[$c];
                        return "CONVERT({$cast}, [{$c}], {$style}) AS [{$c}]";
                    }
                    return "[{$c}]";
                },
                $srcCols
              ));
        $where  = $opts['where']    ? "WHERE {$opts['where']}"    : '';
        $order  = $opts['order_by'] ? "ORDER BY {$opts['order_by']}" : 'ORDER BY (SELECT NULL)'; // OFFSET FETCH requires ORDER BY in MSSQL

        // MSSQL 2012+ syntax
        $sql = "SELECT {$cols} FROM {$table} {$where} {$order}
                OFFSET {$offset} ROWS FETCH NEXT {$chunk} ROWS ONLY";

        return $this->src->query($sql)->fetchAll();
    }

    // =========================================================================
    // Chunk write
    // =========================================================================

    private function writeChunk(
        \PDOStatement $stmt,
        array         $rows,
        array         $columnMap,
        ?callable     $transform,
        SyncResult    $result
    ): int {
        $written = 0;

        $this->dst->beginTransaction();
        try {
            foreach ($rows as $row) {
                // Apply optional row transformer
                if ($transform !== null) {
                    $row = ($transform)($row);
                    if ($row === null) { $result->addSkipped(); continue; }
                }

                // Map src columns to dst placeholders
                $params = [];
                foreach ($columnMap as $src => $dst) {
                    $params[":{$dst}"] = $row[$src] ?? null;
                }

                try {
                    $stmt->execute($params);
                    $written++;
                    $result->addWritten();
                } catch (\PDOException $e) {
                    $result->addError($row, $e->getMessage());
                    $this->log->warning("  Row error: " . $e->getMessage());
                }
            }
            $this->dst->commit();
        } catch (\Throwable $e) {
            $this->dst->rollBack();
            throw $e;
        }

        return $written;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function buildUpsertSql(
        string $table,
        string $colList,
        string $placeholders,
        array  $dstCols
    ): string {
        // MySQL ON DUPLICATE KEY UPDATE
        $updates = implode(', ', array_map(
            fn($c) => "`{$c}` = VALUES(`{$c}`)",
            $dstCols
        ));
        return "INSERT INTO `{$table}` ({$colList}) VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE {$updates}";
    }

    /**
     * Auto-detect source columns when no column map is provided.
     * Returns [ 'col_name' => 'col_name' ] (identity map).
     */
    private function detectColumns(string $table): array
    {
        // Strip schema prefix — 'dbo.eligibility' => schema='dbo', table='eligibility'
        $parts     = explode('.', $table, 2);
        $schema    = count($parts) === 2 ? $parts[0] : 'dbo';
        $tableName = count($parts) === 2 ? $parts[1] : $parts[0];

        $stmt = $this->src->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = :schema
               AND TABLE_NAME   = :table
             ORDER BY ORDINAL_POSITION"
        );
        $stmt->execute([':schema' => $schema, ':table' => $tableName]);
        $all  = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $rows = array_column($all, 'COLUMN_NAME');

        // FreeTDS returns uniqueidentifier as 16 raw bytes, not as a formatted
        // GUID string — pdo_sqlsrv formats it, FreeTDS does not. Inserting
        // those bytes into a char(36) column fails with MySQL error 1366,
        // reported misleadingly as SQLSTATE 22007 (invalid datetime format).
        // Record the GUID columns so the SELECT can CONVERT them server-side.
        // FreeTDS renders temporal types in Sybase's default format
        // ('Dec 17 2025 12:00:00:AM'), which MySQL rejects with error 1292.
        // pdo_sqlsrv rendered ISO, which is why this only appeared after the
        // driver change. CONVERT styles: 121 = yyyy-mm-dd hh:mi:ss.mmm,
        // 23 = yyyy-mm-dd, 108 = hh:mi:ss.
        $styles = [
            'date'           => ['CHAR(10)', 23],
            'time'           => ['CHAR(8)',  108],
            'datetime'       => ['CHAR(23)', 121],
            'datetime2'      => ['CHAR(23)', 121],
            'smalldatetime'  => ['CHAR(23)', 121],
            'datetimeoffset' => ['CHAR(23)', 121],
        ];

        foreach ($all as $col) {
            $type = strtolower($col['DATA_TYPE']);
            if ($type === 'uniqueidentifier') {
                $this->guidCols[$col['COLUMN_NAME']] = true;
            } elseif (isset($styles[$type])) {
                $this->dateCols[$col['COLUMN_NAME']] = $styles[$type];
            }
        }

        if (empty($rows)) {
            throw new \RuntimeException(
                "No columns detected for {$table} — check table name and schema."
            );
        }

        return array_combine($rows, $rows);
    }

    // =========================================================================
    // Diagnostic helpers
    // =========================================================================

    /**
     * Return the MSSQL server version string.
     * Use with --version CLI flag to identify the server before configuring sync.
     */
    public function getMssqlVersion(): string
    {
        $row = $this->src
            ->query("SELECT @@VERSION AS version")
            ->fetch();

        return $row['version'] ?? 'Unknown';
    }

    /**
     * List all user tables in the MSSQL database, grouped by schema.
     * Use to discover correct table names before configuring sync_config.php.
     */
    public function listTables(): array
    {
        $rows = $this->src
            ->query("SELECT TABLE_SCHEMA, TABLE_NAME
                     FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_TYPE = 'BASE TABLE'
                     ORDER BY TABLE_SCHEMA, TABLE_NAME")
            ->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['TABLE_SCHEMA']][] = $row['TABLE_NAME'];
        }
        return $grouped;
    }

    /**
     * Generate a MySQL CREATE TABLE statement from an MSSQL table definition.
     *
     * Reads column names, types, lengths, nullability and identity (auto_increment)
     * from MSSQL INFORMATION_SCHEMA and maps them to MySQL equivalents.
     *
     * @param string $mssqlTable  e.g. 'dbo.eligibility'
     * @param string $mysqlTable  e.g. 'iKMCv2_EligibilityRegistration'
     * @return string             Ready-to-run CREATE TABLE SQL for MySQL
     */
    public function generateSchema(string $mssqlTable, string $mysqlTable): string
    {
        // Strip schema prefix for INFORMATION_SCHEMA lookup
        $parts     = explode('.', $mssqlTable, 2);
        $schema    = count($parts) === 2 ? $parts[0] : 'dbo';
        $tableName = count($parts) === 2 ? $parts[1] : $parts[0];

        // Fetch column definitions
        $cols = $this->src->prepare(
            "SELECT
                c.COLUMN_NAME,
                c.DATA_TYPE,
                c.CHARACTER_MAXIMUM_LENGTH,
                c.NUMERIC_PRECISION,
                c.NUMERIC_SCALE,
                c.IS_NULLABLE,
                c.COLUMN_DEFAULT,
                COLUMNPROPERTY(OBJECT_ID(:full), c.COLUMN_NAME, 'IsIdentity') AS is_identity
             FROM INFORMATION_SCHEMA.COLUMNS c
             WHERE c.TABLE_SCHEMA = :schema
               AND c.TABLE_NAME   = :table
             ORDER BY c.ORDINAL_POSITION"
        );
        $cols->execute([
            ':full'   => "{$schema}.{$tableName}",
            ':schema' => $schema,
            ':table'  => $tableName,
        ]);
        $columns = $cols->fetchAll();

        if (empty($columns)) {
            throw new \RuntimeException(
                "No columns found for {$mssqlTable} — check table name and schema."
            );
        }

        // Fetch primary key columns
        $pkStmt = $this->src->prepare(
            "SELECT kc.COLUMN_NAME
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
             JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kc
               ON tc.CONSTRAINT_NAME = kc.CONSTRAINT_NAME
              AND tc.TABLE_SCHEMA    = kc.TABLE_SCHEMA
             WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY'
               AND tc.TABLE_SCHEMA    = :schema
               AND tc.TABLE_NAME      = :table
             ORDER BY kc.ORDINAL_POSITION"
        );
        $pkStmt->execute([':schema' => $schema, ':table' => $tableName]);
        $pkCols = $pkStmt->fetchAll(\PDO::FETCH_COLUMN);

        // ── Build column definitions ──────────────────────────────────────────
        $lines = [];
        foreach ($columns as $col)
        {
            $name     = $col['COLUMN_NAME'];
            $nullable = $col['IS_NULLABLE'] === 'YES';
            $identity = (int)($col['is_identity'] ?? 0) === 1;
            $mysqlType= $this->mapType($col);

            $def = "`{$name}` {$mysqlType}";
            $def .= $nullable ? ' NULL' : ' NOT NULL';

            if ($identity) {
                $def .= ' AUTO_INCREMENT';
            } elseif ($col['COLUMN_DEFAULT'] !== null) {
                $default = $this->mapDefault($col['COLUMN_DEFAULT'], $col['DATA_TYPE']);
                $def    .= " DEFAULT {$default}";
            }

            $lines[] = '  ' . $def;
        }

        // ── Primary key constraint ────────────────────────────────────────────
        if (!empty($pkCols)) {
            $pkList  = implode(', ', array_map(fn($c) => "`{$c}`", $pkCols));
            $lines[] = "  PRIMARY KEY ({$pkList})";
        }

        $colsSql = implode(",\n", $lines);

        return "CREATE TABLE IF NOT EXISTS `{$mysqlTable}` (\n{$colsSql}\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n";
    }

    /**
     * Map MSSQL data type to MySQL equivalent.
     */
    private function mapType(array $col): string
    {
        $type      = strtolower($col['DATA_TYPE']);
        $charLen   = $col['CHARACTER_MAXIMUM_LENGTH'];
        $numPrec   = $col['NUMERIC_PRECISION'];
        $numScale  = $col['NUMERIC_SCALE'];

        return match($type) {
            // Integers
            'tinyint'            => 'TINYINT',
            'smallint'           => 'SMALLINT',
            'int', 'integer'     => 'INT',
            'bigint'             => 'BIGINT',
            'bit'                => 'TINYINT(1)',
            // Decimals
            'decimal', 'numeric' => "DECIMAL({$numPrec},{$numScale})",
            'money'              => 'DECIMAL(19,4)',
            'smallmoney'         => 'DECIMAL(10,4)',
            'float'              => 'DOUBLE',
            'real'               => 'FLOAT',
            // Strings
            'char'               => "CHAR(" . min((int)$charLen, 255) . ")",
            'varchar'            => $charLen == -1 ? 'LONGTEXT' : "VARCHAR(" . min((int)$charLen, 16383) . ")",
            'nchar'              => "CHAR(" . min((int)$charLen, 255) . ")",
            'nvarchar'           => $charLen == -1 ? 'LONGTEXT' : "VARCHAR(" . min((int)$charLen, 16383) . ")",
            'text', 'ntext'      => 'LONGTEXT',
            // Date / time
            'date'               => 'DATE',
            'time'               => 'TIME',
            'datetime','smalldatetime' => 'DATETIME',
            'datetime2'          => 'DATETIME(6)',
            'datetimeoffset'     => 'DATETIME(6)',
            // Binary
            'binary','varbinary' => $charLen == -1 ? 'LONGBLOB' : "VARBINARY({$charLen})",
            'image'              => 'LONGBLOB',
            // Other
            'uniqueidentifier'   => 'CHAR(36)',
            'xml'                => 'LONGTEXT',
            default              => 'TEXT',
        };
    }

    /**
     * Map MSSQL column default to MySQL syntax.
     */
    private function mapDefault(string $default, string $type): string
    {
        // Strip MSSQL parentheses wrapping e.g. ((0)) or ('value')
        $clean = trim($default, "() \t");
        $clean = trim($clean, "()");

        // Current timestamp
        if (in_array(strtolower($clean), ['getdate()', 'getutcdate()', 'sysdatetime()'], true)) {
            return 'CURRENT_TIMESTAMP';
        }

        // Numeric defaults — no quoting
        if (is_numeric($clean)) {
            return $clean;
        }

        // String defaults — strip surrounding quotes and re-quote for MySQL
        $clean = trim($clean, "'\"");
        return "'" . addslashes($clean) . "'";
    }

    // =========================================================================
    // Convenience: sync multiple tables from a map
    // =========================================================================

    /**
     * Sync several tables at once from a table map config.
     *
     * $tableMap = [
     *   ['src' => 'dbo.patients', 'dst' => 'patients', 'pk' => 'patient_id'],
     *   ['src' => 'dbo.visits',   'dst' => 'visits',   'map' => [...], 'where' => 'active=1'],
     * ];
     */
    public function syncAll(array $tableMap, array $defaultOptions = []): array
    {
        $results = [];
        foreach ($tableMap as $entry) {
            $results[] = $this->sync(
                $entry['src'],
                $entry['dst'],
                $entry['map']  ?? [],
                array_merge($defaultOptions, [
                    'primary_key' => $entry['pk']    ?? 'id',
                    'where'       => $entry['where'] ?? '',
                    'order_by'    => $entry['order_by'] ?? '',
                ])
            );
        }
        return $results;
    }
}

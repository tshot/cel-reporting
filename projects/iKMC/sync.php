<?php

/**
 * sync.php — CLI entry point for MSSQL → MySQL sync
 *
 * Usage:
 *   php sync.php                                      # sync all configured tables
 *   php sync.php --table=patients                     # sync one table by dst name
 *   php sync.php --dry-run                            # count rows without writing
 *   php sync.php --table=patients --dry-run
 *   php sync.php --log=/var/log/sync.log              # write log to file
 *   php sync.php --version                            # print MSSQL server version and exit
 *   php sync.php --list-tables                        # list tables (respects 'discover' in config)
 *   php sync.php --generate-schema=dbo.eligibility,iKMCv2_EligibilityRegistration  # generate MySQL CREATE TABLE SQL
 *   php sync.php --error-log=/var/log/ikmc_sync/errors_2026-04-17.json             # write failed rows to JSON file
 *   php sync.php --summary-email=admin@example.com                                 # email summary report on completion
 */

declare(strict_types=1);

// ── Autoload ──────────────────────────────────────────────────────────────────
// Adjust path if using Composer autoloader or the shared-lib structure
// Paths relative to /var/www/projects/iKMC/Sync/
// Up three levels: Sync -> iKMC -> projects -> www -> shared-lib
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) 
{
    require $autoload;
} 
else 
{
    // Fallback: manual require without Composer
    require __DIR__ . '/Sync/Logger.php';
    require __DIR__ . '/Sync/SyncResult.php';
    require __DIR__ . '/Sync/MssqlSyncClient.php';
}

use CEL\Projects\IKMC\Sync\MssqlSyncClient;
use CEL\Projects\IKMC\Sync\Logger;

// ── Parse CLI arguments ───────────────────────────────────────────────────────
$args           = getopt('', ['table:', 'tables:', 'dry-run', 'log:', 'verbose', 'list-tables', 'version', 'generate-schema:', 'error-log:', 'summary-email:']);
$table          = $args['table']             ?? null;
$dryRun         = isset($args['dry-run']);
$listTables     = isset($args['list-tables']);
$showVersion    = isset($args['version']);
$generateSchema = $args['generate-schema']   ?? null;
$logFile        = $args['log']               ?? '';
$errorLogFile   = $args['error-log']         ?? '';
$summaryEmail   = $args['summary-email']     ?? '';
$verbose        = isset($args['verbose']) || true;  // always verbose for CLI

// --tables=dbo.X,dbo.Y  — CLI override for table whitelist
$cliTables = isset($args['tables'])
    ? array_map('trim', explode(',', $args['tables']))
    : [];

// ── Load config ───────────────────────────────────────────────────────────────
// Config at /var/www/projects/iKMC/sync_config.php
// One level up from Sync/
$configFile = __DIR__ . '/sync_config.php';
if (!file_exists($configFile)) 
{
    fwrite(STDERR, "Error: sync_config.php not found at {$configFile}\n");
    exit(1);
}
$cfg = require $configFile;

// ── Initialise logger ─────────────────────────────────────────────────────────
$logger = new Logger($verbose, $logFile);
$logger->info("=== MSSQL → MySQL Sync " . date('Y-m-d H:i:s') . " ===");
if ($dryRun) $logger->info("DRY RUN — no data will be written.");

// ── Connect ───────────────────────────────────────────────────────────────────
try 
{
    $client = new MssqlSyncClient(
        $cfg['mssql']['dsn'],
        $cfg['mssql']['user'],
        $cfg['mssql']['password'],
        $cfg['mysql']['dsn'],
        $cfg['mysql']['user'],
        $cfg['mysql']['password'],
        $logger
    );
} 
catch (\RuntimeException $e) 
{
    $logger->error($e->getMessage());
    exit(1);
}

// ── List tables mode ──────────────────────────────────────────────────────────
if ($listTables)
{
    // Whitelist priority: CLI --tables > config 'discover' > show all
    $whitelist = $cliTables
        ?: ($cfg['discover'] ?? []);

    $all = $client->listTables();

    foreach ($all as $schema => $names)
    {
        foreach ($names as $name)
        {
            $full = "{$schema}.{$name}";

            // If whitelist is set, only show matching tables
            if (!empty($whitelist) && !in_array($full, $whitelist, true))
                continue;

            $logger->info($full);
        }
    }
    exit(0);
}

// ── Version mode ──────────────────────────────────────────────────────────────
if ($showVersion)
{
    $version = $client->getMssqlVersion();
    $logger->info("MSSQL Version: {$version}");
    exit(0);
}

// ── Generate schema mode ──────────────────────────────────────────────────────
// Usage: --generate-schema=dbo.eligibility,iKMCv2_EligibilityRegistration
// Prints a MySQL CREATE TABLE statement to stdout — pipe or redirect to a .sql file.
// Example: php sync.php --generate-schema=dbo.eligibility,iKMCv2_EligibilityRegistration > eligibility.sql
if ($generateSchema !== null)
{
    $parts = array_map('trim', explode(',', $generateSchema, 2));
    if (count($parts) !== 2) {
        $logger->error("--generate-schema requires two comma-separated values: mssql_table,mysql_table");
        $logger->error("Example: --generate-schema=dbo.eligibility,iKMCv2_EligibilityRegistration");
        exit(1);
    }
    [$mssqlTable, $mysqlTable] = $parts;

    try {
        $sql = $client->generateSchema($mssqlTable, $mysqlTable);
        echo $sql;
        $logger->info("Schema generated for {$mssqlTable} → {$mysqlTable}");
    } 
    catch (\RuntimeException $e) 
    {
        $logger->error($e->getMessage());
        exit(1);
    }
    exit(0);
}

// ── Filter tables ─────────────────────────────────────────────────────────────
$tables = $cfg['tables'];
if ($table !== null) 
{
    $tables = array_filter($tables, fn($t) => $t['dst'] === $table);
    if (empty($tables)) 
    {
        $logger->error("No table found with dst='{$table}' in config.");
        exit(1);
    }
}

// ── Run sync ──────────────────────────────────────────────────────────────────
$syncStartTime = microtime(true);
$defaults = array_merge($cfg['defaults'] ?? [], ['dry_run' => $dryRun]);
$results  = [];
$exitCode = 0;

foreach ($tables as $entry) 
{
    try 
    {
        $result = $client->sync(
            $entry['src'],
            $entry['dst'],
            $entry['map']       ?? [],
            array_merge($defaults, [
                'primary_key' => $entry['pk']       ?? 'id',
                'where'       => $entry['where']    ?? '',
                'order_by'    => $entry['order_by'] ?? '',
                'transform'   => $entry['transform'] ?? null,
            ])
        );
        $results[] = $result;
        $logger->info($result->summary());

        if ($result->errors > 0) $exitCode = 1;

    } 
    catch (\Throwable $e) 
    {
        $logger->error("Fatal error syncing {$entry['src']}: " . $e->getMessage());
        $results[] = null;
        $exitCode = 2;
    }
}

// ── Final summary ─────────────────────────────────────────────────────────────
$syncEndTime  = microtime(true);
$totalElapsed = round($syncEndTime - $syncStartTime, 2);

$logger->info("=== Summary ===");
$totalFetched = $totalWritten = $totalErrors = 0;
$allErrorRows = [];

foreach ($results as $r)
{
    if ($r === null) continue;
    $totalFetched += $r->fetched;
    $totalWritten += $r->written;
    $totalErrors  += $r->errors;
    // Collect all row-level errors across all tables
    foreach ($r->errorLog as $entry) 
    {
        $allErrorRows[] = $entry;
    }
}

$logger->info("Tables : "  . count(array_filter($results)));
$logger->info("Fetched: "  . number_format($totalFetched));
$logger->info("Written: "  . number_format($totalWritten));
$logger->info("Errors : "  . number_format($totalErrors));
$logger->info("Duration: " . $totalElapsed . "s");

// ── Write error JSON log ───────────────────────────────────────────────────────
if ($errorLogFile !== '' && !empty($allErrorRows))
{
    $dir = dirname($errorLogFile);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $lines = array_map(
        fn($entry) => json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $allErrorRows
    );
    file_put_contents($errorLogFile, implode("\n", $lines) . "\n");
    $logger->info("Error log: {$errorLogFile} (" . count($allErrorRows) . " failed rows)");
}
elseif ($errorLogFile !== '')
{
    $logger->info("Error log: no failures — nothing written.");
}

// ── Send summary email ─────────────────────────────────────────────────────────
if ($summaryEmail !== '')
{
    $date    = date('Y-m-d');
    $status  = $totalErrors > 0 ? 'COMPLETED WITH ERRORS' : 'OK';
    $subject = "iKMC Sync {$status} — {$date}";

    $body  = "iKMC MSSQL → MySQL Sync Report\n";
    $body .= str_repeat('=', 40) . "\n";
    $body .= "Date     : {$date}\n";
    $body .= "Status   : {$status}\n";
    $body .= str_repeat('-', 40) . "\n";
    $body .= "Tables   : " . count(array_filter($results)) . "\n";
    $body .= "Fetched  : " . number_format($totalFetched) . "\n";
    $body .= "Written  : " . number_format($totalWritten) . "\n";
    $body .= "Errors   : " . number_format($totalErrors)  . "\n";
    $body .= "Duration : {$totalElapsed}s\n";

    if (!empty($allErrorRows)) 
    {
        $body .= str_repeat('-', 40) . "\n";
        $body .= "Failed rows (first 20):\n\n";
        foreach (array_slice($allErrorRows, 0, 20) as $entry) 
        {
            $body .= "Table : {$entry['table']}\n";
            $body .= "Error : {$entry['error']}\n";
            $body .= "Row   : " . json_encode($entry['row'], JSON_UNESCAPED_UNICODE) . "\n\n";
        }
        if (count($allErrorRows) > 20) 
        {
            $body .= "... and " . (count($allErrorRows) - 20) . " more. See error log for full details.\n";
        }
    }

    if ($errorLogFile !== '' && !empty($allErrorRows)) {
        $body .= str_repeat('-', 40) . "\n";
        $body .= "Full error log: {$errorLogFile}\n";
    }

    $headers = "From: iKMC Sync <noreply@celworld.org>\r\nContent-Type: text/plain; charset=UTF-8";
    if (mail($summaryEmail, $subject, $body, $headers)) 
    {
        $logger->info("Summary email sent to {$summaryEmail}");
    } 
    else 
    {
        $logger->warning("Failed to send summary email to {$summaryEmail}");
    }
}

$logger->info("=== Done ===");

exit($exitCode);

<?php
/**
 * projects/iKMC/config.php
 *
 * iKMC project — MySQL data source (will migrate to AWS REDCap later)
 *
 * The 'driver' key selects which DataSourceClient implementation the engine
 * uses. Today: mysql_pdo. Later: redcap_api (no other code changes needed).
 *
 * Variable prefix mapping (used by VariablePrefixResolver):
 *   scr_  → iKMCv2_EligibilityRegistration
 *   enr_  → iKMCv2_MotherBabyRegistration
 *   dmf_  → iKMCv2_DailyCareTracking
 *   dis_  → iKMCv2_Discharge
 *
 * Shared key across all four tables: recordid
 * Each table has 'deleted' column — 1 = deleted, 0 = active
 */

return [
    // ── Data source driver ─────────────────────────────────────────────────
    'driver' => 'mysql_pdo',   // 'mysql_pdo' (now) | 'redcap_api' (after AWS migration)
    'client_class' => 'IkmcMysqlClient',

    // ── MySQL connection (current — localhost) ─────────────────────────────
    'mysql' => [
        'host'       => $_ENV['IKMC_DB_HOST']     ?? '127.0.0.1',
        'port'       => (int)($_ENV['IKMC_DB_PORT'] ?? 3306),
        'username'   => $_ENV['IKMC_DB_USER']     ?? throw new RuntimeException('IKMC_DB_USER is not set — check .env'),
        'password'   => $_ENV['IKMC_DB_PASS']     ?? throw new RuntimeException('IKMC_DB_PASS is not set — check .env'),
        'db_raw'     => $_ENV['IKMC_DB_RAW']      ?? 'iKMC_ver2_Raw',
        'db_views'   => $_ENV['IKMC_DB_VIEWS']    ?? 'iKMC_ver2_Views',
        'charset'    => 'utf8mb4',
        'options'    => [
            // Standard PDO options for safety
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],

    // ── REDCap connection (future — for AWS migration) ─────────────────────
    'redcap' => [
        'api_url' => $_ENV['IKMC_REDCAP_URL']   ?? '',
        'token'   => $_ENV['IKMC_REDCAP_TOKEN'] ?? '',
    ],

    // ── Variable prefix → table mapping ────────────────────────────────────
    'prefix_table_map' => [
        'scr_' => 'iKMCv2_EligibilityRegistration',
        'enr_' => 'iKMCv2_MotherBabyRegistration',
        'dmf_' => 'iKMCv2_DailyCareTracking',
        'dis_' => 'iKMCv2_Discharge',
    ],

    // ── Primary key shared across all tables ───────────────────────────────
    'primary_key' => 'recordid',

    // ── Deletion filter — all queries apply `WHERE deleted = 0` ────────────
    'soft_delete_column' => 'deleted',
    'soft_delete_active' => 0,
];

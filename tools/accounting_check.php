<?php
/**
 * tools/accounting_check.php
 * ==========================
 * Authoritative reconciliation, keyed on bottles.id. REDCap "used" data is API
 * only (bottle_usage / babies are empty locally), so this calls
 * BottleAccountingService — the same path the dashboard uses.
 *
 * USAGE (run on the server):
 *   php tools/accounting_check.php
 *   php tools/accounting_check.php 3
 *   php tools/accounting_check.php "GSVM Medical College, Kanpur"
 *   php tools/accounting_check.php --warehouse
 *
 * Adjust the require path to your bootstrap (tools/ and oil-tracking/ are
 * siblings under /var/www, so ../oil-tracking/src/... is correct).
 */

require __DIR__ . '/../oil-tracking/src/config/bootstrap.php';

use OilTracking\Services\BottleAccountingService;

// Build the service if the deployed bootstrap doesn't (older bootstrap).
if (!isset($bottleAccountingService) || !($bottleAccountingService instanceof BottleAccountingService)) {
    if (!class_exists(BottleAccountingService::class)) {
        fwrite(STDERR, "ERROR: deploy src/Services/BottleAccountingService.php (composer dump-autoload).\n");
        exit(1);
    }
    foreach (['oilDbConnection','bottleRepo','bottleIssueRepo','redcapClient'] as $v) {
        if (!isset($$v) || $$v === null) {
            fwrite(STDERR, "ERROR: bootstrap did not define \$$v. Check the require path.\n");
            exit(1);
        }
    }
    if (!method_exists($bottleRepo, 'getCartonRouteBottleIds')
        || !method_exists($bottleIssueRepo, 'getFacilityTestingBottleIds')) {
        fwrite(STDERR, "ERROR: deploy the updated BottleRepository.php / BottleIssueRepository.php.\n");
        exit(1);
    }
    $bottleAccountingService = new BottleAccountingService(
        $bottleRepo, $bottleIssueRepo, $redcapClient, $oilDbConnection
    );
}

$arg = $argv[1] ?? null;

// ── Warehouse mode ───────────────────────────────────────────────────────────
if ($arg === '--warehouse') {
    $w = $bottleAccountingService->getWarehouseStock();
    echo "Warehouse stock (bottle_id keyed, W-1)\n";
    echo str_repeat('-', 50) . "\n";
    printf("%-34s %8d\n", 'warehouse_received (full)',        $w['received_full']);
    printf("%-34s %8d\n", 'warehouse_partially_opened (bal)', $w['partial_balance']);
    printf("%-34s %8d\n", 'warehouse_opened (0 by rule)',     0);
    printf("%-34s %8d\n", 'BALANCE BOTTLES AT WAREHOUSE',     $w['balance_bottles']);
    printf("%-34s %8d\n", 'warehouse testing / given-out',    $w['warehouse_testing']);
    echo "\nCartons: received {$w['cartons']['received']}, "
       . "partially_opened {$w['cartons']['partially_opened']}, "
       . "opened {$w['cartons']['opened']}\n";
    exit(0);
}

// ── Facility mode ────────────────────────────────────────────────────────────
$facilityId = null;
if ($arg !== null && $arg !== '') {
    if (ctype_digit((string)$arg)) {
        $facilityId = (int)$arg;
    } else {
        $stmt = $oilDbConnection->prepare("SELECT id FROM facilities WHERE name = :n LIMIT 1");
        $stmt->execute(['n' => $arg]);
        $facilityId = ($r = $stmt->fetch(PDO::FETCH_ASSOC)) ? (int)$r['id'] : null;
        if ($facilityId === null) { fwrite(STDERR, "No facility named: {$arg}\n"); exit(1); }
    }
}

$records = $bottleAccountingService->getFacilityAccounting($facilityId);
if (!$records) { echo "No accounting records.\n"; exit(0); }

printf("%-34s %8s %8s %8s %8s %8s %10s %9s\n",
    'Facility','Recv','Carton','Direct','Testing','Used','Unmatched','Avail');
echo str_repeat('-', 104) . "\n";
foreach ($records as $r) {
    printf("%-34s %8d %8d %8d %8d %8d %10d %9d\n",
        mb_strimwidth((string)$r['facility_name'], 0, 34),
        $r['received_total'], $r['from_cartons_total'], $r['direct_supply_total'],
        $r['testing_total'], $r['used_total'], $r['unmatched_usage'], $r['available']);
}
echo "\nReceived = Cartons + Direct(supply)    Available = Received − Testing − Used\n";

if ($facilityId !== null && count($records) === 1) {
    $r = $records[0];
    if (!empty($r['unmatched_numbers'])) {
        echo "\nUnmatched REDCap usage (no received bottle at this facility) ["
            . count($r['unmatched_numbers']) . "]: "
            . implode(', ', $r['unmatched_numbers']) . "\n";
    }
}

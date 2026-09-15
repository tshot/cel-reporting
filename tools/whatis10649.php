<?php
/**
 * whatis10649.php — what does one row in the wide dump represent?
 * Usage: php whatis10649.php /tmp/fpd.csv
 *
 * Looks for family / baby-count / twin fields, shows their value spread,
 * and checks whether any column repeats across record_ids (a family key).
 */
ini_set('memory_limit', '3G');
$file = $argv[1] ?? exit("Usage: php whatis10649.php <file.csv>\n");
$fh = fopen($file, 'r') ?: exit("Cannot open\n");
$header = fgetcsv($fh);

// columns that might describe multiples
$pat = '/fmly|family|baby_count|babycount|twin|multi|birth_order|no_of_bab|num_bab/i';
$watch = [];
foreach ($header as $i => $h) { if (preg_match($pat, $h)) { $watch[$i] = $h; } }

echo "columns matching family/baby-count/twin: " . count($watch) . "\n";
foreach (array_slice($watch, 0, 25, true) as $i => $h) { echo "  [{$i}] {$h}\n"; }
if (count($watch) > 25) { echo "  ... and " . (count($watch) - 25) . " more\n"; }

$vals   = [];          // col => value => count
$ids    = [];
$sitePre = [];
$rows   = 0;

while (($r = fgetcsv($fh)) !== false) {
    if ($r === [null]) continue;
    $rows++;
    $id = (string)($r[0] ?? '');
    $ids[$id] = true;
    $sitePre[explode('-', $id)[0]] = ($sitePre[explode('-', $id)[0]] ?? 0) + 1;

    foreach ($watch as $i => $h) {
        $v = trim((string)($r[$i] ?? ''));
        if ($v === '') { $v = '(blank)'; }
        if (strlen($v) > 20) { $v = substr($v, 0, 20) . '…'; }
        $vals[$i][$v] = ($vals[$i][$v] ?? 0) + 1;
    }
}
fclose($fh);

echo "\ndata rows      : {$rows}\n";
echo "distinct ids   : " . count($ids) . "\n";

echo "\nrecords per site prefix:\n";
arsort($sitePre);
foreach ($sitePre as $p => $c) { printf("  %-8s %6d\n", $p, $c); }

echo "\nvalue spread in the watched columns (top 6 each):\n";
foreach ($vals as $i => $counts) {
    arsort($counts);
    $nonBlank = $rows - ($counts['(blank)'] ?? 0);
    printf("\n  [%d] %s\n      non-blank: %d of %d\n", $i, $header[$i], $nonBlank, $rows);
    foreach (array_slice($counts, 0, 6, true) as $v => $c) {
        printf("      %-24s %6d\n", $v, $c);
    }
    // does this column repeat across ids? (candidate family/mother key)
    if ($nonBlank > 0) {
        $distinct = count($counts) - (isset($counts['(blank)']) ? 1 : 0);
        if ($distinct > 1 && $distinct < $nonBlank) {
            printf("      -> %d distinct values over %d rows — repeats, could be a family key\n",
                $distinct, $nonBlank);
        }
    }
}

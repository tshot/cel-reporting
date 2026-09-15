<?php
/**
 * csvcheck.php — count a CSV properly (handles quoted commas and newlines)
 * NOTE: reads with escape='' (RFC 4180). PHP's fgetcsv default backslash escape
 *       corrupts values containing a backslash.
 *
 * Usage: php csvcheck.php /tmp/fpd.csv
 */
$file = $argv[1] ?? exit("Usage: php csvcheck.php <file.csv>\n");
$fh = fopen($file, 'r') ?: exit("Cannot open {$file}\n");
ini_set('memory_limit', '2G');

$header = fgetcsv($fh, 0, ',', '"', '');
$cols   = count($header);
$rows   = 0;
$ids    = [];
$dupes  = [];
$ragged = 0;

while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($r === [null]) { continue; }              // blank line
    $rows++;
    if (count($r) !== $cols) { $ragged++; }
    $id = $r[0] ?? '';
    if (isset($ids[$id])) { $dupes[$id] = ($dupes[$id] ?? 1) + 1; }
    $ids[$id] = true;
}
fclose($fh);

printf("file            : %s (%.1f MB)\n", $file, filesize($file) / 1048576);
printf("header columns  : %d\n", $cols);
printf("data rows       : %d\n", $rows);
printf("distinct col-1  : %d\n", count($ids));
printf("rows per id     : %.2f\n", count($ids) ? $rows / count($ids) : 0);
printf("ragged rows     : %d  (column count != header)\n", $ragged);

if ($dupes) {
    printf("\nduplicated ids  : %d — first 5:\n", count($dupes));
    foreach (array_slice($dupes, 0, 5, true) as $id => $n) {
        printf("   %-12s appears %d times\n", $id, $n);
    }
} else {
    printf("\nNo duplicate ids — one row per participant.\n");
}

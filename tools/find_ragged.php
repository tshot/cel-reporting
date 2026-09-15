<?php
/**
 * find_ragged.php — isolate rows whose column count differs from the header.
 * NOTE: reads with escape='' (RFC 4180). PHP's fgetcsv default backslash escape
 *       corrupts values containing a backslash.
 *
 * Usage: php find_ragged.php /tmp/fpd.csv [record_id]
 */
ini_set('memory_limit', '2G');
$file = $argv[1] ?? exit("Usage: php find_ragged.php <file.csv> [record_id]\n");
$want = $argv[2] ?? null;

$fh = fopen($file, 'r') ?: exit("Cannot open\n");
$header = fgetcsv($fh, 0, ',', '"', '');
$cols   = count($header);
$n = 0;

while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
    if ($r === [null]) continue;
    $n++;
    $isTarget = ($want !== null && ($r[0] ?? '') === $want);
    if (count($r) === $cols && !$isTarget) continue;

    printf("=== data row %d | record_id=%s | %d cols (header %d, diff %+d) ===\n",
        $n, var_export($r[0] ?? '', true), count($r), $cols, count($r) - $cols);

    // where does it stop matching? compare tail alignment
    $last = count($r) - 1;
    echo "  header[last 5]: " . implode(' | ', array_slice($header, -5)) . "\n";
    echo "  row   [last 5]: " . implode(' | ', array_map(
        fn($v) => substr(str_replace(["\n","\r"], '\\n', (string)$v), 0, 30),
        array_slice($r, -5))) . "\n";

    // any value containing a quote, comma or newline is the usual culprit
    $sus = [];
    foreach ($r as $i => $v) {
        $v = (string)$v;
        if ($v !== '' && (str_contains($v, '"') || str_contains($v, "\n") || str_contains($v, "\r"))) {
            $sus[] = sprintf("    col %d (%s) = %s", $i,
                $header[$i] ?? '?', var_export(substr($v, 0, 60), true));
        }
    }
    if ($sus) {
        echo "  values containing quote/newline:\n" . implode("\n", array_slice($sus, 0, 8)) . "\n";
    } else {
        echo "  no embedded quotes or newlines in this row\n";
    }
    echo "\n";
}
fclose($fh);
echo "scanned {$n} data rows\n";

<?php
/**
 * inspect_col1.php — what is actually in column 1, and where is the ragged row?
 * Usage: php inspect_col1.php /tmp/fpd.csv
 */
ini_set('memory_limit', '2G');
$file = $argv[1] ?? exit("Usage: php inspect_col1.php <file.csv>\n");
$fh = fopen($file, 'r') ?: exit("Cannot open\n");

$header = fgetcsv($fh);
$cols   = count($header);

echo "header[0..3] : " . implode(' | ', array_slice($header, 0, 4)) . "\n\n";

$n = 0; $samples = []; $bases = []; $ragged = [];
while (($r = fgetcsv($fh)) !== false) {
    if ($r === [null]) continue;
    $n++;
    if ($n <= 10) { $samples[] = $r[0]; }
    if (count($r) !== $cols) { $ragged[] = [$n, count($r), substr((string)$r[0], 0, 40)]; }

    // strip anything after the first separator-ish character
    $base = preg_split('/[_|:\s]/', (string)$r[0])[0];
    $bases[$base] = true;
}
fclose($fh);

echo "first 10 values of column 1:\n";
foreach ($samples as $i => $v) { printf("  %2d: %s\n", $i + 1, var_export($v, true)); }

echo "\ntotal data rows              : {$n}\n";
echo "distinct col-1 (raw)         : ...see csvcheck\n";
echo "distinct col-1 (before _|: ) : " . count($bases) . "\n";
echo "  sample bases: " . implode(', ', array_slice(array_keys($bases), 0, 8)) . "\n";

if ($ragged) {
    echo "\nragged rows (" . count($ragged) . "):\n";
    foreach (array_slice($ragged, 0, 5) as [$line, $c, $id]) {
        printf("  data row %d: %d cols (header has %d), col1 starts '%s'\n", $line, $c, $cols, $id);
    }
} else {
    echo "\nno ragged rows\n";
}

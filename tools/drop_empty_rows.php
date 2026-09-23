#!/usr/bin/env php
<?php
/**
 * drop_empty_rows.php — remove records that carry no data.
 *
 * A wide export has one row per record_id. When a subset of forms is chosen,
 * a baby that never reached those forms leaves a row with record_id filled and
 * every other cell blank. This removes those rows.
 *
 *   --min-fields=N   keep a row only if it has at least N non-empty cells
 *                    besides record_id. Default 1, i.e. drop only the rows
 *                    that are entirely blank.
 *   --key-cols=N     how many leading columns are identifiers and do not count
 *                    towards the total. Default 1 (record_id).
 *   --check-only     report what would be dropped, write nothing.
 *
 * Usage (from the repo root):
 *   php tools/drop_empty_rows.php in.csv out.csv
 *   php tools/drop_empty_rows.php in.csv out.csv --min-fields=3
 *   php tools/drop_empty_rows.php in.csv --check-only
 *
 * Exit codes: 0 ok · 1 error · 4 every row would be dropped (refuses to write)
 */
ini_set('memory_limit', '1G');
const ESC = '';

$opts = []; $files = [];
foreach (array_slice($argv, 1) as $a)
{
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opts[$m[1]] = $m[2] ?? true; }
    else { $files[] = $a; }
}
$check = isset($opts['check-only']);
$min   = max(1, (int)($opts['min-fields'] ?? 1));
$keyN  = max(0, (int)($opts['key-cols']   ?? 1));

if ($files === [] || (!$check && count($files) < 2))
{
    fwrite(STDERR, "Usage: php drop_empty_rows.php <in.csv> <out.csv> [--min-fields=N] [--check-only]\n");
    exit(1);
}
if (!is_file($files[0])) { fwrite(STDERR, "ERROR: not found: {$files[0]}\n"); exit(1); }

$fh  = fopen($files[0], 'r') ?: exit("Cannot open {$files[0]}\n");
$hdr = fgetcsv($fh, 0, ',', '"', ESC);
if ($hdr === false) { fwrite(STDERR, "ERROR: no header row\n"); exit(1); }

$oh = null;
if (!$check)
{
    $oh = fopen($files[1], 'w') ?: exit("Cannot write {$files[1]}\n");
    fputcsv($oh, $hdr, ',', '"', ESC);
}

$total = $kept = $dropped = 0;
$examples = [];

while (($r = fgetcsv($fh, 0, ',', '"', ESC)) !== false)
{
    if ($r === [null]) { continue; }
    $total++;

    $filled = 0;
    foreach ($r as $i => $v)
    {
        if ($i < $keyN) { continue; }
        if ($v !== null && trim((string)$v) !== '') { $filled++; }
    }

    if ($filled >= $min)
    {
        $kept++;
        if ($oh) { fputcsv($oh, $r, ',', '"', ESC); }
    }
    else
    {
        $dropped++;
        if (count($examples) < 5) { $examples[] = ($r[0] ?? '?') . " ({$filled} non-empty)"; }
    }
}
fclose($fh);

printf("input      : %s\n", $files[0]);
printf("threshold  : at least %d non-empty cell(s) besides the first %d column(s)\n", $min, $keyN);
printf("rows       : %d total, %d kept, %d dropped (%.1f%%)\n",
    $total, $kept, $dropped, $total ? $dropped / $total * 100 : 0);

if ($examples)
{
    echo "dropped, first few:\n";
    foreach ($examples as $e) { echo "  {$e}\n"; }
}

if ($check) { echo "\n--check-only: nothing written.\n"; exit(0); }

if ($kept === 0)
{
    fclose($oh);
    unlink($files[1]);
    fwrite(STDERR, "\nERROR: every row would be dropped — refusing to write an empty file.\n");
    exit(4);
}

fclose($oh);
printf("\nwritten    : %s — %d rows\n", $files[1], $kept);

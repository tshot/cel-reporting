#!/usr/bin/env php
<?php
/**
 * deidentify.php — remove identifying columns from an export.
 *
 * Works on ANY export layout, because it matches on the FIELD part of a
 * column name:
 *   long  : fmly_mother_name
 *   wide  : day0_arm_1_family_details_and_baby_count_form_fmly_mother_name
 *   repeat: day1_arm_1_some_form_3_fmly_mother_name
 *   check : baby_area___1
 *
 * Usage (from the repo root):
 *   php tools/deidentify.php /tmp/wide.csv /tmp/wide_deid.csv
 *   php tools/deidentify.php /tmp/raw.csv  /tmp/raw_deid.csv --list=tools/phi_fields.txt
 *   php tools/deidentify.php /tmp/wide.csv --check-only
 *
 * --check-only reports what WOULD be removed and writes nothing. Use it on a
 * file you are about to send, as a final gate.
 *
 * NOTE: this strips a file that already exists, so the identifiers were on
 * disk before it ran. Removing them at source is stronger — see the report
 * definition note in the Exports Runbook. Treat the pre-strip file as
 * identifiable data and delete it once the clean copy is verified.
 */
ini_set('memory_limit', '1G');

const ESC = '';   // RFC 4180

$args  = array_slice($argv, 1);
$flags = array_values(array_filter($args, fn($a) => str_starts_with($a, '--')));
$files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

$checkOnly = in_array('--check-only', $flags, true);
$listPath  = null;
foreach ($flags as $f)
{
    if (preg_match('/^--list=(.+)$/', $f, $m)) { $listPath = $m[1]; }
}

if ($files === [] || (!$checkOnly && count($files) < 2))
{
    fwrite(STDERR, "Usage: php deidentify.php <in.csv> <out.csv> [--list=phi_fields.txt]\n");
    fwrite(STDERR, "       php deidentify.php <in.csv> --check-only\n");
    exit(1);
}

$in  = $files[0];
$out = $checkOnly ? null : $files[1];

if ($listPath === null)
{
    foreach ([__DIR__ . '/phi_fields.txt', getcwd() . '/tools/phi_fields.txt'] as $c)
    {
        if (is_file($c)) { $listPath = $c; break; }
    }
}

if ($listPath === null || !is_file($listPath))
{
    fwrite(STDERR, "ERROR: field list not found. Pass --list=/path/to/phi_fields.txt\n");
    exit(1);
}

// ---- load the list ----
$phi = [];
foreach (file($listPath, FILE_IGNORE_NEW_LINES) as $line)
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) { continue; }
    $phi[] = $line;
}

if ($phi === [])
{
    fwrite(STDERR, "ERROR: {$listPath} lists no fields.\n");
    exit(1);
}

echo "input  : {$in}\n";
echo "list   : {$listPath} (" . count($phi) . " fields)\n";

/** Does this column carry one of the listed fields? */
function isPhiColumn(string $col, array $phi): ?string
{
    // strip a checkbox option suffix: field___CODE
    $base = preg_replace('/___.+$/', '', $col);

    foreach ($phi as $f)
    {
        if ($base === $f) { return $f; }
        if (str_ends_with($base, '_' . $f)) { return $f; }
    }

    return null;
}

$fh = fopen($in, 'r') ?: exit("Cannot open {$in}\n");
$header = fgetcsv($fh, 0, ',', '"', ESC);

if ($header === false)
{
    fwrite(STDERR, "ERROR: {$in} has no header row.\n");
    exit(1);
}

$drop = [];      // index => matched field
foreach ($header as $i => $col)
{
    $hit = isPhiColumn((string)$col, $phi);
    if ($hit !== null) { $drop[$i] = $hit; }
}

$byField = [];
foreach ($drop as $f) { $byField[$f] = ($byField[$f] ?? 0) + 1; }
ksort($byField);

printf("columns: %d total, %d identifying\n\n", count($header), count($drop));

if ($byField === [])
{
    echo "Nothing to remove — no listed field appears in this file.\n";
}
else
{
    printf("%-26s %s\n", 'field', 'columns');
    foreach ($byField as $f => $n) { printf("  %-24s %d\n", $f, $n); }
}

$notFound = array_diff($phi, array_keys($byField));
if ($notFound !== [])
{
    printf("\nListed but absent from this file (%d):\n  %s\n",
        count($notFound), implode(', ', $notFound));
    echo "  Expected for a partial export; check spelling if you expected them here.\n";
}

if ($checkOnly)
{
    fclose($fh);
    echo "\n--check-only: nothing written.\n";
    exit(count($drop) > 0 ? 2 : 0);   // exit 2 = identifiers present
}

// ---- rewrite without those columns ----
$keep = array_values(array_diff(array_keys($header), array_keys($drop)));
$oh = fopen($out, 'w') ?: exit("Cannot write {$out}\n");

$line = [];
foreach ($keep as $i) { $line[] = $header[$i]; }
fputcsv($oh, $line, ',', '"', ESC);

$rows = 0;
while (($r = fgetcsv($fh, 0, ',', '"', ESC)) !== false)
{
    if ($r === [null]) { continue; }
    $line = [];
    foreach ($keep as $i) { $line[] = $r[$i] ?? ''; }
    fputcsv($oh, $line, ',', '"', ESC);
    $rows++;
}
fclose($fh);
fclose($oh);

printf("\nwritten: %s\n", $out);
printf("  %d rows, %d columns (%d removed)\n", $rows, count($keep), count($drop));
printf("\nDelete the pre-strip file once you have checked this one:\n  rm %s\n", $in);

#!/usr/bin/env php
<?php
/**
 * verify_wide.php — prove a wide dump reproduces the long-format source.
 *
 * Takes the WIDE csv and a LONG csv of the same data (RawDump or
 * RawLongitudinalDump) and checks, cell by cell, that every non-empty value
 * in the long file appears in the column the wide file should have put it in.
 *
 * Needs no REDCap access — it compares two files you already have.
 *
 * Usage (from the repo root):
 *   php tools/dump.php --project=Emollient --report=WideDump --output=/tmp/wide.csv
 *   php tools/dump.php --project=Emollient --report=RawDump  --output=/tmp/raw.csv
 *   php tools/verify_wide.php /tmp/wide.csv /tmp/raw.csv
 *   php tools/verify_wide.php /tmp/wide.csv /tmp/raw.csv --sample=200 --show=15
 *   php tools/verify_wide.php /tmp/wide.csv /tmp/raw.csv --exclude=tools/phi_fields.txt
 *
 * --exclude skips fields that were deliberately removed from the wide file.
 * Without it, verifying a de-identified export against a full long export
 * reports every removed field as a missing column.
 */
ini_set('memory_limit', '4G');

$args = array_slice($argv, 1);
$files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
$opts  = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $a, $m)) { $opts[$m[1]] = $m[2]; }
}

if (count($files) < 2) {
    fwrite(STDERR, "Usage: php verify_wide.php <wide.csv> <long.csv> [--sample=N] [--show=N]\n");
    exit(1);
}

[$wideFile, $longFile] = $files;
$sample = (int)($opts['sample'] ?? 0);      // 0 = all records
$show   = (int)($opts['show']   ?? 10);

$excluded = [];
if (isset($opts['exclude']))
{
    if (!is_file($opts['exclude'])) { exit("exclude list not found: {$opts['exclude']}\n"); }
    foreach (file($opts['exclude'], FILE_IGNORE_NEW_LINES) as $l)
    {
        $l = trim($l);
        if ($l !== '' && !str_starts_with($l, '#')) { $excluded[$l] = true; }
    }
}

const ESC = '';   // RFC 4180 — PHP's default backslash escape corrupts values

function readCsv(string $path): array {
    $fh = fopen($path, 'r') ?: exit("Cannot open {$path}\n");
    $hdr = fgetcsv($fh, 0, ',', '"', ESC);
    return [$fh, $hdr];
}

// ---- 1. index the wide file ----
// Only the sampled records are held, and only their NON-EMPTY cells. The wide
// file is mostly empty, so this is a small fraction of the file; indexing every
// row in full needs many GB and was the previous behaviour.
[$wh, $wideHdr] = readCsv($wideFile);
$wideIdx = array_flip($wideHdr);
$wide    = [];      // id => [column index => value], non-empty only
$wideRows = 0;

if ($sample === 0)
{
    fwrite(STDERR, "NOTE: no --sample given, so every record is indexed. On a file
");
    fwrite(STDERR, "      this wide that needs a lot of memory; --sample=200 is plenty
");
    fwrite(STDERR, "      to prove the pivot is correct.

");
}

while (($r = fgetcsv($wh, 0, ',', '"', ESC)) !== false)
{
    if ($r === [null]) continue;
    $wideRows++;

    $id = (string)($r[0] ?? '');
    if ($id === '') continue;

    if ($sample > 0 && count($wide) >= $sample && !isset($wide[$id])) { continue; }

    $keep = [];
    foreach ($r as $i => $v)
    {
        if ($v !== '' && $v !== null) { $keep[$i] = $v; }
    }
    $wide[$id] = $keep;
}
fclose($wh);

echo "wide file : {$wideFile}\n";
echo "  columns : " . count($wideHdr) . "\n";
echo "  rows    : {$wideRows}\n";
printf("  indexed : %d record(s)%s\n", count($wide),
    $sample > 0 ? " (--sample={$sample})" : '');
printf("  memory  : %.0f MB\n", memory_get_peak_usage(true) / 1048576);

// ---- 2. walk the long file and check each cell ----
[$lh, $longHdr] = readCsv($longFile);
$lIdx = array_flip($longHdr);

foreach (['record_id', 'redcap_event_name'] as $need) {
    if (!isset($lIdx[$need])) {
        fwrite(STDERR, "ERROR: long file has no '{$need}' column.\n");
        fwrite(STDERR, "       Use RawDump or RawLongitudinalDump as the long file.\n");
        exit(1);
    }
}

$hasInstr = isset($lIdx['redcap_repeat_instrument']);
$hasInst  = isset($lIdx['redcap_repeat_instance']);

$checked = $matched = $missingCol = $mismatch = $skippedPhi = 0;
$seenIds = [];
$examples = [];
$longRows = 0;

while (($r = fgetcsv($lh, 0, ',', '"', ESC)) !== false) {
    if ($r === [null]) continue;
    $longRows++;

    $id    = (string)$r[$lIdx['record_id']];
    $event = (string)$r[$lIdx['redcap_event_name']];
    if ($id === '' || $event === '') continue;

    if ($sample > 0 && !isset($wide[$id])) continue;
    $seenIds[$id] = true;

    if (!isset($wide[$id])) {
        // With --sample we only indexed some records; skip the rest silently.
        if ($sample > 0) { continue; }
        $examples['record in long file but not wide'][] = $id;
        continue;
    }

    $instr = $hasInstr ? (string)$r[$lIdx['redcap_repeat_instrument']] : '';
    $inst  = $hasInst  ? (string)$r[$lIdx['redcap_repeat_instance']]   : '';

    foreach ($longHdr as $i => $field) {
        if (in_array($field, ['record_id','redcap_event_name',
            'redcap_repeat_instrument','redcap_repeat_instance'], true)) continue;

        // fields deliberately removed from the wide file (de-identification)
        if ($excluded !== [])
        {
            $bare = preg_replace('/___.+$/', '', $field);
            if (isset($excluded[$bare])) { $skippedPhi++; continue; }
        }

        $v = (string)($r[$i] ?? '');
        if ($v === '') continue;          // only verify values that exist

        $checked++;

        // candidate wide column names, most specific first
        $cands = [];
        if ($instr !== '' && $inst !== '') {
            $cands[] = "{$event}_{$instr}_{$inst}_{$field}";
        }
        $cands[] = null;                  // placeholder: search by suffix below

        $col = null;
        foreach ($cands as $c) {
            if ($c !== null && isset($wideIdx[$c])) { $col = $c; break; }
        }

        if ($col === null) {
            // non-repeating: {event}_{form}_{field} — form unknown here, so
            // match on prefix+suffix
            $prefix = $event . '_';
            $suffix = '_' . $field;
            foreach ($wideHdr as $h) {
                if (str_starts_with($h, $prefix) && str_ends_with($h, $suffix)) {
                    // reject an instance-numbered column when this row is not a repeat
                    if ($instr === '' && preg_match('/_\d+' . preg_quote($suffix, '/') . '$/', $h)) continue;
                    $col = $h; break;
                }
            }
        }

        if ($col === null) {
            $missingCol++;
            if (count($examples['no matching wide column'] ?? []) < $show) {
                $examples['no matching wide column'][] = "{$id} {$event} {$field}";
            }
            continue;
        }

        $wv = (string)($wide[$id][$wideIdx[$col]] ?? '');   // absent = empty
        if ($wv === $v) { $matched++; }
        else {
            $mismatch++;
            if (count($examples['value mismatch'] ?? []) < $show) {
                $examples['value mismatch'][] =
                    "{$id} {$col}\n      long='{$v}'\n      wide='{$wv}'";
            }
        }
    }
}
fclose($lh);

echo "\nlong file : {$longFile}\n";
echo "  columns : " . count($longHdr) . "\n";
echo "  rows    : {$longRows}\n";
echo "  records checked: " . count($seenIds) . ($sample > 0 ? " (--sample={$sample})" : " (all)") . "\n";

echo "\n--- cell-level verification ---\n";
printf("  non-empty cells checked : %d\n", $checked);
printf("  matched exactly         : %d (%.4f%%)\n", $matched, $checked ? $matched / $checked * 100 : 0);
printf("  no matching wide column : %d\n", $missingCol);
printf("  value mismatch          : %d\n", $mismatch);
if ($excluded !== [])
{
    printf("  skipped, excluded fields: %d (%d field names in the list)\n",
        $skippedPhi, count($excluded));
}

foreach ($examples as $label => $list) {
    echo "\n  {$label} (" . count($list) . " shown):\n";
    foreach (array_slice($list, 0, $show) as $x) { echo "    {$x}\n"; }
}

printf("\n  peak memory: %.0f MB\n", memory_get_peak_usage(true) / 1048576);

echo "\n" . ($mismatch === 0 && $missingCol === 0
    ? "PASS — every non-empty source value is present in the wide file.\n"
    : "REVIEW — see the examples above.\n");

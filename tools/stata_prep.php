#!/usr/bin/env php
<?php
/**
 * stata_prep.php — make a wide export importable into Stata.
 *
 * Stata allows 32 characters per variable name. Wide column names run to 85,
 * so `import delimited` truncates them and appends digits to force uniqueness,
 * which destroys the event / form / instance structure.
 *
 * This writes:
 *   <out>_stata.csv       the data with short, unique, structured names
 *   <out>_crosswalk.csv   short name -> original name -> label (keep this)
 *   <out>_import.do       import, variable labels, value labels, save
 *
 * Short name pattern:  d1_f07_i3_int_datetime
 *                      |  |   |  |
 *                      |  |   |  +-- REDCap field name
 *                      |  |   +----- repeat instance (omitted when absent)
 *                      |  +--------- form index, see crosswalk
 *                      +------------ event
 *
 * Usage (from the repo root):
 *   php tools/stata_prep.php /tmp/wide.csv --codebook=/tmp/emol_codebook.csv \
 *       --choices=/tmp/emol_choices.csv --out=/tmp/emol
 */
ini_set('memory_limit', '2G');
const ESC = '';

$args  = array_slice($argv, 1);
$opts  = [];
$files = [];
foreach ($args as $a)
{
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opts[$m[1]] = $m[2] ?? true; }
    else { $files[] = $a; }
}

if ($files === [])
{
    fwrite(STDERR, "Usage: php stata_prep.php <wide.csv> [--codebook=cb.csv] [--choices=ch.csv] [--out=/tmp/emol]\n");
    exit(1);
}

$in       = $files[0];
$out      = $opts['out']      ?? preg_replace('/\.csv$/', '', $in);
$cbPath   = $opts['codebook'] ?? null;
$chPath   = $opts['choices']  ?? null;

if (!is_file($in)) { fwrite(STDERR, "ERROR: not found: {$in}\n"); exit(1); }

// ---- labels from the codebook, if supplied ----
$labelOf = [];
$fieldOf = [];
if ($cbPath !== null && is_file($cbPath))
{
    $fh = fopen($cbPath, 'r');
    $h  = fgetcsv($fh, 0, ',', '"', ESC);
    $ix = array_flip($h);
    while (($r = fgetcsv($fh, 0, ',', '"', ESC)) !== false)
    {
        if ($r === [null]) continue;
        $col = $r[$ix['column_name']] ?? '';
        if ($col === '') continue;
        $labelOf[$col] = $r[$ix['question_label']] ?? '';
        $fieldOf[$col] = $r[$ix['field_name']]     ?? '';
    }
    fclose($fh);
    echo "codebook : {$cbPath} (" . count($labelOf) . " columns)\n";
}

/** dayN_arm_1 -> dN ; discharge_arm_1 -> dsch ; baseline_arm_1 -> bl */
function shortEvent(string $ev): string
{
    if ($ev === '') return '';
    if (preg_match('/^day(\d+)_arm_\d+$/', $ev, $m)) return 'd' . $m[1];
    $base = preg_replace('/_arm_\d+$/', '', $ev);
    $map  = ['discharge' => 'dsch', 'other_forms' => 'oth', 'baseline' => 'bl',
             'screening' => 'scr', 'enrolment' => 'enr', 'enrollment' => 'enr'];
    return $map[$base] ?? substr(preg_replace('/[^a-z0-9]/', '', $base), 0, 4);
}

$fh     = fopen($in, 'r') ?: exit("Cannot open {$in}\n");
$header = fgetcsv($fh, 0, ',', '"', ESC);
$nCol   = count($header);

echo "input    : {$in}\n";
echo "columns  : {$nCol}\n\n";

// ---- pass 1: split every column, index the forms ----
$formIdx = [];
$parts   = [];

foreach ($header as $col)
{
    $ev = $form = $inst = ''; $field = $col;

    if (preg_match('/^((?:day\d+|[a-z_]+?)_arm_\d+)_(.*)$/', $col, $m))
    {
        $ev   = $m[1];
        $rest = $m[2];

        // field name from the codebook is the reliable way to find the boundary
        $known = $fieldOf[$col] ?? '';
        if ($known !== '' && str_ends_with($rest, $known))
        {
            $mid   = rtrim(substr($rest, 0, strlen($rest) - strlen($known)), '_');
            $field = $known;
            if (preg_match('/^(.*)_(\d+)$/', $mid, $mm)) { $form = $mm[1]; $inst = $mm[2]; }
            else { $form = $mid; }
        }
        elseif (preg_match('/^(.*?)_(\d+)_(.*)$/', $rest, $mm))
        {
            $form = $mm[1]; $inst = $mm[2]; $field = $mm[3];
        }
        else
        {
            $field = $rest;
        }
    }

    if ($form !== '' && !isset($formIdx[$form])) { $formIdx[$form] = count($formIdx) + 1; }
    $parts[] = [$col, $ev, $form, $inst, $field];
}

// ---- pass 2: build short, unique names ----
$short = [];
$used  = [];
$clash = 0;

foreach ($parts as [$col, $ev, $form, $inst, $field])
{
    $bits = [];
    if ($ev !== '')   { $bits[] = shortEvent($ev); }
    if ($form !== '') { $bits[] = sprintf('f%02d', $formIdx[$form]); }
    if ($inst !== '') { $bits[] = 'i' . $inst; }

    $prefix = $bits ? implode('_', $bits) . '_' : '';
    $fld    = strtolower(preg_replace('/[^A-Za-z0-9_]/', '_', $field));
    $name   = $prefix . $fld;

    if (strlen($name) > 32)
    {
        $name = $prefix . substr($fld, 0, max(1, 32 - strlen($prefix)));
    }
    $name = rtrim($name, '_');
    if ($name === '' || ctype_digit($name[0])) { $name = 'v_' . $name; }

    $cand = $name; $n = 1;
    while (isset($used[strtolower($cand)]))
    {
        $clash++;
        $suffix = '_' . $n++;
        $cand   = substr($name, 0, 32 - strlen($suffix)) . $suffix;
    }
    $used[strtolower($cand)] = true;
    $short[] = $cand;
}

printf("forms    : %d\n", count($formIdx));
printf("renamed  : %d (%d needed a uniqueness suffix)\n", $nCol, $clash);
printf("longest  : %d chars\n\n", max(array_map('strlen', $short)));

// ---- crosswalk ----
$cw = fopen($out . '_crosswalk.csv', 'w');
fputcsv($cw, ['stata_name','original_column','event','form','instance','field_name','label'], ',', '"', ESC);
foreach ($parts as $i => [$col, $ev, $form, $inst, $field])
{
    fputcsv($cw, [$short[$i], $col, $ev, $form, $inst, $field, $labelOf[$col] ?? ''], ',', '"', ESC);
}
fclose($cw);

// ---- data with the new header ----
$oh = fopen($out . '_stata.csv', 'w');
fputcsv($oh, $short, ',', '"', ESC);
$rows = 0;
while (($r = fgetcsv($fh, 0, ',', '"', ESC)) !== false)
{
    if ($r === [null]) continue;
    fputcsv($oh, $r, ',', '"', ESC);
    $rows++;
}
fclose($fh); fclose($oh);

// ---- do-file ----
$do  = "* " . str_repeat('-', 68) . "\n";
$do .= "* {$out}_import.do — import the Emollient wide export into Stata\n";
$do .= "* Generated " . date('Y-m-d H:i') . " from " . basename($in) . "\n";
$do .= "*\n";
$do .= "* Requires Stata/SE or Stata/MP: this file has {$nCol} variables and\n";
$do .= "* Stata/BE is limited to 2048.\n";
$do .= "* " . str_repeat('-', 68) . "\n\n";
$do .= "clear all\nset more off\n";
$do .= "set maxvar " . min(120000, max(10000, $nCol + 500)) . "\n\n";
$do .= "import delimited using \"" . basename($out) . "_stata.csv\", ///\n";
$do .= "    varnames(1) stringcols(_all) clear\n\n";
$do .= "* Variable labels " . str_repeat('-', 52) . "\n";

$n = 0;
foreach ($parts as $i => [$col, $ev, $form, $inst, $field])
{
    $lab = trim((string)($labelOf[$col] ?? ''));
    if ($lab === '') continue;
    $lab = str_replace(['"', "\n", "\r"], ['', ' ', ' '], $lab);
    if (strlen($lab) > 76) { $lab = substr($lab, 0, 76); }
    $do .= 'label variable ' . $short[$i] . ' "' . $lab . "\"\n";
    $n++;
}

$do .= "\n* " . str_repeat('-', 68) . "\n";
$do .= "* Value labels are NOT applied automatically: every variable is imported\n";
$do .= "* as a string so nothing is coerced. Codes such as Y / N / NI are in\n";
$do .= "* " . basename($out) . "_choices.csv. Convert only the variables you need, e.g.\n";
$do .= "*\n";
$do .= "*   encode d1_f07_i1_int_emoliate_baby, gen(emol_d1_1)\n";
$do .= "*   destring d1_f07_i1_int_oil_qty, gen(oil_d1_1) force\n";
$do .= "* " . str_repeat('-', 68) . "\n\n";
$do .= "compress\n";
$do .= "save \"" . basename($out) . ".dta\", replace\n";
$do .= "describe, short\n";

file_put_contents($out . '_import.do', $do);

printf("written:\n");
printf("  %-28s %d rows, %d variables\n", basename($out) . '_stata.csv', $rows, $nCol);
printf("  %-28s %d rows\n", basename($out) . '_crosswalk.csv', $nCol);
printf("  %-28s %d variable labels\n", basename($out) . '_import.do', $n);

#!/usr/bin/env php
<?php
/**
 * select_fields.php — choose which forms and variables go into a wide export.
 *
 * Options (comma-separated lists; any combination):
 *   --forms=a,b            include every variable of these forms
 *   --vars=x,y             include these variables
 *   --exclude-forms=a,b    drop every variable of these forms
 *   --exclude-vars=x,y     drop these variables
 *   --fields=FILE          the same rules from a file, one per line:
 *                            x         include variable     -x         exclude variable
 *                            form:a    include form         -form:a    exclude form
 *
 *   --fieldmap=fields.csv  REQUIRED. field_name -> form_name, from
 *                          tools/export_field_map.php. This is how a column is
 *                          assigned to its form reliably.
 *
 * Precedence, applied per column:
 *   1. record_id is always kept
 *   2. a VARIABLE rule beats a FORM rule         (more specific wins)
 *   3. at the same level, EXCLUDE beats INCLUDE
 *   4. if any include was given, it is a whitelist: anything not included is
 *      dropped. With only excludes, everything else is kept.
 *   Identifying fields are removed afterwards by de-identification, whatever
 *   is listed here.
 *
 * Usage:
 *   php select_fields.php in.csv out.csv --fieldmap=fields.csv --forms=daily_control_log
 *   php select_fields.php in.csv --fieldmap=fields.csv --forms=... --check-only
 *   php select_fields.php --fieldmap=fields.csv --list          list forms and variables
 *
 * Exit codes: 0 ok · 1 error · 3 a listed form/variable is not in the dictionary
 */
ini_set('memory_limit', '1G');
const ESC = '';

$opts = []; $files = [];
foreach (array_slice($argv, 1) as $a)
{
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) { $opts[$m[1]] = $m[2] ?? true; }
    else { $files[] = $a; }
}
$split = fn($k) => array_values(array_filter(array_map('trim', explode(',', (string)($opts[$k] ?? ''))), 'strlen'));

// ---- the data dictionary: field -> form ----
if (empty($opts['fieldmap']) || !is_file($opts['fieldmap']))
{
    fwrite(STDERR, "ERROR: --fieldmap=fields.csv is required (php tools/export_field_map.php --out=fields.csv)\n");
    exit(1);
}
$formOf = []; $varsOf = [];
$fm = fopen($opts['fieldmap'], 'r'); $h = fgetcsv($fm, 0, ',', '"', ESC); $ix = array_flip($h);
while (($r = fgetcsv($fm, 0, ',', '"', ESC)) !== false)
{
    if ($r === [null]) continue;
    $f = $r[$ix['field_name']] ?? ''; $form = $r[$ix['form_name']] ?? '';
    if ($f === '' || $form === '') continue;
    if (($r[$ix['field_type']] ?? '') === 'checkbox_option') continue;   // resolved via the base field
    $formOf[$f] = $form;
    $varsOf[$form][] = $f;
}
fclose($fm);

// ---- --list: show what can be chosen, then stop ----
if (isset($opts['list']))
{
    ksort($varsOf);
    printf("%-52s %s\n", 'form', 'variables');
    foreach ($varsOf as $form => $vs) { printf("  %-50s %d\n", $form, count($vs)); }
    printf("\n%d forms, %d variables.\n", count($varsOf), count($formOf));
    if (isset($opts['vars-of']))
    {
        $f = $opts['vars-of'];
        echo "\nVariables of {$f}:\n";
        foreach ($varsOf[$f] ?? [] as $v) { echo "  {$v}\n"; }
        if (!isset($varsOf[$f])) { echo "  (no such form)\n"; }
    }
    exit(0);
}

// ---- collect rules from the command line and the file ----
$inForm = array_fill_keys($split('forms'), 0);
$inVar  = array_fill_keys($split('vars'), 0);
$exForm = array_fill_keys($split('exclude-forms'), 0);
$exVar  = array_fill_keys($split('exclude-vars'), 0);

if (!empty($opts['fields']))
{
    if (!is_file($opts['fields'])) { fwrite(STDERR, "ERROR: not found: {$opts['fields']}\n"); exit(1); }
    foreach (file($opts['fields'], FILE_IGNORE_NEW_LINES) as $line)
    {
        $line = trim(preg_replace('/\s+#.*$/', '', $line));
        if ($line === '' || $line[0] === '#') continue;
        $neg  = $line[0] === '-'; if ($neg) { $line = ltrim(substr($line, 1)); }
        if (str_starts_with($line, 'form:')) { $name = trim(substr($line, 5)); $neg ? $exForm[$name] = 0 : $inForm[$name] = 0; }
        else                                 { $neg ? $exVar[$line] = 0 : $inVar[$line] = 0; }
    }
}

if (!$inForm && !$inVar && !$exForm && !$exVar)
{
    fwrite(STDERR, "ERROR: no forms or variables given (--forms / --vars / --exclude-forms / --exclude-vars / --fields)\n");
    exit(1);
}
$whitelist = (bool)($inForm || $inVar);

// ---- validate names against the DICTIONARY, not the file ----
$bad = [];
foreach ([$inForm, $exForm] as $set) foreach ($set as $f => $_) if (!isset($varsOf[$f])) $bad[] = "form '{$f}'";
foreach ([$inVar,  $exVar]  as $set) foreach ($set as $v => $_)
{
    $base = preg_replace('/___.+$/', '', $v);
    if (!isset($formOf[$base])) $bad[] = "variable '{$v}'";
}

// ---- resolve every column to (field, form) ----
if ($files === []) { fwrite(STDERR, "ERROR: no input file\n"); exit(1); }
$check = isset($opts['check-only']);
if (!$check && count($files) < 2) { fwrite(STDERR, "ERROR: no output file (or use --check-only)\n"); exit(1); }

$fh  = fopen($files[0], 'r') ?: exit("Cannot open {$files[0]}\n");
$hdr = fgetcsv($fh, 0, ',', '"', ESC);

$known = array_keys($formOf);
usort($known, fn($a, $b) => strlen($b) <=> strlen($a));     // longest match wins

$keep = []; $why = ['always' => 0, 'var-in' => 0, 'var-out' => 0, 'form-in' => 0,
                    'form-out' => 0, 'default-out' => 0, 'default-in' => 0];
$unresolved = 0;

foreach ($hdr as $i => $col)
{
    if ($i === 0 || $col === 'record_id') { $keep[] = $i; $why['always']++; continue; }

    $base = preg_replace('/___.+$/', '', $col);
    $code = str_contains($col, '___') ? substr($col, strpos($col, '___')) : '';
    $field = null;
    foreach ($known as $f) { if ($base === $f || str_ends_with($base, '_' . $f)) { $field = $f; break; } }
    $form  = $field !== null ? $formOf[$field] : null;
    if ($field === null) { $unresolved++; }

    $varHit = fn($set) => $field !== null && (isset($set[$field]) || ($code !== '' && isset($set[$field . $code])));
    $varKey = fn($set) => isset($set[$field . $code]) ? $field . $code : $field;

    if     ($varHit($exVar))                      { $exVar[$varKey($exVar)]++;  $why['var-out']++;  continue; }
    elseif ($varHit($inVar))                      { $inVar[$varKey($inVar)]++;  $why['var-in']++;   $keep[] = $i; }
    elseif ($form !== null && isset($exForm[$form])) { $exForm[$form]++;         $why['form-out']++; continue; }
    elseif ($form !== null && isset($inForm[$form])) { $inForm[$form]++;         $why['form-in']++;  $keep[] = $i; }
    elseif ($whitelist)                           { $why['default-out']++; continue; }
    else                                          { $why['default-in']++;  $keep[] = $i; }
}

// ---- report ----
printf("input   : %s (%d columns)\n", $files[0], count($hdr));
printf("mode    : %s\n", $whitelist ? 'INCLUDE list — only what is named is kept' : 'EXCLUDE only — everything else is kept');
printf("keeping : %d columns including record_id\n\n", count($keep));

$show = function (string $label, array $set) {
    foreach ($set as $n => $c) printf("  %-10s %-44s %6d column(s)%s\n", $label, $n, $c, $c ? '' : '   (none in this file)');
};
$show('+form', $inForm); $show('+var', $inVar); $show('-form', $exForm); $show('-var', $exVar);

printf("\n  decided by:  variable rule %d · form rule %d · default %d\n",
    $why['var-in'] + $why['var-out'], $why['form-in'] + $why['form-out'], $why['default-in'] + $why['default-out']);
if ($unresolved) { printf("  %d column(s) could not be matched to a dictionary field (treated by the default rule)\n", $unresolved); }

if ($bad)
{
    printf("\nNOT IN THE DATA DICTIONARY — probably a typo:\n  %s\n", implode("\n  ", $bad));
    echo "Run with --list to see the valid form names, --list --vars-of=FORM for its variables.\n";
}
$empty = array_filter(array_merge($inForm, $inVar), fn($c) => $c === 0);
if ($empty && !$bad)
{
    echo "\nNote: listed names with no columns exist in the dictionary but are blank for every\n";
    echo "baby in this extract (the R route drops such columns). Not an error.\n";
}

if ($check) { fclose($fh); echo "\n--check-only: nothing written.\n"; exit($bad ? 3 : 0); }
if ($bad && !isset($opts['allow-missing'])) { fclose($fh); fwrite(STDERR, "\nERROR: refusing to write — fix the names above.\n"); exit(3); }
if (count($keep) <= 1) { fclose($fh); fwrite(STDERR, "\nERROR: nothing selected — refusing to write a file with only record_id.\n"); exit(1); }

$oh = fopen($files[1], 'w') ?: exit("Cannot write {$files[1]}\n");
fputcsv($oh, array_map(fn($i) => $hdr[$i], $keep), ',', '"', ESC);
$rows = 0;
while (($r = fgetcsv($fh, 0, ',', '"', ESC)) !== false)
{
    if ($r === [null]) continue;
    fputcsv($oh, array_map(fn($i) => $r[$i] ?? '', $keep), ',', '"', ESC);
    $rows++;
}
fclose($fh); fclose($oh);
printf("\nwritten : %s — %d rows, %d columns\n", $files[1], $rows, count($keep));

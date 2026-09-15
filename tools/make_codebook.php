#!/usr/bin/env php
<?php
/**
 * make_codebook.php — build a data dictionary for a wide dump.
 *
 * Produces three files so an analyst who has never seen REDCap can read the
 * export without asking anyone:
 *
 *   <out>_codebook.csv   one row per wide column: event, form, instance,
 *                        field, question label, type, and the decoded
 *                        answer options (1=Yes, 2=No, ...)
 *   <out>_labels.csv     ONE line — the question labels in wide-column order.
 *                        Paste as row 2 of the dump for a human-readable
 *                        header, or keep beside it.
 *   <out>_choices.csv    one row per coded answer: field, code, meaning.
 *                        The lookup table for decoding dropdowns/radios.
 *
 * Two ways to choose the column set:
 *
 *   --from-header=wide.csv   RECOMMENDED. Reads the delivered file's header,
 *                            so the codebook describes exactly the file the
 *                            analyst has. Works for any route (PHP blueprint
 *                            or R pivot, with or without --template) and
 *                            needs only ONE metadata call — no streaming.
 *
 *   --report=WideDump        Predicts the columns from the blueprint before
 *                            the export exists. Streams the project to build
 *                            the repeat map, so it is much slower.
 *
 * Usage (from the repo root):
 *   php tools/make_codebook.php --project=Emollient --from-header=/tmp/wide.csv --out=/tmp/emol
 *   php tools/make_codebook.php --project=Emollient --report=WideDump --out=/tmp/emol
 */
ini_set('memory_limit', '2G');
error_reporting(E_ALL);
ini_set('display_errors', 1);

$repoRoot = dirname(__DIR__);

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;
use CEL\Shared\Domain\Metadata\WideColumnBlueprintBuilder;

const ESC = '';

$opts    = getopt('', ['project:', 'report:', 'out:', 'chunk::', 'from-header:']);
$project = $opts['project'] ?? 'Emollient';
$report  = $opts['report']  ?? 'WideDump';
$out     = $opts['out']     ?? '/tmp/' . $project . '_' . $report;
$fromHdr = $opts['from-header'] ?? null;

$projectsRoot = $repoRoot . '/projects';
$cfg          = require "{$projectsRoot}/{$project}/config.php";
$client       = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$definition = [];

if ($fromHdr === null)
{
    $allReports = require "{$projectsRoot}/{$project}/reports.php";

    if (!isset($allReports[$report]))
    {
        fwrite(STDERR, "ERROR: Report '{$report}' not defined.\n");
        exit(1);
    }

    $definition = $allReports[$report];
}

$filterForms  = $definition['forms']  ?? [];
$filterEvents = $definition['events'] ?? [];
$chunkSize    = (int)($opts['chunk'] ?? $definition['chunk_size'] ?? 200);

echo "Project : {$project}\n";
echo $fromHdr !== null ? "Source  : header of {$fromHdr}\n\n" : "Report  : {$report}\n\n";

$metadata      = $client->fetchMetadata();
$projectEvents = $client->fetchEvents();
$formEventMap  = $client->fetchFormEventMapping();

// ---- field lookup ----
$byField = [];
foreach ($metadata as $m) { $byField[$m['field_name']] = $m; }

// ---- decode choice strings: "1, Yes | 2, No" ----
function parseChoices(string $raw): array
{
    $out = [];
    foreach (explode('|', $raw) as $part)
    {
        $part = trim($part);
        if ($part === '') continue;
        $bits = explode(',', $part, 2);
        if (count($bits) === 2) { $out[trim($bits[0])] = trim($bits[1]); }
    }
    return $out;
}

// ---- column set ------------------------------------------------------------
if ($fromHdr !== null)
{
    if (!is_file($fromHdr))
    {
        fwrite(STDERR, "ERROR: Not found: {$fromHdr}\n");
        exit(1);
    }

    $fh = fopen($fromHdr, 'r') ?: exit("Cannot open {$fromHdr}\n");
    $cols = fgetcsv($fh, 0, ',', '"', ESC);
    fclose($fh);

    if ($cols === false || $cols === [null])
    {
        fwrite(STDERR, "ERROR: {$fromHdr} has no header row.\n");
        exit(1);
    }

    echo "  columns read from header: " . count($cols) . "\n\n";
}
else
{
    echo "Streaming to build the repeat map...\n";
    $repeatMap = [];
    $rows = 0;

    foreach ($client->stream([], $filterForms, $filterEvents, $chunkSize) as $row)
    {
        $rows++;
        $e = $row['redcap_event_name']        ?? null;
        $f = $row['redcap_repeat_instrument'] ?? null;
        $i = (int)($row['redcap_repeat_instance'] ?? 0);
        unset($row);
        if (!$e || !$f || !$i) continue;
        if ($filterForms && !in_array($f, $filterForms, true)) continue;
        $repeatMap[$e][$f] = max($repeatMap[$e][$f] ?? 0, $i);
    }

    echo "  rows streamed: {$rows}\n";

    $blueprint = (new WideColumnBlueprintBuilder())->build(
        $metadata, $projectEvents, $formEventMap, $filterForms ?: null, null, $repeatMap
    );
    $cols = $blueprint['columns'] ?? [];
    echo "  columns: " . count($cols) . "\n\n";
}

// ---- event and form names, longest first so prefixes match greedily ----
$eventNames = [];
foreach ($projectEvents as $e)
{
    $n = $e['unique_event_name'] ?? ($e['event_name'] ?? null);
    if ($n) { $eventNames[] = $n; }
}
if (isset($repeatMap)) { foreach (array_keys($repeatMap) as $e) { $eventNames[] = $e; } }
$eventNames = array_unique($eventNames);
usort($eventNames, fn($a, $b) => strlen($b) <=> strlen($a));

$formNames = array_values(array_unique(array_column($metadata, 'form_name')));
usort($formNames, fn($a, $b) => strlen($b) <=> strlen($a));

/** Split a wide column into event / form / instance / field. */
function splitColumn(string $col, array $events, array $forms, array $byField): array
{
    $event = $form = ''; $instance = ''; $rest = $col;

    foreach ($events as $e)
    {
        if (str_starts_with($rest, $e . '_')) { $event = $e; $rest = substr($rest, strlen($e) + 1); break; }
    }
    foreach ($forms as $f)
    {
        if (str_starts_with($rest, $f . '_')) { $form = $f; $rest = substr($rest, strlen($f) + 1); break; }
    }
    if (preg_match('/^(\d+)_(.*)$/', $rest, $m)) { $instance = $m[1]; $rest = $m[2]; }

    // checkbox columns are field___CODE
    $field = $rest; $checkbox = '';
    if (preg_match('/^(.*)___(.+)$/', $rest, $m) && isset($byField[$m[1]]))
    {
        $field = $m[1]; $checkbox = $m[2];
    }

    return [$event, $form, $instance, $field, $checkbox];
}

// ---- write codebook + labels ----
$cbPath  = $out . '_codebook.csv';
$lbPath  = $out . '_labels.csv';
$chPath  = $out . '_choices.csv';

$cb = fopen($cbPath, 'w');
fputcsv($cb, ['column_name','event','form','instance','field_name','question_label',
              'field_type','checkbox_option','answer_options','validation','required','branching_logic'],
        ',', '"', ESC);

$labels  = [];
$unknown = 0;

foreach ($cols as $col)
{
    [$event, $form, $instance, $field, $checkbox] = splitColumn($col, $eventNames, $formNames, $byField);
    $m = $byField[$field] ?? null;

    if ($m === null) { $unknown++; }

    $choices = $m ? parseChoices((string)($m['select_choices_or_calculations'] ?? '')) : [];
    $label   = $m['field_label'] ?? '';
    $label   = trim(preg_replace('/\s+/', ' ', strip_tags($label)));

    $cbOpt = '';
    if ($checkbox !== '')
    {
        $cbOpt = $checkbox . ($choices[$checkbox] ?? '' ? ' = ' . $choices[$checkbox] : '');
        $label = $label . ' [' . ($choices[$checkbox] ?? $checkbox) . ']';
    }

    $optStr = '';
    foreach ($choices as $code => $meaning) { $optStr .= "{$code} = {$meaning}; "; }

    fputcsv($cb, [
        $col, $event, $form, $instance, $field, $label,
        $m['field_type'] ?? '', $cbOpt, rtrim($optStr, '; '),
        $m['text_validation_type_or_show_slider_number'] ?? '',
        ($m['required_field'] ?? '') === 'y' ? 'yes' : '',
        trim((string)($m['branching_logic'] ?? '')),
    ], ',', '"', ESC);

    // human label for the label row; keep event+instance so it stays unique
    $prefix = $event !== '' ? $event : '';
    if ($instance !== '') { $prefix .= " #{$instance}"; }
    $labels[] = $label === '' ? $col : trim($prefix . ' — ' . $label);
}
fclose($cb);

$lb = fopen($lbPath, 'w');
fputcsv($lb, $labels, ',', '"', ESC);
fclose($lb);

// ---- choices lookup ----
$ch = fopen($chPath, 'w');
fputcsv($ch, ['field_name','form','question_label','code','meaning'], ',', '"', ESC);
$nChoice = 0;
foreach ($metadata as $m)
{
    $choices = parseChoices((string)($m['select_choices_or_calculations'] ?? ''));
    if (!$choices) continue;
    $lab = trim(preg_replace('/\s+/', ' ', strip_tags((string)($m['field_label'] ?? ''))));
    foreach ($choices as $code => $meaning)
    {
        fputcsv($ch, [$m['field_name'], $m['form_name'], $lab, $code, $meaning], ',', '"', ESC);
        $nChoice++;
    }
}
fclose($ch);

echo "Written:\n";
printf("  %-28s %d rows (one per wide column)\n", basename($cbPath), count($cols));
printf("  %-28s 1 row  (labels in column order)\n", basename($lbPath));
printf("  %-28s %d rows (code -> meaning)\n", basename($chPath), $nChoice);
if ($unknown > 0)
{
    printf("\n  NOTE: %d column(s) could not be matched to a dictionary field.\n", $unknown);
    printf("        They are listed with an empty label rather than dropped.\n");
}

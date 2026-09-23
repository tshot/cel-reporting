#!/usr/bin/env php
<?php
/**
 * export_field_map.php — dump the REDCap field -> form mapping.
 *
 * The long export (RawDump) names the form only on REPEATING rows, via
 * redcap_repeat_instrument. For non-repeating rows the form has to come from
 * the data dictionary. This writes that mapping so wide_pivot.R can build
 * the same column names the engine would.
 *
 * One metadata call. No record streaming, so it cannot run out of memory.
 *
 * Columns: field_name, form_name, field_type, field_order, form_order.
 * field_order is the position in the REDCap data dictionary; form_order is the
 * position of the form. Together they let the wide export put columns in
 * dictionary order rather than alphabetical order.
 *
 * --events=FILE also writes the project's events in their defined order.
 *
 * Usage (from the repo root):
 *   php tools/export_field_map.php --project=Emollient --out=/tmp/fields.csv
 *   php tools/export_field_map.php --project=Emollient --out=/tmp/fields.csv \
 *       --events=/tmp/events.csv
 */
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

$opts    = getopt('', ['project:', 'out:', 'events::']);
$project = $opts['project'] ?? 'Emollient';
$out     = $opts['out']     ?? '/tmp/fields.csv';

$cfg    = require "{$repoRoot}/projects/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$metadata = $client->fetchMetadata();

$fh = fopen($out, 'w') ?: exit("Cannot write {$out}\n");
fputcsv($fh, ['field_name', 'form_name', 'field_type', 'field_order', 'form_order'], ',', '"', '');

// form_order: the position of each form's first field in the dictionary
$formOrder = [];
$i = 0;
foreach ($metadata as $m)
{
    $form = (string)($m['form_name'] ?? '');
    if ($form !== '' && !isset($formOrder[$form])) { $formOrder[$form] = ++$i; }
}

$n = 0;
$pos = 0;
foreach ($metadata as $m)
{
    $field = (string)($m['field_name'] ?? '');
    $form  = (string)($m['form_name'] ?? '');
    $type  = (string)($m['field_type'] ?? '');
    if ($field === '' || $form === '') { continue; }

    $pos++;
    fputcsv($fh, [$field, $form, $type, $pos, $formOrder[$form]], ',', '"', '');
    $n++;

    // checkbox fields appear in exports as field___CODE, one column per option
    if ($type === 'checkbox')
    {
        $opt = 0;
        foreach (explode('|', (string)($m['select_choices_or_calculations'] ?? '')) as $part)
        {
            $bits = explode(',', trim($part), 2);
            if (count($bits) !== 2) { continue; }
            $opt++;
            // keep options adjacent to their parent field, in choice order
            fputcsv($fh, [$field . '___' . trim($bits[0]), $form, 'checkbox_option',
                          $pos + ($opt / 1000), $formOrder[$form]], ',', '"', '');
            $n++;
        }
    }
}
fclose($fh);

if (!empty($opts['events']))
{
    $ev = fopen($opts['events'], 'w') ?: exit("Cannot write {$opts['events']}\n");
    fputcsv($ev, ['event_name', 'event_order'], ',', '"', '');
    $k = 0;
    foreach ($client->fetchEvents() as $e)
    {
        $name = $e['unique_event_name'] ?? ($e['event_name'] ?? null);
        if (!$name) { continue; }
        fputcsv($ev, [$name, ++$k], ',', '"', '');
    }
    fclose($ev);
    printf("Wrote %s — %d events in project order\n", $opts['events'], $k);
}

printf("Wrote %s — %d rows (%d dictionary fields)\n", $out, $n, count($metadata));

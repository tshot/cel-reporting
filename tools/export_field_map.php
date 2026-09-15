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
 * Usage (from the repo root):
 *   php tools/export_field_map.php --project=Emollient --out=/tmp/fields.csv
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

$opts    = getopt('', ['project:', 'out:']);
$project = $opts['project'] ?? 'Emollient';
$out     = $opts['out']     ?? '/tmp/fields.csv';

$cfg    = require "{$repoRoot}/projects/{$project}/config.php";
$client = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$metadata = $client->fetchMetadata();

$fh = fopen($out, 'w') ?: exit("Cannot write {$out}\n");
fputcsv($fh, ['field_name', 'form_name', 'field_type'], ',', '"', '');

$n = 0;
foreach ($metadata as $m)
{
    $field = (string)($m['field_name'] ?? '');
    $form  = (string)($m['form_name'] ?? '');
    $type  = (string)($m['field_type'] ?? '');
    if ($field === '' || $form === '') { continue; }

    fputcsv($fh, [$field, $form, $type], ',', '"', '');
    $n++;

    // checkbox fields appear in exports as field___CODE, one column per option
    if ($type === 'checkbox')
    {
        foreach (explode('|', (string)($m['select_choices_or_calculations'] ?? '')) as $part)
        {
            $bits = explode(',', trim($part), 2);
            if (count($bits) !== 2) { continue; }
            fputcsv($fh, [$field . '___' . trim($bits[0]), $form, 'checkbox_option'], ',', '"', '');
            $n++;
        }
    }
}
fclose($fh);

printf("Wrote %s — %d rows (%d dictionary fields)\n", $out, $n, count($metadata));

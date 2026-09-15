#!/usr/bin/env php
<?php

/**
 * generate_label_map.php
 *
 * Generates label_map.php and/or site_labels.php for a project by pulling
 * option codes and labels directly from REDCap metadata.
 *
 * Can be run for one or both outputs in a single call:
 *
 *   # Generate label_map.php only (SES categoricals)
 *   php tools/generate_label_map.php \
 *     --project=Emollient \
 *     --fields=ses_religion,ses_caste,ses_mthr_edu_qual,ses_head_edu_qual
 *
 *   # Generate site_labels.php only (hospital codes)
 *   php tools/generate_label_map.php \
 *     --project=Emollient \
 *     --sites=baby_hosp_code
 *
 *   # Generate both in one call (recommended — single REDCap metadata fetch)
 *   php tools/generate_label_map.php \
 *     --project=Emollient \
 *     --fields=ses_religion,ses_caste,ses_mthr_edu_qual,ses_head_edu_qual \
 *     --sites=baby_hosp_code
 *
 * Options:
 *   --project        Project folder name under projects/  (required)
 *   --fields         Comma-separated REDCap field names for label_map.php
 *                    Each field becomes a keyed block:
 *                      'field_name' => ['CODE' => 'Label', ...]
 *   --sites          One REDCap field name whose options are the hospital
 *                    codes. baby_hosp_code and enr_hosp_code share the same
 *                    option set — either field name produces the same result.
 *                    Output is a flat map: 'GSVM' => 'GSVM Kanpur', ...
 *   --label-output   Override output path for label_map.php
 *   --site-output    Override output path for site_labels.php
 *   --dry-run        Print generated PHP to stdout, do not write files
 *
 * Re-run whenever the REDCap data dictionary changes.
 * Generated files contain no credentials and are safe to commit.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ── Locate monorepo roots ──────────────────────────────────────────────────────
// This script lives in tools/ at the monorepo root.
// Folder layout expected:
//
//   cel-reporting/
//   ├── reporting-engine/   <- autoloader lives here
//   ├── projects/           <- output files are written here
//   └── tools/
//       └── generate_label_map.php  <- this file
//
// __DIR__ always resolves to the real path of this script regardless of where
// you run it from (e.g. cd /var/www && php tools/generate_label_map.php).
// We use __DIR__ directly rather than realpath() so that paths are always
// correct even if the directories do not exist yet (realpath returns false
// for non-existent paths, silently corrupting every path built from it).

$monorepoRoot = dirname(__DIR__);
$engineRoot   = $monorepoRoot . '/reporting-engine';
$projectsRoot = $monorepoRoot . '/projects';

if (!file_exists($engineRoot . '/vendor/autoload.php'))
{
    echo "ERROR: Could not locate reporting-engine/vendor/autoload.php\n";
    echo "       Looked in: {$engineRoot}/vendor/autoload.php\n";
    echo "       Run 'composer install' inside reporting-engine/ first.\n";
    exit(1);
}

if (!is_dir($projectsRoot))
{
    echo "ERROR: projects/ directory not found at: {$projectsRoot}\n";
    exit(1);
}

require $engineRoot . '/vendor/autoload.php';

use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

// ── Parse CLI options ──────────────────────────────────────────────────────────

$opts = getopt('', [
    'project:',
    'fields::',
    'sites::',
    'label-output::',
    'site-output::',
    'dry-run',
    'english-only',   // strip Hindi (or any non-ASCII) from bilingual labels
]);

$project     = $opts['project']      ?? null;
$fieldsCsv   = $opts['fields']       ?? null;
$siteField   = $opts['sites']        ?? null;
$dryRun      = isset($opts['dry-run']);
$englishOnly = isset($opts['english-only']);

if (!$project || (!$fieldsCsv && !$siteField))
{
    echo "Usage:\n";
    echo "  php tools/generate_label_map.php --project=Emollient [--fields=...] [--sites=...]\n\n";
    echo "Examples:\n";
    echo "  # Both files in one call (recommended)\n";
    echo "  php tools/generate_label_map.php \\\n";
    echo "    --project=Emollient \\\n";
    echo "    --fields=ses_religion,ses_caste,ses_mthr_edu_qual,ses_head_edu_qual \\\n";
    echo "    --sites=baby_hosp_code\n\n";
    echo "  # label_map.php only\n";
    echo "  php tools/generate_label_map.php --project=Emollient --fields=ses_religion,ses_caste\n\n";
    echo "  # site_labels.php only\n";
    echo "  php tools/generate_label_map.php --project=Emollient --sites=baby_hosp_code\n\n";
    echo "Options:\n";
    echo "  --fields         Comma-separated field names -> label_map.php\n";
    echo "  --sites          One hospital code field name -> site_labels.php\n";
    echo "  --english-only   Strip non-ASCII (Hindi) text from bilingual labels\n";
    echo "  --label-output   Override output path for label_map.php\n";
    echo "  --site-output    Override output path for site_labels.php\n";
    echo "  --dry-run        Print output without writing files\n";
    exit(1);
}

// ── Resolve and validate project paths ────────────────────────────────────────

$projectDir = "{$projectsRoot}/{$project}";
$configPath = "{$projectDir}/config.php";

echo "Monorepo root : {$monorepoRoot}\n";
echo "Project dir   : {$projectDir}\n\n";

if (!is_dir($projectDir))
{
    echo "ERROR: Project folder not found: {$projectDir}\n";
    echo "       Available projects:\n";
    foreach (glob("{$projectsRoot}/*", GLOB_ONLYDIR) as $d) 
    {
        echo "         - " . basename($d) . "\n";
    }
    exit(1);
}

if (!file_exists($configPath))
{
    echo "ERROR: config.php not found at {$configPath}\n";
    exit(1);
}

$config = require $configPath;

// ── Fetch metadata from REDCap — one call shared by both generators ────────────

echo "Connecting to REDCap at {$config['api_url']} ...\n";

$client   = new RedcapApiClient($config['api_url'], $config['token']);
$metadata = $client->fetchMetadata();

if (empty($metadata))
{
    echo "ERROR: Empty metadata response from REDCap.\n";
    exit(1);
}

echo "Metadata fetched — " . count($metadata) . " fields.\n\n";

// Index by field_name for O(1) lookup
$metaIndex = [];
foreach ($metadata as $field)
{
    $metaIndex[$field['field_name']] = $field;
}

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Extract the English portion from a bilingual REDCap label.
 *
 * REDCap labels in this project follow these patterns:
 *   "हिंदू (Hindu)"                          → "Hindu"
 *   "मुस्लिम (Muslim)"                        → "Muslim"
 *   "प्रोफेशनल या ऑनर्स डिग्री Profession or Honours Degree (MBBS...)"
 *                                             → "Profession or Honours Degree (MBBS...)"
 *   "इंटरमीडिएट (12वीं) / डिप्लोमा Intermediate (Class 12) / Diploma"
 *                                             → "Intermediate (Class 12) / Diploma"
 *   "अन्य, कृपया बताएं (Other, please specify): {ses_religion_oth}"
 *                                             → "Other (specify)"
 *
 * Strategy:
 *   1. Strip REDCap piping placeholders like {field_name}
 *   2. Find the first ASCII letter (A-Z a-z) — everything before it is Hindi
 *   3. If that first ASCII letter is inside parentheses, extract just the
 *      parenthesis content (e.g. "हिंदू (Hindu)" → "Hindu")
 *   4. Otherwise keep from the first ASCII letter onwards
 *   5. Trim and collapse whitespace
 *
 * If no ASCII letter is found the original label is returned as fallback.
 */
function extractEnglish(string $label): string
{
    // Step 1: strip REDCap piping placeholders {field_name}
    $label = preg_replace('/\{[^}]+\}/', '', $label);
    $label = trim($label, " \t\n\r\0\x0B:,");

    // Step 2: find position of first ASCII letter
    if (!preg_match('/[A-Za-z]/', $label, $m, PREG_OFFSET_CAPTURE))
    {
        // No English at all — return cleaned original
        return trim(preg_replace('/\s+/', ' ', $label));
    }

    $firstAsciiPos = $m[0][1];

    // Step 3: check if that ASCII letter is inside parentheses immediately
    // preceded by a non-ASCII run — pattern: "हिंदू (Hindu)"
    // Look back from firstAsciiPos for an opening paren with only spaces before it
    $before = substr($label, 0, $firstAsciiPos);
    if (preg_match('/\(\s*$/', $before))
    {
        // Find the matching closing paren
        $closePos = strpos($label, ')', $firstAsciiPos);
        if ($closePos !== false)
        {
            $english = substr($label, $firstAsciiPos, $closePos - $firstAsciiPos);
            return trim(preg_replace('/\s+/', ' ', $english));
        }
    }

    // Step 4: keep everything from the first ASCII letter onwards
    $english = substr($label, $firstAsciiPos);
    return trim(preg_replace('/\s+/', ' ', $english));
}

/**
 * Parse REDCap pipe-delimited choice string into [code => label].
 * REDCap format: "CODE1, Label One | CODE2, Label Two | ..."
 *
 * Labels may contain commas, so we split on the FIRST comma only (limit=2).
 * Labels may also contain newlines or carriage returns (copy-paste artefacts
 * in REDCap data dictionaries) — these are stripped so they don't break the
 * generated PHP string literals.
 *
 * $englishOnly: when true, bilingual Hindi+English labels are reduced to
 * English only via extractEnglish().
 */
function parseChoices(string $raw, bool $englishOnly = false): array
{
    $choices = [];
    foreach (explode('|', $raw) as $pair)
    {
        $parts = explode(',', trim($pair), 2);
        if (count($parts) === 2)
        {
            $code  = trim($parts[0]);
            // Strip control characters (newlines, tabs, carriage returns)
            $label = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', $parts[1]));
            // Collapse whitespace
            $label = preg_replace('/\s+/', ' ', $label);

            if ($englishOnly)
            {
                $label = extractEnglish($label);
            }

            if ($code !== '') $choices[$code] = $label;
        }
    }
    return $choices;
}

/**
 * Produce a safely escaped PHP single-quoted string value (no surrounding quotes).
 * Handles backslashes and single quotes. Using this instead of addslashes()
 * which misses edge cases like strings that end with a backslash.
 */
function phpStr(string $s): string
{
    // var_export produces a properly quoted+escaped PHP string literal.
    // We strip the surrounding single quotes so callers can embed it freely.
    $exported = var_export($s, true);
    // var_export may choose double quotes for strings with single quotes —
    // normalise to single-quoted form for consistency in generated files.
    return $exported;
}

/**
 * Look up a field in metadata, parse its choices, print status.
 * Returns [code => label] array, or false if field is unusable.
 */
function resolveFieldChoices(string $fieldName, array $metaIndex, bool $englishOnly = false): array|false
{
    if (!isset($metaIndex[$fieldName]))
    {
        echo "  NOT FOUND  : {$fieldName} (not in REDCap metadata)\n";
        return false;
    }

    $raw = trim($metaIndex[$fieldName]['select_choices_or_calculations'] ?? '');

    if ($raw === '')
    {
        echo "  NO CHOICES : {$fieldName} (text or calculated field — no options)\n";
        return false;
    }

    $choices = parseChoices($raw, $englishOnly);

    if (empty($choices))
    {
        echo "  PARSE ERR  : {$fieldName} (could not parse: {$raw})\n";
        return false;
    }

    echo "  OK         : {$fieldName} — " . count($choices) . " options\n";
    return $choices;
}

$generated = 0;

// =============================================================================
// 1. label_map.php
//    Structure: 'field_name' => ['CODE' => 'Label', ...]
//    One block per field. Used by EligibilityHtmlExporter to resolve
//    categorical values that appear INSIDE records (e.g. ses_religion).
// =============================================================================

if ($fieldsCsv)
{
    $fields   = array_filter(array_map('trim', explode(',', $fieldsCsv)));
    $labelOut = $opts['label-output'] ?? "{$projectDir}/label_map.php";

    echo "── label_map.php ──────────────────────────────────────────────────────────\n";
    echo "   Fields requested : " . count($fields) . "\n";
    echo "   Output           : {$labelOut}\n";

    $maps = [];
    foreach ($fields as $fieldName)
    {
        $choices = resolveFieldChoices($fieldName, $metaIndex, $englishOnly);
        if ($choices !== false) {
            $maps[$fieldName] = $choices;
        }
    }

    if (empty($maps))
    {
        echo "   No valid fields found — label_map.php NOT generated.\n\n";
    }
    else
    {
        $generatedAt = date('Y-m-d H:i:s');
        $totalOpts   = array_sum(array_map('count', $maps));

        $php  = "<?php\n\n";
        $php .= "/**\n";
        $php .= " * REDCap Option Label Map — {$project} Project\n";
        $php .= " *\n";
        $php .= " * AUTO-GENERATED on {$generatedAt}\n";
        $php .= " * Command: php tools/generate_label_map.php --project={$project} --fields={$fieldsCsv}\n";
        $php .= " *\n";
        $php .= " * Maps raw REDCap option codes to display labels for categorical\n";
        $php .= " * variables rendered inside report tables (e.g. Page 3 Demographics).\n";
        $php .= " *\n";
        $php .= " * Re-run after any REDCap data dictionary change.\n";
        $php .= " * Safe to edit manually — the generator will overwrite on next run.\n";
        $php .= " *\n";
        $php .= " * Usage in reports.php:\n";
        $php .= " *   'label_map_path' => __DIR__ . '/label_map.php'\n";
        $php .= " *\n";
        $php .= " * Fields: " . count($maps) . "  |  Total options: {$totalOpts}\n";
        $php .= " */\n\n";
        $php .= "return [\n\n";

        foreach ($maps as $fieldName => $choices)
        {
            $meta       = $metaIndex[$fieldName] ?? [];
            $fieldLabel = strip_tags($meta['field_label'] ?? $fieldName);
            $count      = count($choices);
            $pad        = str_repeat('-', max(0, 58 - strlen($fieldLabel) - strlen($fieldName)));

            $php .= "    // -- {$fieldLabel} ({$fieldName}) -- {$count} options {$pad}\n";
            $php .= "    '{$fieldName}' => [\n";
            foreach ($choices as $code => $label)
            {
                $php .= "        " . phpStr($code) . " => " . phpStr($label) . ",\n";
            }
            $php .= "    ],\n\n";
        }

        $php .= "];\n";

        if ($dryRun)
        {
            echo "\n--- DRY RUN: label_map.php ---\n\n{$php}\n";
        }
        else
        {
            file_put_contents($labelOut, $php);
            echo "   Written — " . count($maps) . " fields, {$totalOpts} total options.\n\n";
        }

        $generated++;
    }
}

// =============================================================================
// 2. site_labels.php
//    Structure: 'CODE' => 'Display Name'  (flat, no field-name nesting)
//    Used as column headers and row labels across all reports.
//    baby_hosp_code and enr_hosp_code share the same hospital option set
//    so either field name produces the correct site list.
// =============================================================================

if ($siteField)
{
    $siteOut  = $opts['site-output'] ?? "{$projectDir}/site_labels.php";
    $siteName = trim($siteField);

    echo "── site_labels.php ────────────────────────────────────────────────────────\n";
    echo "   Source field : {$siteName}\n";
    echo "   Output       : {$siteOut}\n";

    $choices = resolveFieldChoices($siteName, $metaIndex, $englishOnly);

    if ($choices === false)
    {
        echo "   site_labels.php NOT generated.\n\n";
    }
    else
    {
        $generatedAt = date('Y-m-d H:i:s');

        $php  = "<?php\n\n";
        $php .= "/**\n";
        $php .= " * Site Label Map — {$project} Project\n";
        $php .= " *\n";
        $php .= " * AUTO-GENERATED on {$generatedAt}\n";
        $php .= " * Command: php tools/generate_label_map.php --project={$project} --sites={$siteName}\n";
        $php .= " *\n";
        $php .= " * Maps hospital site codes to display names used as column headers\n";
        $php .= " * and row labels across all reports in this project.\n";
        $php .= " *\n";
        $php .= " * Covers all fields that hold the same hospital code set:\n";
        $php .= " *   baby_hosp_code (screening form), enr_hosp_code (enrolment form)\n";
        $php .= " * The codes are identical across forms so one file covers all.\n";
        $php .= " *\n";
        $php .= " * Re-run after any REDCap data dictionary change.\n";
        $php .= " * Safe to edit manually — the generator will overwrite on next run.\n";
        $php .= " *\n";
        $php .= " * Usage in reports.php:\n";
        $php .= " *   'site_labels_path' => __DIR__ . '/site_labels.php'\n";
        $php .= " *\n";
        $php .= " * Sites: " . count($choices) . "\n";
        $php .= " */\n\n";
        $php .= "return [\n";

        foreach ($choices as $code => $label)
        {
            $php .= "    " . phpStr($code) . " => " . phpStr($label) . ",\n";
        }

        $php .= "];\n";

        if ($dryRun)
        {
            echo "\n--- DRY RUN: site_labels.php ---\n\n{$php}\n";
        }
        else
        {
            file_put_contents($siteOut, $php);
            echo "   Written — " . count($choices) . " sites.\n\n";
        }

        $generated++;
    }
}

// ── Summary ────────────────────────────────────────────────────────────────────

if ($generated === 0)
{
    echo "Nothing generated. Check field names above, or use --dry-run to preview.\n";
    exit(1);
}

echo $dryRun
    ? "Dry run complete. Remove --dry-run to write the files.\n"
    : "Done.\n";

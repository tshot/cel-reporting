#!/usr/bin/env php
<?php
/**
 * fix_env_load.php — make sure every CLI tool that reads config.php also
 * loads .env, and that the autoloader is taken from the REPO ROOT.
 *
 * config.php reads $_ENV['EMOLLIENT_REDCAP_URL']. The web entry point loads
 * .env during bootstrap; a CLI script has to do it itself, or every REDCap
 * call fails with "EMOLLIENT_REDCAP_URL is not set".
 *
 * Idempotent: a file that already loads .env is left alone.
 *
 * Usage (from the repo root):
 *   php tools/fix_env_load.php --dry-run
 *   php tools/fix_env_load.php
 *   php tools/fix_env_load.php tools/generate_label_map.php
 */
$args  = array_slice($argv, 1);
$dry   = in_array('--dry-run', $args, true);
$files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

if ($files === [])
{
    $self  = basename(__FILE__);
    $files = array_values(array_filter(glob('tools/*.php'), function ($f) use ($self) {
        if (basename($f) === $self) return false;
        $s = file_get_contents($f);
        if (!str_contains($s, 'vendor/autoload')) return false;
        if (!preg_match('/config\.php|ReportFacade|RedcapApiClient/', $s)) return false;
        return !str_contains($s, 'Dotenv');
    }));
}

if ($files === []) { echo "Nothing to fix — every tool that needs .env already loads it.\n"; exit(0); }

$changed = $skipped = 0;

foreach ($files as $file)
{
    $src = file_get_contents($file);
    $out = $src;
    $notes = [];

    // 1. the autoloader must come from the repo root, not reporting-engine/
    if (preg_match('/\$(\w+)\s*=\s*dirname\(__DIR__\);/', $out, $m))
    {
        $rootVar = '$' . $m[1];
    }
    else
    {
        $rootVar = '$repoRoot';
    }

    if (preg_match('/require\s+\$engineRoot\s*\.\s*\'\/vendor\/autoload\.php\';/', $out))
    {
        $out = preg_replace('/require\s+\$engineRoot\s*\.\s*\'\/vendor\/autoload\.php\';/',
                            "require {$rootVar} . '/vendor/autoload.php';", $out, 1);
        $out = preg_replace('/if\s*\(!file_exists\(\$engineRoot\s*\.\s*\'\/vendor\/autoload\.php\'\)\)/',
                            "if (!file_exists({$rootVar} . '/vendor/autoload.php'))", $out, 1);
        $out = str_replace('{$engineRoot}/vendor/autoload.php',
                           '{' . $rootVar . '}/vendor/autoload.php', $out);
        $notes[] = 'autoloader repointed at the repo root';
    }

    // 2. load .env immediately after the autoloader
    if (!str_contains($out, 'Dotenv') &&
        preg_match('/(require\s+\$(\w+)\s*\.\s*\'\/vendor\/autoload\.php\';\s*\n)/', $out, $m))
    {
        $var = '$' . $m[2];
        $add = $m[1]
             . "\n// config.php reads \$_ENV. The web entry point loads .env during bootstrap;\n"
             . "// a CLI script must do it explicitly.\n"
             . "Dotenv\\Dotenv::createImmutable({$var})->safeLoad();\n";
        $out = str_replace($m[1], $add, $out);
        $notes[] = '.env load added';
    }

    if ($out === $src) { printf("  %-34s no change (pattern not recognised)\n", $file); $skipped++; continue; }
    if ($dry)          { printf("  %-34s WOULD CHANGE — %s\n", $file, implode(', ', $notes)); $changed++; continue; }

    copy($file, $file . '.bak');
    file_put_contents($file, $out);

    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lint, $rc);
    if ($rc !== 0)
    {
        rename($file . '.bak', $file);
        printf("  %-34s LINT FAILED — reverted\n", $file);
        $skipped++;
        continue;
    }
    printf("  %-34s %s\n", $file, implode(', ', $notes));
    $changed++;
}

printf("\n%d changed, %d skipped.%s\n", $changed, $skipped,
    $dry ? ' (dry run)' : ' Backups are *.bak');

#!/usr/bin/env php
<?php
/**
 * fix_tools_bootstrap.php — repoint the legacy diagnostics at the repo root.
 *
 * After tools/ moved into the repo, these scripts still resolved the engine as
 * a sibling and looked for vendor/autoload.php under reporting-engine/. The
 * autoloader is actually at the REPO ROOT, and CLI scripts that require
 * config.php must load .env themselves.
 *
 * Rewrites, per file:
 *   $engineRoot = realpath(__DIR__ . '/../reporting-engine');   -> $repoRoot block
 *   require $engineRoot . '/vendor/autoload.php';               -> repo-root autoload
 *   realpath($engineRoot . '/../projects')                      -> $repoRoot . '/projects'
 *   __DIR__ . '/../projects'                                    -> $repoRoot . '/projects'
 *   + Dotenv load when the file requires config.php
 *
 * $engineRoot is still defined afterwards, so any other use of it keeps working.
 *
 * Usage (from the repo root):
 *   php tools/fix_tools_bootstrap.php --dry-run          show what would change
 *   php tools/fix_tools_bootstrap.php                    apply, with .bak backups
 *   php tools/fix_tools_bootstrap.php tools/diag_weekly.php
 */
$args   = array_slice($argv, 1);
$dry    = in_array('--dry-run', $args, true);
$files  = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));

if ($files === [])
{
    $self  = basename(__FILE__);
    $files = array_filter(glob('tools/*.php'), function ($f) use ($self) {
        if (basename($f) === $self) { return false; }       // never patch itself
        $s = file_get_contents($f);
        if (str_contains($s, '$repoRoot = dirname(__DIR__)')) { return false; }  // already fixed
        return str_contains($s, "__DIR__ . '/../reporting-engine'")
            || str_contains($s, "__DIR__ . '/../vendor");
    });
    $files = array_values($files);
}

if ($files === []) { echo "Nothing to fix.\n"; exit(0); }

$BOOT = <<<'PHPBLOCK'
// tools/ sits at the repo root alongside reporting-engine/, shared-lib/,
// projects/ and vendor/. Composer's autoloader is at the REPO ROOT — the
// same one reporting-engine/public/index.php requires.
$repoRoot   = dirname(__DIR__);
$engineRoot = $repoRoot . '/reporting-engine';

if (!is_file($repoRoot . '/vendor/autoload.php'))
{
    fwrite(STDERR, "ERROR: Cannot find {$repoRoot}/vendor/autoload.php\n");
    fwrite(STDERR, "       Run 'composer install' in {$repoRoot}\n");
    exit(1);
}

require $repoRoot . '/vendor/autoload.php';
PHPBLOCK;

$DOTENV = "\n// config.php reads \$_ENV. The web entry point loads .env during\n"
        . "// bootstrap; a CLI script must do it explicitly.\n"
        . "Dotenv\\Dotenv::createImmutable(\$repoRoot)->safeLoad();\n";

$changed = $skipped = 0;

foreach ($files as $file)
{
    $src = file_get_contents($file);

    if (str_contains($src, '$repoRoot = dirname(__DIR__)'))
    {
        printf("  %-38s already fixed\n", $file);
        $skipped++;
        continue;
    }

    $out = $src;

    // 1. the simple two-line form
    $out = preg_replace(
        '/\$engineRoot\s*=\s*realpath\(__DIR__ \. \'\/\.\.\/reporting-engine\'\);\s*\n'
        . '(?:\s*\n)?require \$engineRoot \. \'\/vendor\/autoload\.php\';/',
        $BOOT, $out, 1, $n1);

    // 2. the guarded form (diag_wide / dump style)
    if (!$n1)
    {
        $out = preg_replace(
            '/\$engineRoot\s*=\s*realpath\(__DIR__ \. \'\/\.\.\/reporting-engine\'\);\s*\n\s*\n'
            . 'if \(![^\n]*autoload[^}]*\}\s*\n\s*\n'
            . 'require \$engineRoot \. \'\/vendor\/autoload\.php\';/s',
            $BOOT, $out, 1, $n2);
    }

    // 3. the fallback form (nicu_24hr_extract style)
    if (!$n1 && empty($n2))
    {
        $out = preg_replace(
            '/\$engineRoot\s*=\s*realpath\(__DIR__ \. \'\/\.\.\/reporting-engine\'\);\s*\n'
            . '\$autoload\s*=.*?require \$autoload;/s',
            $BOOT, $out, 1, $n3);
    }

    // projects path, in all its shapes
    $out = str_replace(
        ["realpath(\$engineRoot . '/../projects')", "realpath(\$engineRoot.'/../projects')",
         "__DIR__ . '/../projects'", "\$engineRoot . '/../projects'"],
        "\$repoRoot . '/projects'", $out);

    // .env, only when config.php is read and Dotenv is not already loaded
    if (str_contains($out, 'config.php') && !str_contains($out, 'Dotenv'))
    {
        $out = preg_replace('/(require \$repoRoot \. \'\/vendor\/autoload\.php\';\n)/',
                            '$1' . $DOTENV, $out, 1);
    }

    if ($out === $src)
    {
        printf("  %-38s no change (pattern not recognised)\n", $file);
        $skipped++;
        continue;
    }

    if ($dry)
    {
        printf("  %-38s WOULD CHANGE\n", $file);
        $changed++;
        continue;
    }

    copy($file, $file . '.bak');
    file_put_contents($file, $out);

    $lint = [];
    exec('php -l ' . escapeshellarg($file) . ' 2>&1', $lint, $rc);

    if ($rc !== 0)
    {
        rename($file . '.bak', $file);
        printf("  %-38s LINT FAILED — reverted\n     %s\n", $file, implode(' ', $lint));
        $skipped++;
        continue;
    }

    printf("  %-38s patched\n", $file);
    $changed++;
}

printf("\n%d changed, %d skipped.%s\n", $changed, $skipped,
    $dry ? ' (dry run — nothing written)' : ' Backups are *.bak');

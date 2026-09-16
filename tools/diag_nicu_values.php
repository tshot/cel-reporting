<?php
ini_set('memory_limit', '256M');
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

// config.php reads $_ENV. The web entry point loads .env during
// bootstrap; a CLI script must do it explicitly.
Dotenv\Dotenv::createImmutable($repoRoot)->safeLoad();
use CEL\Shared\Infrastructure\Redcap\RedcapApiClient;

$opts    = getopt('', ['project:']);
$project = $opts['project'] ?? 'Emollient';
$cfg     = require $repoRoot . '/projects' . "/{$project}/config.php";
$client  = new RedcapApiClient($cfg['api_url'], $cfg['token']);

$stream  = $client->stream(['record_id', 'baby_admit_nicu'], [], ['day0_arm_1'], 200);

$counts = [];
$total  = 0;
foreach ($stream as $row) {
    if (($row['redcap_repeat_instrument'] ?? '') !== 'baby_prescreening_and_screening_form') continue;
    $val = $row['baby_admit_nicu'] ?? '';
    $counts[$val] = ($counts[$val] ?? 0) + 1;
    $total++;
}

echo "=== baby_admit_nicu value distribution ===\n";
echo "Total prescreening rows: {$total}\n\n";
arsort($counts);
foreach ($counts as $val => $n) {
    $display = $val === '' ? '(blank)' : "'{$val}'";
    echo "  {$display}: {$n}\n";
}

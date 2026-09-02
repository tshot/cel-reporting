<?php
$root = dirname(__DIR__, 2);

require $root . '/vendor/autoload.php';

// Load .env before anything reads $_ENV. Credentials live there, never in
// source — see projects/*/config.php, which now fail loudly if a value is
// missing rather than falling back to a committed default.
Dotenv\Dotenv::createImmutable($root)->safeLoad();

$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
ini_set('display_errors', $debug ? '1' : '0');
ini_set('display_startup_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

use CEL\Reporting\Presentation\Api\ReportController;

$controller = new ReportController();
$controller->generate();

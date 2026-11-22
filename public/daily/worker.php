<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/_store.php';
require_once __DIR__ . '/_ai.php';
require_once __DIR__ . '/_workout-plan.php';
$root = dirname(__DIR__, 2);
$path = getenv('DAILY_CONFIG') ?: (is_file($root . '/.daily-data/config.php') ? $root . '/.daily-data/config.php' : $root . '/daily-config.php');
if (!is_file($path)) { fwrite(STDERR, "Daily configuration is missing.\n"); exit(1); }
$config = require $path;
if (dailyProcessor($config) !== 'openrouter') { echo "External processing is selected.\n"; exit; }
$db = new PDO($config['dsn'], $config['user'] ?? null, $config['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') $db->exec('PRAGMA busy_timeout = 4000');
echo json_encode(dailyDrain($db, $config), JSON_THROW_ON_ERROR), PHP_EOL;

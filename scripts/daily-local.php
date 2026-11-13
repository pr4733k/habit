<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
$directory = $root . '/.daily-data';
if (!is_dir($directory)) {
    mkdir($directory, 0700, true);
}
$configPath = $directory . '/config.php';
$dsn = 'sqlite:' . $directory . '/tracker.sqlite';
$logPassword = getenv('DAILY_LOG_PASSWORD');
$timeZone = getenv('DAILY_TIMEZONE');
if ($timeZone !== false && !in_array($timeZone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
    fwrite(STDERR, "Set DAILY_TIMEZONE to an IANA timezone, such as Europe/London.\n");
    exit(1);
}
if ($logPassword !== false && (trim($logPassword) === '' || strlen($logPassword) > 72)) {
    fwrite(STDERR, "Set a log password between 1 and 72 bytes.\n");
    exit(1);
}
if (!is_file($configPath) && $logPassword === false) {
    fwrite(STDERR, "Set DAILY_LOG_PASSWORD before setting up Habit.\n");
    exit(1);
}
if (!is_file($configPath)) {
    $token = bin2hex(random_bytes(32));
    $config = [
        'dsn' => $dsn,
        'password_hash' => password_hash($logPassword, PASSWORD_DEFAULT),
        'sync_token_hash' => hash('sha256', $token),
        'session_key' => bin2hex(random_bytes(32)),
        'timezone' => $timeZone === false ? 'UTC' : $timeZone,
        'local' => true,
    ];
    file_put_contents($directory . '/processor.json', json_encode([
        'url' => 'http://127.0.0.1:8877/daily/api.php', 'token' => $token,
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
} else {
    $config = require $configPath;
    if ($logPassword !== false && !password_verify($logPassword, $config['password_hash'])) {
        $config['password_hash'] = password_hash($logPassword, PASSWORD_DEFAULT);
        $config['session_key'] = bin2hex(random_bytes(32));
    }
    if ($timeZone !== false) {
        $config['timezone'] = $timeZone;
    }
}
file_put_contents($configPath, '<?php return ' . var_export($config, true) . ';', LOCK_EX);
chmod($configPath, 0600);
chmod($directory . '/processor.json', 0600);

$db = new PDO($dsn, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec(file_get_contents($root . '/daily/schema.sql'));
echo "Local tracker ready.\n";

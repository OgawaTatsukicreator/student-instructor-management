<?php
declare(strict_types=1);

$env = static fn(string $key, mixed $default): mixed => getenv($key) === false ? $default : getenv($key);
$settings = [
    'db' => ['host' => $env('BETATEAM_DB_HOST', '127.0.0.1'), 'port' => (int)$env('BETATEAM_DB_PORT', 3307),
        'name' => $env('BETATEAM_DB_NAME', 'βteam'), 'user' => $env('BETATEAM_DB_USER', 'root'),
        'password' => $env('BETATEAM_DB_PASSWORD', '')],
    'app_key' => $env('BETATEAM_APP_KEY', ''),
    'mail_verification' => $env('BETATEAM_MAIL_VERIFY', '0') === '1',
    'smtp' => ['host' => '', 'port' => 587, 'security' => 'tls', 'username' => '', 'password' => '',
        'from' => '', 'from_name' => 'βteam'],
];
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (!is_array($local)) { throw new RuntimeException('Invalid local configuration'); }
    $settings = array_replace_recursive($settings, $local);
}
return $settings;

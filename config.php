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
    if (!is_array($local)) { throw new ConfigurationError('Invalid local configuration'); }
    $settings = array_replace_recursive($settings, $local);
}
foreach (['db','smtp'] as $group) {
    if (!is_array($settings[$group] ?? null)) { throw new ConfigurationError('Invalid configuration group'); }
}
foreach (['host','name','user','password'] as $key) {
    if (!is_string($settings['db'][$key] ?? null)) { throw new ConfigurationError('Invalid database configuration'); }
}
if ($settings['db']['host'] === '' || $settings['db']['name'] === '' || $settings['db']['user'] === ''
    || preg_match('/[;\x00-\x1f]/', $settings['db']['host'] . $settings['db']['name'])
    || !is_int($settings['db']['port'] ?? null) || $settings['db']['port'] < 1 || $settings['db']['port'] > 65535
    || !is_bool($settings['mail_verification']) || !is_string($settings['app_key'])) {
    throw new ConfigurationError('Invalid application configuration');
}
foreach (['host','security','username','password','from','from_name'] as $key) {
    if (!is_string($settings['smtp'][$key] ?? null)) { throw new ConfigurationError('Invalid SMTP configuration'); }
}
if (!is_int($settings['smtp']['port'] ?? null) || $settings['smtp']['port'] < 1 || $settings['smtp']['port'] > 65535) {
    throw new ConfigurationError('Invalid SMTP port');
}
return $settings;

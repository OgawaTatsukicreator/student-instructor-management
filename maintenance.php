<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$options = array_slice($argv,1);
if ($options !== [] && $options !== ['--prune']) {
    fwrite(STDERR,"Usage: php maintenance.php [--prune]\n"); exit(1);
}
require __DIR__ . '/bootstrap.php';

// 最大の制限期間(1時間)より長く保持し、期限内の制限を解除しない。
$rate_before = time() - 86400;
$expired_before = time();
$prune = $options === ['--prune'];
$result = ['mode'=>$prune ? 'prune' : 'dry-run','batch_limit'=>1000];
if ($prune) {
    $result += transaction(static function () use ($rate_before,$expired_before): array {
        return [
            'rate_limits_deleted'=>query('DELETE FROM rate_limits WHERE window_started < ? LIMIT 1000',[$rate_before])->rowCount(),
            'password_changes_deleted'=>query('DELETE FROM password_changes WHERE expires_at <= ? LIMIT 1000',[$expired_before])->rowCount(),
        ];
    });
} else {
    $result['rate_limits_eligible'] = (int)query('SELECT COUNT(*) FROM rate_limits WHERE window_started < ?',[$rate_before])->fetchColumn();
    $result['password_changes_eligible'] = (int)query('SELECT COUNT(*) FROM password_changes WHERE expires_at <= ?',[$expired_before])->fetchColumn();
}
fwrite(STDOUT,json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES) . "\n");

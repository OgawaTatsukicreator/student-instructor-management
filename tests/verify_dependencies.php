<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Official release checksums are reviewed in Git, never regenerated from the
// installed files. This check deliberately has no network or DB dependency.
$root = dirname(__DIR__);
$expected_paths = [
    'third_party/PHPMailer/src/Exception.php',
    'third_party/PHPMailer/src/PHPMailer.php',
    'third_party/PHPMailer/src/SMTP.php',
    'third_party/PHPMailer/composer.json',
    'third_party/PHPMailer/LICENSE',
];
try {
    $raw = @file_get_contents($root . '/docs/依存関係チェックサム.json');
    if (!is_string($raw) || strlen($raw) > 65536) {
        throw new RuntimeException('チェックサム一覧を読み込めません。');
    }
    $manifest = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($manifest) || ($manifest['schema_version'] ?? null) !== 1
        || ($manifest['dependency'] ?? null) !== 'phpmailer/phpmailer'
        || ($manifest['version'] ?? null) !== '7.1.1'
        || ($manifest['official_tag'] ?? null) !== 'v7.1.1'
        || ($manifest['source_url'] ?? null) !== 'https://github.com/PHPMailer/PHPMailer/tree/v7.1.1'
        || !is_string($manifest['verified_on'] ?? null)
        || !preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $manifest['verified_on'])) {
        throw new RuntimeException('チェックサム一覧のライブラリ・版・供給元・確認日の形式が正しくありません。');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $manifest['verified_on']);
    if (!$date || $date->format('Y-m-d') !== $manifest['verified_on']) {
        throw new RuntimeException('チェックサム一覧の確認日が正しくありません。');
    }
    $files = $manifest['files'] ?? null;
    if (!is_array($files) || !array_is_list($files) || count($files) !== count($expected_paths)) {
        throw new RuntimeException('チェックサム一覧には指定された5ファイルが必要です。');
    }
    $checksums = [];
    foreach ($files as $file) {
        if (!is_array($file) || count($file) !== 2 || !is_string($file['path'] ?? null)
            || !in_array($file['path'], $expected_paths, true)
            || array_key_exists($file['path'], $checksums)
            || !is_string($file['sha256'] ?? null)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $file['sha256'])) {
            throw new RuntimeException('チェックサム一覧のファイル名またはSHA-256形式が正しくありません。');
        }
        $checksums[$file['path']] = $file['sha256'];
    }
    $failed = false;
    foreach ($expected_paths as $relative) {
        $path = $root . '/' . $relative;
        $hash = is_file($path) ? @hash_file('sha256', $path) : false;
        $matches = is_string($hash) && hash_equals($checksums[$relative], $hash);
        echo ($matches ? 'PASS ' : 'FAIL ') . $relative . "\n";
        $failed = $failed || !$matches;
    }
    if ($failed) {
        throw new RuntimeException('同梱ファイルが公式チェックサムと一致しないか、ファイルが不足しています。');
    }
    // Read the declared version only after all executable files match the
    // reviewed official bytes; unverified dependency code is never included.
    require_once $root . '/third_party/PHPMailer/src/PHPMailer.php';
    if (\PHPMailer\PHPMailer\PHPMailer::VERSION !== '7.1.1') {
        throw new RuntimeException('PHPMailerのVERSIONが7.1.1と一致しません。');
    }
    echo "dependencies: 5/5 passed; PHPMailer 7.1.1; offline verification\n";
    exit(0);
} catch (JsonException $error) {
    fwrite(STDERR, "依存関係の検証に失敗しました。チェックサム一覧のJSON形式を確認してください。\n");
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, '依存関係の検証に失敗しました。' . ($error instanceof RuntimeException ? $error->getMessage() : '検証処理を完了できません。') . "\n");
    exit(1);
}

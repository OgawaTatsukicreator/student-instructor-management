<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/sql_fixtures.php';
require __DIR__ . '/sql_cases.php';

// アプリのconfig.phpは読み込まない。接続情報が不足したら実DBへ接続せず終了する。
$name = getenv('BETATEAM_SQL_TEST_DATABASE');
$host = getenv('BETATEAM_SQL_TEST_HOST');
$port = getenv('BETATEAM_SQL_TEST_PORT');
$user = getenv('BETATEAM_SQL_TEST_USER');
$password = getenv('BETATEAM_SQL_TEST_PASSWORD');
if ($password === false) { $password = ''; }
if (!is_string($name) || !preg_match('/\Abeta_sql_test_[a-z0-9_]+\z/D', $name)
    || !is_string($host) || !preg_match('/\A[a-zA-Z0-9.:-]+\z/D', $host)
    || !is_string($port) || !ctype_digit($port) || (int)$port < 1 || (int)$port > 65535
    || !is_string($user) || $user === '') {
    fwrite(STDERR, "専用のBETATEAM_SQL_TEST_*接続設定が必要です。DB名はbeta_sql_test_で始めてください。docs/SQLテストケース.mdを参照してください。\n"); exit(1);
}
$pdo = null;
try {
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET time_zone='+00:00'");
    $tables = ['password_changes', 'rate_limits', 'roles', 'student_teacher', 'students', 'system_locks', 'teachers'];
    $found = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    sort($found);
    if ($found !== $tables) { throw new RuntimeException('7テーブルの初期定義が必要です。database.sqlを専用DBへ読み込んでください。'); }
    $baseline = [];
    foreach ($tables as $table) {
        $baseline[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        $expected = $table === 'roles' ? 3 : ($table === 'system_locks' ? 1 : 0);
        if ($baseline[$table] !== $expected) { throw new RuntimeException('初期定義だけの専用DBが必要です。テストデータを登録済みのDBは使用できません。'); }
    }
    if ($pdo->query("SELECT GROUP_CONCAT(name ORDER BY CAST(name AS CHAR)) FROM roles")->fetchColumn() !== 'admin,general,operator'
        || $pdo->query('SELECT name FROM system_locks')->fetchColumn() !== 'management') {
        throw new RuntimeException('初期権限または管理用ロックの定義が一致しません。');
    }
    $engines = $pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);
    if (array_unique($engines) !== ['InnoDB']) { throw new RuntimeException('ROLLBACK可能なInnoDBが必要です。'); }
    $cases = sql_test_cases();
    $counts = array_count_values(array_column($cases, 'table'));
    if (count($counts) !== 7 || array_unique(array_values($counts)) !== [10]) { throw new RuntimeException('各テーブル10ケースが必要です。'); }
    $pdo->beginTransaction();
    foreach (sql_fixture_statements() as $statements) {
        foreach ($statements as $sql) { $pdo->exec($sql); }
    }
    $results = [];
    foreach ($cases as $case) {
        $pdo->exec('SAVEPOINT test_case');
        $result = array_intersect_key($case, array_flip(['id', 'table', 'title', 'expected']));
        try {
            foreach ($case['steps'] as $step) {
                try { $statement = $pdo->query($step['sql']); }
                catch (PDOException $error) {
                    $code = (int)($error->errorInfo[1] ?? 0);
                    if (isset($step['error']) && in_array($code, $step['error'], true)) { continue; }
                    throw new RuntimeException('予期しないSQLエラー: ' . $code);
                }
                if (isset($step['error'])) { throw new RuntimeException('拒否されるはずのSQLが成功しました。'); }
                if (array_key_exists('value', $step) && (string)$statement->fetchColumn() !== $step['value']) {
                    throw new RuntimeException('SQL結果が期待値に一致しません。');
                }
            }
            $result['passed'] = true;
        } catch (Throwable $error) {
            $result['passed'] = false;
            $result['failure'] = $error->getMessage();
        } finally {
            $pdo->exec('ROLLBACK TO SAVEPOINT test_case');
            $pdo->exec('RELEASE SAVEPOINT test_case');
        }
        $results[] = $result;
        echo ($result['passed'] ? 'PASS ' : 'FAIL ') . $case['id'] . ' ' . $case['title'] . "\n";
    }
    $pdo->rollBack();
    foreach ($baseline as $table => $count) {
        if ((int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn() !== $count) {
            throw new RuntimeException('試験後の件数が初期状態に戻っていません。');
        }
    }
    $failed = count(array_filter($results, static fn(array $result): bool => !$result['passed']));
    $report = ['executed_at' => gmdate('c'), 'server_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION), 'total' => count($results), 'passed' => count($results) - $failed, 'failed' => $failed, 'rolled_back' => true, 'cases' => $results];
    $dir = __DIR__ . '/../output';
    if (!is_dir($dir) && !mkdir($dir, 0777, true)) { throw new RuntimeException('レポートの保存先を作成できません。'); }
    if (file_put_contents($dir . '/sql-tests.json', json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
        throw new RuntimeException('レポートを書き込めません。');
    }
    echo "sql: " . (count($results) - $failed) . '/' . count($results) . " passed; ROLLBACK完了; output/sql-tests.json\n";
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) { $pdo->rollBack(); }
    // PDOの接続エラーにはホスト名等を含むため、ログへそのまま出さない。
    fwrite(STDERR, ($error instanceof PDOException ? 'SQL試験の接続または準備に失敗しました。専用DB設定・初期定義を確認してください。' : $error->getMessage()) . "\n");
    exit(1);
}

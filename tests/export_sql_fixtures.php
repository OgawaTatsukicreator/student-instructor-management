<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/sql_fixtures.php';
if ($argc > 2 || ($argc === 2 && $argv[1] !== '--stdout')) {
    fwrite(STDERR, "使用方法: php tests/export_sql_fixtures.php [--stdout]\n"); exit(1);
}
$sql = "-- 自動生成した架空データ。実データを含むDBには使用しないでください。\n"
    . "-- beta_sql_test_fixturesを自分で作成し、database.sqlを読み込んだ後に実行。\n"
    . "-- 可変5テーブルは各10件。roles=3件、system_locks=1件は初期定義を利用。\n"
    . "-- 講師の試験用パスワード: SqlTest123!（初回変更必須）。認証保留は全件期限切れ。\n"
    . "USE beta_sql_test_fixtures;\nSET NAMES utf8mb4;\n"
    . "SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';\n"
    . "START TRANSACTION;\n";
foreach (sql_fixture_statements() as $table => $statements) {
    $sql .= "\n-- {$table}: 10件\n" . implode(";\n", $statements) . ";\n";
}
$sql .= "\nCOMMIT;\n";
if ($argc === 2) { echo $sql; exit; }
$path = __DIR__ . '/test_data.sql';
if (file_put_contents($path, $sql, LOCK_EX) === false) { fwrite(STDERR, "SQLを書き込めませんでした。\n"); exit(1); }
echo "tests/test_data.sql を生成しました（Git管理外）。\n";

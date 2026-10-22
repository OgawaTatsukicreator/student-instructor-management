<?php
declare(strict_types=1);
// PHP開発サーバー専用。Apacheからの実行と通常の開発サーバーでは停止する。
if (PHP_SAPI !== 'cli-server' || getenv('BETATEAM_SECURITY_TEST_MODE') !== '1') { http_response_code(403); exit; }
try {
    require_once __DIR__ . '/../errors.php';
    $resolved = require __DIR__ . '/../config.php';
    $db_test = $resolved['db'];
    if (!preg_match('/\Abeta_security_test_[a-z0-9_]+\z/D', $db_test['name'])
        || !in_array($db_test['host'], ['127.0.0.1','localhost','::1'], true)) {
        http_response_code(403); exit;
    }
    header('X-BetaTeam-Test-Database: ' . $db_test['name']);
    header('X-BetaTeam-Test-Port: ' . $db_test['port']);
    header('X-BetaTeam-Test-Host: ' . $db_test['host']);
} catch (Throwable) { http_response_code(503); exit('試験用設定を確認してください。'); }
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (preg_match('~\A/assets/[a-z0-9_-]+\.(?:css|js|svg)\z~D', $path)) { return false; }
$pages = ['index.php','login.php','logout.php','password_change.php','password_verify.php',
    'students.php','student_detail.php','student_create.php','student_edit.php','student_notes.php','student_delete.php',
    'teachers.php','teacher_detail.php','teacher_create.php','teacher_edit.php','teacher_delete.php'];
$page = ltrim($path, '/');
if (!in_array($page, $pages, true)) { http_response_code(403); exit; }
$_SERVER['SCRIPT_NAME'] = '/' . $page;
require __DIR__ . '/../' . $page;
return true;

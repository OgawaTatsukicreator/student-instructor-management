<?php
declare(strict_types=1);

ini_set('display_errors', '0');
date_default_timezone_set('Asia/Tokyo');
$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/layout.php';
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    session_name('betateam_session');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $cookie_path = cookie_base_path($_SERVER['SCRIPT_NAME']);
    session_set_cookie_params(['lifetime'=>0,'path'=>$cookie_path,
        'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly'=>true, 'samesite'=>'Lax']);
    session_start();
    set_exception_handler(function (Throwable $error): void {
        if (isset($GLOBALS['user'])) { unset($GLOBALS['user']); }
        error_log('βteam failure: ' . get_class($error) . ' [' . $error->getCode() . ']');
        page_error(503, '現在データを取得・保存できません。時間をおいて再度お試しください。');
    });
}

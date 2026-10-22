<?php
declare(strict_types=1);

class FormError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 409,
        public readonly ?string $field = null, public readonly ?int $retry_after = null)
    { parent::__construct($message); }
}
class ConfigurationError extends RuntimeException {}
class ServiceUnavailable extends RuntimeException {}

function diagnostic_id(): string
{
    static $id;
    if ($id === null) {
        try { $id = bin2hex(random_bytes(8)); }
        catch (Throwable) { $id = substr(hash('sha256', uniqid('', true)), 0, 16); }
    }
    return $id;
}
function diagnostic_log(string $event, ?Throwable $error = null, ?int $status = null): void
{
    // 例外文・入力・SQL・メール・Cookieは記録しない。
    $record = ['time'=>gmdate('c'), 'reference'=>diagnostic_id(),
        'event'=>preg_replace('/[^a-z0-9_.-]/i', '_', $event), 'status'=>$status,
        'route'=>preg_replace('/[^a-z0-9_.-]/i', '_', basename($_SERVER['SCRIPT_NAME'] ?? 'cli'))];
    if ($error) {
        $record += ['exception'=>get_class($error), 'code'=>(string)$error->getCode(),
            'source'=>basename($error->getFile()), 'line'=>$error->getLine()];
        if ($error instanceof PDOException) { $record['driver_code'] = (int)($error->errorInfo[1] ?? 0); }
    }
    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    $dir = __DIR__ . '/runtime'; $path = $dir . '/application.log';
    if (!is_dir($dir)) { @mkdir($dir, 0750, true); }
    clearstatcache(true, $path);
    if (is_file($path) && @filesize($path) > 5 * 1024 * 1024) {
        if (is_file($path . '.1')) { @unlink($path . '.1'); }
        @rename($path, $path . '.1');
    }
    if (@file_put_contents($path, $json, FILE_APPEND | LOCK_EX) === false) { @error_log(trim($json), 4); }
}
function failure_status(Throwable $error): int
{
    if ($error instanceof FormError) { return $error->status; }
    if ($error instanceof ServiceUnavailable) { return 503; }
    if ($error instanceof PDOException && in_array((int)($error->errorInfo[1] ?? 0), [1040,1042,1205,1213,2002,2003,2006,2013], true)) { return 503; }
    return 500;
}
function error_destination(bool $form = false): array
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $allowed = ['login.php','password_change.php','password_verify.php','student_create.php','teacher_create.php'];
    if ($form && in_array($page, $allowed, true)) { return [$page, '画面を開き直す']; }
    if ($form && in_array($page, ['student_edit.php','student_notes.php','teacher_edit.php'], true)
        && isset($_GET['id']) && is_string($_GET['id']) && preg_match('/^[1-9][0-9]{0,9}$/D', $_GET['id']) && (int)$_GET['id'] <= 4294967295) {
        return [$page . '?id=' . $_GET['id'], '最新の画面を開く'];
    }
    return empty($_SESSION['user_id']) ? ['login.php','ログイン画面へ戻る'] : ['students.php','生徒一覧へ戻る'];
}
function error_page(int $status, string $message, ?string $reference = null, bool $form = false): never
{
    $base = $GLOBALS['app_output_level'] ?? ob_get_level();
    while (ob_get_level() > $base) { if (!@ob_end_clean()) { break; } }
    if (!headers_sent()) {
        header_remove('Location'); http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8'); header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff'); header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin'); header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        if ($status === 503) { header('Retry-After: 60'); }
    }
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    [$return, $label] = error_destination($form);
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>操作を完了できません | βteam</title><link rel="stylesheet" href="assets/app.css"></head><body><main class="container"><section class="panel error-page"><span class="eyebrow">' . $status . '</span><h1>操作を完了できません</h1><p>' . $escape($message) . '</p>';
    if ($reference !== null) { echo '<p class="subtle small">問い合わせID：' . $escape($reference) . '</p>'; }
    echo '<a class="button" href="' . $escape($return) . '">' . $escape($label) . '</a></section></main></body></html>';
    exit;
}
function handle_failure(Throwable $error): never
{
    static $handling = false;
    if ($handling) { http_response_code(500); exit('現在操作を完了できません。'); }
    $handling = true;
    $status = failure_status($error);
    diagnostic_log('request.failure', $error, $status);
    if (PHP_SAPI === 'cli') { fwrite(STDERR, '処理を完了できません。問い合わせID：' . diagnostic_id() . "\n"); exit(1); }
    unset($GLOBALS['user']);
    if ($error instanceof FormError) {
        if ($error->retry_after !== null) { header('Retry-After: ' . max(1, $error->retry_after)); }
        error_page($status, $error->getMessage(), null, true);
    }
    $message = $status === 503 ? '現在操作を完了できません。時間をおき、処理結果を確認してからやり直してください。'
        : '処理中に問題が発生しました。処理結果を確認してからやり直すか、問い合わせIDを管理者へ伝えてください。';
    error_page($status, $message, diagnostic_id());
}
function install_error_handling(): void
{
    ini_set('display_errors', '0'); ini_set('display_startup_errors', '0'); ini_set('zend.exception_ignore_args', '1');
    if (PHP_SAPI !== 'cli') { header_remove('X-Powered-By'); }
    error_reporting(E_ALL);
    if (PHP_SAPI !== 'cli') { $GLOBALS['app_output_level'] = ob_get_level(); ob_start(); }
    $GLOBALS['app_error_reserve'] = str_repeat('x', 65536);
    set_exception_handler('handle_failure');
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) { return false; }
        throw new ErrorException('PHP runtime warning', 0, $severity, $file, $line);
    });
    register_shutdown_function(static function (): void {
        $last = error_get_last();
        if ($last && in_array($last['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR], true)) {
            unset($GLOBALS['app_error_reserve']);
            handle_failure(new ErrorException('PHP fatal error', 0, $last['type'], $last['file'], $last['line']));
        }
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) {
            try { if (!session_write_close()) { throw new ServiceUnavailable('Session write failed'); } }
            catch (Throwable $error) { diagnostic_log('session.write', $error, 503); handle_failure(new ServiceUnavailable('Session write failed')); }
        }
    });
}
function apply_form_error(FormError $error, array &$errors, string $key = 'save'): void
{
    http_response_code($error->status);
    if ($error->retry_after !== null) { header('Retry-After: ' . max(1, $error->retry_after)); }
    $errors[$error->field ?? $key] = $error->getMessage();
    if ($error->status >= 500) {
        diagnostic_log('form.failure', $error, $error->status);
        $errors[$error->field ?? $key] .= ' 問い合わせID：' . diagnostic_id();
    }
}
function enforce_request_contract(): void
{
    $page = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $read = ['index.php','students.php','student_detail.php','teachers.php','teacher_detail.php'];
    $post = ['logout.php','student_delete.php','teacher_delete.php'];
    $allowed = in_array($page, $read, true) ? ['GET','HEAD'] : (in_array($page, $post, true) ? ['POST'] : ['GET','HEAD','POST']);
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed)); error_page(405, 'この操作は画面上のボタンから行ってください。');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { return; }
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) { error_page(413, '入力内容が大きすぎます。内容を短くして、画面から入力し直してください。', null, true); }
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if (!in_array($type, ['application/x-www-form-urlencoded','multipart/form-data'], true)) { error_page(415, '送信形式が正しくありません。画面を開き直して操作してください。', null, true); }
    $limit = (int)ini_get('max_input_vars');
    if ($limit > 0 && count($_POST, COUNT_RECURSIVE) >= $limit) { error_page(413, '選択した項目が多すぎます。項目数を減らして再度操作してください。', null, true); }
}

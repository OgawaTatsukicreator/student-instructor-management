<?php
declare(strict_types=1);

require_once __DIR__ . '/errors.php';

function e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function text_value(array $source, string $key): string { return isset($source[$key]) && is_string($source[$key]) ? $source[$key] : ''; }
function post_text(string $key): string { return text_value($_POST, $key); }
function is_post(): bool { return $_SERVER['REQUEST_METHOD'] === 'POST'; }
function cookie_base_path(string $script): string {
    $path = explode('?', $script, 2)[0];
    $dir = str_replace('\\','/',dirname(str_replace('\\','/',$path)));
    if ($dir === '/' || $dir === '.') { return '/'; }
    // CookieのPath比較は大文字小文字を区別するため、既存の%xxの表記を保持する。
    return implode('/', array_map(static fn(string $part): string =>
        preg_replace('/%25([0-9a-fA-F]{2})/', '%$1', rawurlencode($part)), explode('/', $dir))) . '/';
}
function redirect(string $path): never { header('Location: ' . $path, true, 303); exit; }
function flash(string $message): void { $_SESSION['flash'] = $message; }
function full_name(array $row): string { return $row['last_name'] . ' ' . $row['first_name']; }
function role_label(string $role): string { return ['admin'=>'管理者', 'operator'=>'運営担当', 'general'=>'一般講師'][$role] ?? '権限なし'; }

function positive_id(mixed $value): int
{
    if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string)$value) || (int)$value > 4294967295) {
        page_error(400, '指定された情報が正しくありません。');
    }
    return (int)$value;
}
function request_id(): int { return positive_id($_GET['id'] ?? null); }
function version_value(): int
{
    $value = post_text('version');
    if (!preg_match('/^(0|[1-9][0-9]{0,9})$/D', $value) || (int)$value > 4294967295) {
        throw new FormError('編集情報が正しくありません。画面を開き直してください。', 400);
    }
    return (int)$value;
}
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_input(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!is_post() || !hash_equals(csrf_token(), post_text('csrf'))) {
        error_page(403, '操作の有効期限が切れました。画面を開き直して、もう一度操作してください。', null, true);
    }
}

function password_error(string $password): ?string
{
    if (strlen($password) < 10 || strlen($password) > 50 || !preg_match('/^[\x21-\x7e]+$/D', $password)) {
        return 'パスワードは空白を含まない半角文字で10〜50文字にしてください。';
    }
    $types = (int)(bool)preg_match('/[A-Z]/', $password) + (int)(bool)preg_match('/[a-z]/', $password)
        + (int)(bool)preg_match('/[0-9]/', $password) + (int)(bool)preg_match('/[^A-Za-z0-9]/', $password);
    return $types < 2 ? '英大文字・英小文字・数字・記号のうち2種類以上を使ってください。' : null;
}

function common_fields(array $data, bool $teacher): array
{
    $errors = [];
    foreach (['last_name'=>'姓', 'first_name'=>'名'] as $key=>$label) {
        if ($data[$key] === '' || mb_strlen($data[$key]) > 50) { $errors[$key] = $label . 'は1〜50文字で入力してください。'; }
        elseif (!valid_text($data[$key], 50, false)) { $errors[$key] = $label . 'に使用できない文字があります。入力し直してください。'; }
    }
    if (mb_strlen($data['notes']) > 2000) { $errors['notes'] = '特記事項は2,000文字以内で入力してください。'; }
    elseif (!valid_text($data['notes'], 2000, true)) { $errors['notes'] = '特記事項に使用できない文字があります。入力し直してください。'; }
    if ($teacher && (strlen($data['email']) > 254 || !filter_var($data['email'], FILTER_VALIDATE_EMAIL))) {
        $errors['email'] = '正しいメールアドレスを254文字以内で入力してください。';
    }
    return $errors;
}
function valid_text(string $text, int $maximum, bool $multiline): bool
{
    return mb_check_encoding($text, 'UTF-8') && mb_strlen($text, 'UTF-8') <= $maximum
        && !preg_match($multiline ? '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/' : '/[\x00-\x1f\x7f]/', $text);
}
function field_error(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<p class="field-error" id="error-' . e($key) . '">' . e($errors[$key]) . '</p>' : '';
}
function field_attrs(array $errors, string $key): string
{
    return isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="error-' . e($key) . '"' : '';
}
function error_summary(array $errors): void
{
    if (!$errors) { return; }
    if (http_response_code() < 400) { http_response_code(422); }
    echo '<div class="notice error" role="alert" tabindex="-1"><strong>入力内容を確認してください</strong><ul>';
    foreach ($errors as $key=>$value) { echo '<li>' . e($value) . '</li>'; }
    echo '</ul></div>';
    if (http_response_code() === 409) {
        [$return, $label] = error_destination(true);
        echo '<p class="field-help">入力内容はまだ保存されていません。<a href="' . e($return) . '">' . e($label) . '</a>から内容を確認してください。</p>';
    }
}

function return_target(string $candidate): string
{
    $parts = parse_url($candidate);
    if (!$parts || isset($parts['host']) || isset($parts['scheme']) || isset($parts['fragment'])) { return 'students.php'; }
    $allowed = ['students.php','student_detail.php','student_create.php','student_edit.php','student_notes.php',
        'teachers.php','teacher_detail.php','teacher_create.php','teacher_edit.php'];
    $path = $parts['path'] ?? '';
    if (!in_array($path, $allowed, true)) { return 'students.php'; }
    parse_str($parts['query'] ?? '', $params);
    $safe = [];
    foreach (['id','page'] as $key) {
        if (isset($params[$key]) && is_string($params[$key]) && preg_match('/^[1-9][0-9]{0,8}$/D', $params[$key])) { $safe[$key] = $params[$key]; }
    }
    if (in_array($path, ['students.php','teachers.php'], true) && isset($params['q']) && is_string($params['q']) && valid_text($params['q'],100,false)) { $safe['q'] = $params['q']; }
    return $path . ($safe ? '?' . http_build_query($safe) : '');
}

function consume_limit(string $key, int $maximum, int $seconds, ?int &$retry_after = null): bool
{
    $bucket = hash('sha256', $key);
    return transaction(function () use ($bucket, $maximum, $seconds, &$retry_after): bool {
        $now = time();
        query('INSERT IGNORE INTO rate_limits (bucket_key,hits,window_started) VALUES (?,0,?)', [$bucket,$now]);
        $row = query('SELECT hits,window_started FROM rate_limits WHERE bucket_key=? FOR UPDATE', [$bucket])->fetch();
        if ($now - (int)$row['window_started'] >= $seconds) {
            query('UPDATE rate_limits SET hits=1,window_started=? WHERE bucket_key=?', [$now,$bucket]); return true;
        }
        if ((int)$row['hits'] >= $maximum) { $retry_after = max(1, $seconds - ($now - (int)$row['window_started'])); return false; }
        query('UPDATE rate_limits SET hits=hits+1 WHERE bucket_key=?', [$bucket]); return true;
    });
}

function enforce_search_limit(array $user): void
{
    $retry = 0;
    if (!consume_limit('search-user:' . (int)$user['id'],30,60,$retry)) {
        throw new FormError('検索の実行回数が上限に達しました。しばらく待ってから一覧を開き直してください。',429,null,$retry);
    }
}

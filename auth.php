<?php
declare(strict_types=1);

function user_row(int $id): array|false
{
    return query('SELECT t.*, r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.id=?', [$id])->fetch();
}
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) { return null; }
    if (time() - (int)($_SESSION['last_activity'] ?? 0) > 1800) { end_login(); flash('時間が経過したため、もう一度ログインしてください。'); return null; }
    if (time() - (int)($_SESSION['authenticated_at'] ?? 0) > 28800) { end_login(); flash('ログインから8時間が経過したため、もう一度ログインしてください。'); return null; }
    $user = user_row((int)$_SESSION['user_id']);
    if (!$user || !in_array($user['role'], ['admin','operator','general'], true)
        || (int)$user['credential_version'] !== (int)($_SESSION['credential_version'] ?? -1)) {
        end_login(); flash('ログイン情報が更新されました。もう一度ログインしてください。'); return null;
    }
    $_SESSION['last_activity'] = time();
    return $user;
}
function require_login(bool $allow_forced = false): array
{
    $user = current_user();
    if (!$user) { redirect('login.php'); }
    if (!$allow_forced && (int)$user['must_change_password'] === 1) { redirect('password_change.php'); }
    $GLOBALS['user'] = $user;
    return $user;
}
function require_roles(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) { page_error(403, 'この操作を行う権限がありません。'); }
    return $user;
}
function fresh_actor(array $roles): array
{
    $user = user_row((int)$_SESSION['user_id']);
    if (!$user || !in_array($user['role'], $roles, true) || (int)$user['must_change_password'] === 1
        || (int)$user['credential_version'] !== (int)$_SESSION['credential_version']) {
        throw new FormError('権限またはログイン情報が変わりました。画面を開き直してください。', 403);
    }
    return $user;
}
function rotate_session(): void
{
    try { if (!session_regenerate_id(true)) { throw new ServiceUnavailable('Session rotation failed'); } }
    catch (Throwable $error) {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) { @session_destroy(); }
        diagnostic_log('session.rotate', $error, 503);
        throw new ServiceUnavailable('Session rotation failed');
    }
}
function begin_login(array $user): void
{
    rotate_session();
    $_SESSION = ['user_id'=>(int)$user['id'], 'credential_version'=>(int)$user['credential_version'],
        'last_activity'=>time(), 'authenticated_at'=>time(), 'csrf'=>bin2hex(random_bytes(32)), 'auth_nonce'=>bin2hex(random_bytes(32))];
}
function session_binding(): string { return hash('sha256', $_SESSION['auth_nonce'] ?? ''); }
function end_login(): void
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    $binding = !empty($_SESSION['auth_nonce']) ? session_binding() : null;
    $_SESSION = [];
    rotate_session();
    if ($id && $binding !== null) {
        try { query('DELETE FROM password_changes WHERE teacher_id=? AND session_binding=?', [$id,$binding]); }
        catch (Throwable $error) { diagnostic_log('logout.cleanup', $error, failure_status($error)); }
    }
}
function assigned_to(int $student_id, int $teacher_id): bool
{
    return (bool)query('SELECT 1 FROM student_teacher WHERE student_id=? AND teacher_id=?', [$student_id,$teacher_id])->fetchColumn();
}
function may_edit_notes(array $user, int $id): bool
{
    return in_array($user['role'], ['admin','operator'], true) || assigned_to($id, (int)$user['id']);
}

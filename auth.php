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
        throw new FormError('権限またはログイン情報が変わりました。画面を開き直してください。');
    }
    return $user;
}
function begin_login(array $user): void
{
    session_regenerate_id(true);
    $_SESSION = ['user_id'=>(int)$user['id'], 'credential_version'=>(int)$user['credential_version'],
        'last_activity'=>time(), 'csrf'=>bin2hex(random_bytes(32)), 'auth_nonce'=>bin2hex(random_bytes(32))];
}
function session_binding(): string { return hash('sha256', $_SESSION['auth_nonce'] ?? ''); }
function end_login(): void
{
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['auth_nonce'])) {
        query('DELETE FROM password_changes WHERE teacher_id=? AND session_binding=?', [(int)$_SESSION['user_id'],session_binding()]);
    }
    $_SESSION = [];
    session_regenerate_id(true);
}
function assigned_to(int $student_id, int $teacher_id): bool
{
    return (bool)query('SELECT 1 FROM student_teacher WHERE student_id=? AND teacher_id=?', [$student_id,$teacher_id])->fetchColumn();
}
function may_edit_notes(array $user, int $id): bool
{
    return in_array($user['role'], ['admin','operator'], true) || assigned_to($id, (int)$user['id']);
}

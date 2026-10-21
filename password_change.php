<?php
require __DIR__ . '/bootstrap.php';
$user = require_login(true);
if (!is_post() && isset($_GET['return'])) { $_SESSION['password_return'] = return_target(text_value($_GET, 'return')); }
$return = (int)$user['must_change_password'] ? 'students.php' : return_target($_SESSION['password_return'] ?? 'students.php');
$errors = [];
if (is_post()) {
    csrf_check();
    $next = post_text('new_password');
    if (strlen(post_text('current_password')) > 1024 || !password_verify(post_text('current_password'), $user['password'])) { $errors['current_password'] = '現在のパスワードが正しくありません。'; }
    if ($error = password_error($next)) { $errors['new_password'] = $error; }
    if (!hash_equals($next, post_text('confirm_password'))) { $errors['confirm_password'] = '新しいパスワードと確認用の入力が一致しません。'; }
    if (!$errors && password_verify($next, $user['password'])) { $errors['new_password'] = '現在と異なるパスワードを指定してください。'; }
    if (!$errors) {
        try {
            transaction(function () use ($user, $next): void {
                management_lock();
                $fresh = user_row((int)$user['id']);
                if (!$fresh || (int)$fresh['credential_version'] !== (int)$user['credential_version']) { throw new FormError('ログイン情報が変わりました。画面を開き直してください。'); }
                query('UPDATE teachers SET password=?,must_change_password=0,credential_version=credential_version+1,version=version+1 WHERE id=?', [password_hash($next, PASSWORD_DEFAULT),$user['id']]);
                query('DELETE FROM password_changes WHERE teacher_id=?', [$user['id']]);
            });
            $_SESSION['credential_version'] = (int)$user['credential_version'] + 1;
            session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32));
            unset($_SESSION['password_return']);
            flash('パスワードを変更しました。'); redirect($return);
        } catch (FormError $e) { $errors['save'] = $e->getMessage(); }
    }
}
page_header('パスワード変更');
?><div class="form-panel"><a class="back-link" href="<?=e($return)?>">← 元の画面へ戻る</a><section class="panel"><div class="form-title"><span class="eyebrow">アカウント設定</span><h1>パスワード変更</h1><p class="subtle small"><?=(int)$user['must_change_password'] ? '初回ログインのため、ご自身のパスワードに変更してください。' : '変更後はログインしたまま、元の画面へ戻ります。'?></p></div><?php error_summary($errors); ?>
<form method="post" action="password_change.php" class="stack"><?=csrf_input()?>
<label>現在のパスワード<input type="password" name="current_password" autocomplete="current-password" required <?=field_attrs($errors,'current_password')?>><?=field_error($errors,'current_password')?></label>
<label>新しいパスワード<input type="password" name="new_password" minlength="10" maxlength="50" autocomplete="new-password" required <?=field_attrs($errors,'new_password')?>><?=field_error($errors,'new_password')?><span class="field-help">半角10〜50文字。英大文字・英小文字・数字・記号のうち2種類以上。空白は使えません。</span></label>
<label>新しいパスワード（確認）<input type="password" name="confirm_password" minlength="10" maxlength="50" autocomplete="new-password" required <?=field_attrs($errors,'confirm_password')?>><?=field_error($errors,'confirm_password')?></label>
<div class="actions"><a class="button secondary" href="<?=e($return)?>">キャンセル</a><button class="button" type="submit">パスワードを変更</button></div></form></section></div><?php page_footer(); ?>

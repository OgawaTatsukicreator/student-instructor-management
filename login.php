<?php
require __DIR__ . '/bootstrap.php';
if ($existing = current_user()) { redirect((int)$existing['must_change_password'] ? 'password_change.php' : 'index.php'); }
$email = trim(post_text('email'));
$errors = [];
if (is_post()) {
    csrf_check();
    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors['email'] = 'メールアドレスを正しく入力してください。'; }
    if (post_text('password') === '' || strlen(post_text('password')) > 1024) { $errors['password'] = 'パスワードを入力してください。'; }
    if (!$errors) {
        $email = strtolower($email);
        $by_ip = consume_limit('login-ip:' . ($_SERVER['REMOTE_ADDR'] ?? ''), 30, 900);
        $by_account = consume_limit('login-account:' . $email, 5, 900);
        if (!$by_ip || !$by_account) { $errors['login'] = 'ログイン試行が上限に達しました。15分ほどおいてから再度お試しください。'; }
        else {
            $row = query('SELECT t.*,r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.email=?', [$email])->fetch();
            $valid = password_verify(post_text('password'), $row['password'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$row || !$valid || !in_array($row['role'], ['admin','operator','general'], true)) { $errors['login'] = 'メールアドレスまたはパスワードが正しくありません。'; }
            else {
                query('DELETE FROM rate_limits WHERE bucket_key=?', [hash('sha256','login-account:' . $email)]);
                begin_login($row); redirect((int)$row['must_change_password'] ? 'password_change.php' : 'index.php');
            }
        }
    }
}
page_header('ログイン', '', true);
?><section class="login-card"><div class="login-intro"><span class="eyebrow">生徒と講師をつなぐ名簿</span><h1>講師ログイン</h1><p>登録済みのアカウントでログインしてください。</p></div><?php error_summary($errors); ?><form method="post" action="login.php" class="stack"><?=csrf_input()?>
<label>メールアドレス<input type="email" name="email" value="<?=e($email)?>" maxlength="254" autocomplete="username" required <?=field_attrs($errors,'email')?>><?=field_error($errors,'email')?></label>
<label>パスワード<span class="password-field"><input type="password" id="login-password" name="password" autocomplete="current-password" required <?=field_attrs($errors,'password')?>><button type="button" class="show-password" data-toggle-password="login-password" aria-pressed="false">表示</button></span><?=field_error($errors,'password')?></label>
<button class="button full" type="submit">ログイン</button></form><p class="subtle small">パスワードを忘れた場合は、管理者へ連絡してください。</p></section><?php page_footer(); ?>

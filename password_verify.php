<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/mailer.php';
$user=require_login(true);
if (!$config['mail_verification']) { redirect('password_change.php'); }
$row=pending_change($user);
if (!$row || !(int)$row['sent'] || (int)$row['expires_at']<=time()) {
    cancel_change($user); flash('認証手続きが見つからないか、有効期限が切れました。入力からやり直してください。'); redirect('password_change.php');
}
$errors=[];
if (is_post()) {
    csrf_check(); $action=post_text('action');
    if ($action==='cancel') { cancel_change($user); flash('パスワード変更をキャンセルしました。'); redirect((int)$user['must_change_password']?'password_change.php':password_return($user)); }
    try {
        if ($action==='resend') {
            deliver_change($user,null,null,$row['token']); flash('認証コードを再送しました。以前のコードは使えません。'); redirect('password_verify.php');
        }
        if ($action!=='verify') { page_error(400,'操作の指定が正しくありません。'); }
        $result=confirm_change($user,post_text('code'));
        if ($result['state']==='ok') { finish_change($result['credential_version'],(int)$user['must_change_password']?'students.php':password_return($user)); }
        if ($result['state']==='wrong') { $errors['code']='認証コードが正しくありません。残り'.$result['remaining'].'回入力できます。'; }
        else {
            unset($_SESSION['pending_password_token']); flash($result['state']==='locked'?'認証コードを5回間違えたため、手続きを終了しました。入力からやり直してください。':'認証手続きが無効になりました。入力からやり直してください。'); redirect('password_change.php');
        }
    } catch (FormError $e) { apply_form_error($e, $errors, 'mail'); }
}
$email=explode('@',$row['email'],2); $masked=substr($email[0],0,1).'***@'.($email[1]??'');
page_header('メール認証');
?><div class="form-panel"><section class="panel"><div class="form-title"><span class="eyebrow">パスワード変更</span><h1>メールの認証コードを入力</h1><p class="subtle small"><?=e($masked)?>へ送った6桁のコードを入力してください。有効期限は送信から10分です。</p></div><?php error_summary($errors); ?>
<form method="post" action="password_verify.php" class="stack"><?=csrf_input()?><input type="hidden" name="action" value="verify"><label>認証コード<input name="code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" autocomplete="one-time-code" required <?=field_attrs($errors,'code')?>><?=field_error($errors,'code')?></label><button class="button" type="submit">認証してパスワードを変更</button></form>
<div class="actions"><form method="post" action="password_verify.php"><?=csrf_input()?><input type="hidden" name="action" value="cancel"><button class="button secondary" type="submit">キャンセル</button></form><form method="post" action="password_verify.php"><?=csrf_input()?><input type="hidden" name="action" value="resend"><button class="button secondary" type="submit">コードを再送</button></form></div><p class="field-help">再送は60秒おき、1時間に5回までです。メールが届かない場合は迷惑メールフォルダーも確認してください。</p></section></div><?php page_footer(); ?>

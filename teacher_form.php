<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/services.php';
if (!isset($mode) || !in_array($mode,['create','edit'],true)) { page_error(403,'この画面は直接開けません。'); }
$user = require_roles(['admin']);
$row = $mode==='edit'?teacher_row(request_id(),$user):null;
$data = $row ?? ['last_name'=>'','first_name'=>'','email'=>'','role'=>'general','notes'=>''];
$version = $row['version']??0; $errors=[];
if (is_post()) {
    csrf_check(); $data=teacher_data(); $errors=common_fields($data,true);
    if (!in_array($data['role'],['admin','operator','general'],true)) { $errors['role']='権限を正しく選択してください。'; }
    if (!$row && ($message=password_error(post_text('initial_password')))) { $errors['initial_password']=$message; }
    try { if ($row) { $version=version_value(); } } catch (FormError $e) { $errors['save']=$e->getMessage(); }
    if (!$errors) {
        try {
            save_teacher($row?(int)$row['id']:null,$data,$row?$version:null,$row?null:password_hash(post_text('initial_password'),PASSWORD_DEFAULT));
            flash($row?'講師情報を保存しました。':'講師を登録しました。初期パスワードを本人へ伝えてください。'); redirect('teachers.php');
        } catch (FormError $e) { $errors['save']=$e->getMessage(); }
    }
}
$title=$row?'講師情報の編集':'講師登録'; $self=$row&&(int)$row['id']===(int)$user['id'];
page_header($title,'teachers');
?><div class="form-panel"><a class="back-link" href="teachers.php">← 講師一覧へ戻る</a><section class="panel"><div class="form-title"><span class="eyebrow">講師情報</span><h1><?=e($title)?></h1><p class="subtle small">権限によって操作できる範囲が変わります。</p></div><p class="field-help">保存前に別の画面へ移動すると、入力内容は失われます。</p><?php error_summary($errors); ?>
<form method="post" action="<?=$row?'teacher_edit.php?id='.(int)$row['id']:'teacher_create.php'?>"><?=csrf_input()?><?php if ($row): ?><input type="hidden" name="version" value="<?=(int)$version?>"><?php endif; ?><div class="form-grid">
<label>姓<span class="required">必須</span><input name="last_name" value="<?=e($data['last_name'])?>" maxlength="50" autocomplete="off" required <?=field_attrs($errors,'last_name')?>><?=field_error($errors,'last_name')?></label>
<label>名<span class="required">必須</span><input name="first_name" value="<?=e($data['first_name'])?>" maxlength="50" autocomplete="off" required <?=field_attrs($errors,'first_name')?>><?=field_error($errors,'first_name')?></label>
<label>メールアドレス<span class="required">必須</span><input type="email" name="email" value="<?=e($data['email'])?>" maxlength="254" autocomplete="off" required <?=field_attrs($errors,'email')?>><?=field_error($errors,'email')?></label>
<label>権限<span class="required">必須</span><?php if ($self): ?><input type="hidden" name="role" value="<?=e($row['role'])?>"><input value="<?=e($row['role'])?>" readonly><span class="field-help">ご自身の権限は変更できません。</span><?php else: ?><select name="role" required <?=field_attrs($errors,'role')?>><?php foreach (['general','operator','admin'] as $role): ?><option value="<?=e($role)?>" <?=$data['role']===$role?'selected':''?>><?=e($role)?>（<?=e(role_label($role))?>）</option><?php endforeach; ?></select><?php endif; ?><?=field_error($errors,'role')?></label>
<?php if (!$row): ?><label class="span-two">初期パスワード<span class="required">必須</span><input type="password" name="initial_password" minlength="10" maxlength="50" autocomplete="new-password" required <?=field_attrs($errors,'initial_password')?>><?=field_error($errors,'initial_password')?><span class="field-help">半角10〜50文字。英大文字・英小文字・数字・記号から2種類以上。登録後に本人へ伝えてください。初回ログイン時に変更が必要です。</span></label><?php endif; ?>
<label class="span-two">特記事項<textarea name="notes" maxlength="2000" rows="6" <?=field_attrs($errors,'notes')?>><?=e($data['notes'])?></textarea><?=field_error($errors,'notes')?><span class="field-help">2,000文字以内で入力してください。</span></label></div>
<div class="actions"><?php if ($row&&!$self): ?><button class="button danger push-left" type="button" data-open-delete>削除</button><?php endif; ?><a class="button secondary" href="teachers.php">キャンセル</a><button class="button" type="submit"><?=$row?'保存する':'登録する'?></button></div></form></section></div>
<?php if ($row&&!$self) { deletion_dialog('teacher_delete.php',$row,'講師'); } page_footer(); ?>

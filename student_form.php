<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/services.php';
if (!isset($mode) || !in_array($mode,['create','edit','notes'],true)) { page_error(403,'この画面は直接開けません。'); }
$notes_only = $mode === 'notes';
$user = $notes_only ? require_login() : require_roles(['admin','operator']);
$row = $mode === 'create' ? null : student_row(request_id());
if ($notes_only && !may_edit_notes($user,(int)$row['id'])) { page_error(403,'担当していない生徒の特記事項は編集できません。'); }
$options = $notes_only ? [] : teacher_options();
$data = $row ?? ['last_name'=>'','first_name'=>'','birth_date'=>'','notes'=>''];
$selected = $row ? array_map('intval',array_column(student_teachers((int)$row['id']),'id')) : [];
$version = $row['version'] ?? 0;
$return = $notes_only ? return_target(is_post() ? post_text('return') : (text_value($_GET,'return') ?: 'student_detail.php?id=' . $row['id'])) : 'students.php';
$errors = [];
if (is_post()) {
    csrf_check();
    if ($notes_only) {
        $data['notes'] = post_text('notes');
        $errors = mb_strlen($data['notes']) > 2000 ? ['notes'=>'特記事項は2,000文字以内で入力してください。'] : [];
        if (!$errors && !valid_text($data['notes'],2000,true)) { $errors['notes']='特記事項に使用できない文字があります。入力し直してください。'; }
    }
    else { $data = student_data(); $errors = student_errors($data); $selected = selected_teacher_ids($errors); }
    try { if ($row) { $version = version_value(); } } catch (FormError $e) { apply_form_error($e, $errors); }
    if (!$errors) {
        try {
            save_student($row ? (int)$row['id'] : null,$data,$selected,$row ? $version : null,$notes_only);
            flash($mode === 'create' ? '生徒を登録しました。' : ($notes_only ? '特記事項を保存しました。' : '生徒情報を保存しました。')); redirect($return);
        } catch (FormError $e) { apply_form_error($e, $errors); }
    }
}
$title = ['create'=>'生徒登録','edit'=>'生徒情報の編集','notes'=>'特記事項の編集'][$mode];
$path = ['create'=>'student_create.php','edit'=>'student_edit.php','notes'=>'student_notes.php'][$mode];
page_header($title,'students');
?><div class="form-panel"><a class="back-link" href="<?=e($return)?>">← <?=$notes_only ? '元の画面へ戻る' : '生徒一覧へ戻る'?></a><section class="panel"><div class="form-title"><span class="eyebrow">生徒情報</span><h1><?=e($title)?></h1><p class="subtle small"><?=$notes_only ? e(full_name($row)) . 'さんの特記事項を編集します。担当講師で共有されます。' : '必須項目を入力し、担当講師を1名以上選択してください。'?></p></div><p class="field-help">保存前に別の画面へ移動すると、入力内容は失われます。</p><?php error_summary($errors); ?>
<form method="post" action="<?=e($path)?><?=$row?'?id=' . (int)$row['id']:''?>"><?=csrf_input()?><?php if ($row): ?><input type="hidden" name="version" value="<?=(int)$version?>"><?php endif; ?><input type="hidden" name="return" value="<?=e($return)?>"><div class="form-grid">
<?php if (!$notes_only): ?>
<label>姓<span class="required">必須</span><input name="last_name" value="<?=e($data['last_name'])?>" maxlength="50" autocomplete="off" required <?=field_attrs($errors,'last_name')?>><?=field_error($errors,'last_name')?></label>
<label>名<span class="required">必須</span><input name="first_name" value="<?=e($data['first_name'])?>" maxlength="50" autocomplete="off" required <?=field_attrs($errors,'first_name')?>><?=field_error($errors,'first_name')?></label>
<label>生年月日<span class="required">必須</span><input type="date" name="birth_date" value="<?=e($data['birth_date'])?>" max="<?=date('Y-m-d')?>" required <?=field_attrs($errors,'birth_date')?>><?=field_error($errors,'birth_date')?></label>
<fieldset class="span-two teacher-field"><legend>担当講師<span class="required">1名以上必須</span></legend><p class="field-help">複数の講師を選択できます。</p><?=field_error($errors,'teacher_ids')?><div class="check-options"><?php foreach ($options as $option): ?><label class="check-option"><input type="checkbox" name="teacher_ids[]" value="<?=(int)$option['id']?>" <?=in_array((int)$option['id'],$selected,true)?'checked':''?>><span><?=e(full_name($option))?><small>講師 #<?=(int)$option['id']?></small></span></label><?php endforeach; ?><?php if (!$options): ?><p class="subtle">選択できる講師がいません。管理者へ連絡してください。</p><?php endif; ?></div></fieldset>
<?php endif; ?>
<label class="span-two">特記事項<textarea name="notes" maxlength="2000" rows="6" <?=field_attrs($errors,'notes')?>><?=e($data['notes'])?></textarea><?=field_error($errors,'notes')?><span class="field-help">2,000文字以内。複数の担当講師が編集できる共有欄です。</span></label></div>
<div class="actions"><?php if ($mode === 'edit'): ?><button type="button" class="button danger push-left" data-open-delete>削除</button><?php endif; ?><a class="button secondary" href="<?=e($return)?>">キャンセル</a><button class="button" type="submit"><?=$mode === 'create'?'登録する':'保存する'?></button></div></form></section></div>
<?php if ($mode === 'edit') { deletion_dialog('student_delete.php',$row,'生徒'); } page_footer(); ?>

<?php
require __DIR__ . '/bootstrap.php';
$user=require_roles(['admin']);
if (!is_post()) { page_error(405,'削除は編集画面の確認ボタンから行ってください。'); }
csrf_check(); $id=request_id();
try {
    $version=version_value();
    transaction(function () use ($id,$version): void {
        management_lock(); $actor=fresh_actor(['admin']);
        $row=query('SELECT t.version,r.name AS role FROM teachers t JOIN roles r ON r.id=t.role_id WHERE t.id=? FOR UPDATE',[$id])->fetch();
        if (!$row) { throw new FormError('この講師情報は既に削除されています。'); }
        if ($id===(int)$actor['id']) { throw new FormError('ご自身のアカウントは削除できません。'); }
        if ((int)$row['version']!==$version) { throw new FormError('他のユーザーが更新しました。内容を確認してから削除してください。'); }
        if ($row['role']==='admin'&&(int)query("SELECT COUNT(*) FROM teachers t JOIN roles r ON r.id=t.role_id WHERE r.name='admin'")->fetchColumn()<=1) { throw new FormError('最後の管理者は削除できません。'); }
        if (query('SELECT 1 FROM student_teacher WHERE teacher_id=? LIMIT 1',[$id])->fetch()) { throw new FormError('担当生徒がいます。先に担当講師を変更してください。'); }
        query('DELETE FROM teachers WHERE id=?',[$id]);
    });
    flash('講師を削除しました。'); redirect('teachers.php');
} catch (FormError $e) { page_error(409,$e->getMessage()); }

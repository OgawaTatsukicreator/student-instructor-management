<?php
require __DIR__ . '/bootstrap.php';
$user = require_roles(['admin','operator']);
if (!is_post()) { page_error(405,'削除は編集画面の確認ボタンから行ってください。'); }
csrf_check(); $id = request_id();
try {
    $version = version_value();
    transaction(function () use ($id,$version): void {
        management_lock(); fresh_actor(['admin','operator']);
        $row = query('SELECT version FROM students WHERE id=? FOR UPDATE',[$id])->fetch();
        if (!$row) { throw new FormError('この生徒情報は既に削除されています。'); }
        if ((int)$row['version'] !== $version) { throw new FormError('他のユーザーが更新しました。内容を確認してから削除してください。'); }
        query('DELETE FROM students WHERE id=?',[$id]);
    });
    flash('生徒を削除しました。'); redirect('students.php');
} catch (FormError $e) { page_error(409,$e->getMessage()); }

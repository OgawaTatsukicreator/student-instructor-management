<?php
require __DIR__ . '/bootstrap.php';
$user = require_login();
if (is_file(__DIR__ . '/students.php')) { redirect('students.php'); }
page_header('ログイン完了');
?><section class="panel"><h1>ログインできました</h1><p><?=e(full_name($user))?>さんのアカウントを確認しました。</p></section><?php page_footer(); ?>

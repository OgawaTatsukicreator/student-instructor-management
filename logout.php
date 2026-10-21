<?php
require __DIR__ . '/bootstrap.php';
if (!is_post()) { page_error(405, 'ログアウトは画面上のボタンから行ってください。'); }
csrf_check(); end_login(); flash('ログアウトしました。'); redirect('login.php');

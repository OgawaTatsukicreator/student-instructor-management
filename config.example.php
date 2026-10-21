<?php
// config.local.phpへコピーし、ご自身の環境に合わせて設定してください。
return [
    'db' => ['host' => '127.0.0.1', 'port' => 3307, 'name' => 'βteam', 'user' => 'root', 'password' => ''],
    // メール認証を有効にする際は bin2hex(random_bytes(32)) 等で秘密鍵を生成。
    'app_key' => '',
    'mail_verification' => false,
    'smtp' => ['host' => '', 'port' => 587, 'security' => 'tls', 'username' => '', 'password' => '',
        'from' => '', 'from_name' => 'βteam'],
];

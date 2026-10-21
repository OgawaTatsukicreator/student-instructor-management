<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require __DIR__ . '/bootstrap.php';
try {
    $data = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new FormError('入力が正しくありません。'); }
    $values = ['last_name'=>trim(text_value($data,'last_name')), 'first_name'=>trim(text_value($data,'first_name')),
        'email'=>strtolower(trim(text_value($data,'email'))), 'notes'=>''];
    $errors = common_fields($values, true);
    if ($message = password_error(text_value($data,'password'))) { $errors['password'] = $message; }
    if ($errors) { throw new FormError(implode("\n", $errors)); }
    $hash = password_hash($data['password'], PASSWORD_DEFAULT);
    unset($data['password']);
    transaction(function () use ($values,$hash): void {
        management_lock();
        if ((int)query('SELECT COUNT(*) FROM teachers')->fetchColumn() !== 0) { throw new FormError('講師は登録済みです。ログイン後に管理者の画面から追加してください。'); }
        $role = query("SELECT id FROM roles WHERE name='admin'")->fetchColumn();
        if (!$role) { throw new FormError('adminの権限データがありません。database.sqlの読込を確認してください。'); }
        query('INSERT INTO teachers (last_name,first_name,email,password,role_id,notes) VALUES (?,?,?,?,?,?)',
            [$values['last_name'],$values['first_name'],$values['email'],$hash,$role,'']);
    });
    fwrite(STDOUT, "初期管理者を作成しました。初回ログイン後にパスワードを変更してください。\n");
} catch (FormError $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); }
catch (Throwable $e) { fwrite(STDERR, "設定またはDBの準備を確認してください。\n"); exit(1); }

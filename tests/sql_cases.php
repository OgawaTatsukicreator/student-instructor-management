<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/** 各ケースは共通の架空データから開始し、終了後にSAVEPOINTまで戻す。 */
function sql_test_cases(): array
{
    $cases = [];
    $add = static function (string $table, string $title, string $expected, array $steps) use (&$cases): void {
        $number = count(array_filter($cases, static fn(array $case): bool => $case['table'] === $table)) + 1;
        $cases[] = ['id' => $table . '-' . sprintf('%02d', $number), 'table' => $table, 'title' => $title, 'expected' => $expected, 'steps' => $steps];
    };
    $v = static fn(string $sql, string|int $value): array => ['sql' => $sql, 'value' => (string)$value];
    $e = static fn(string $sql, array $codes): array => ['sql' => $sql, 'error' => $codes];
    $q = static fn(string $sql): array => ['sql' => $sql];

    $add('roles', '初期権限の件数', '3件', [$v('SELECT COUNT(*) FROM roles', 3)]);
    $add('roles', '権限名の一覧', 'admin,general,operator', [$v("SELECT GROUP_CONCAT(name ORDER BY CAST(name AS CHAR) SEPARATOR ',') FROM roles", 'admin,general,operator')]);
    $add('roles', '権限名の重複登録', '一意制約で拒否', [$e("INSERT INTO roles(name) VALUES('admin')", [1062])]);
    $add('roles', '定義外の権限', 'ENUMで拒否', [$e("INSERT INTO roles(name) VALUES('owner')", [1265])]);
    $add('roles', 'NULLの権限名', 'NOT NULLで拒否', [$e('INSERT INTO roles(name) VALUES(NULL)', [1048])]);
    $add('roles', '主キーの重複', '主キー制約で拒否', [$e("INSERT INTO roles(id,name) SELECT id,'general' FROM roles WHERE name='admin'", [1062])]);
    $add('roles', '使用中の権限の削除', '外部キーで拒否', [$e("DELETE FROM roles WHERE name='admin'", [1451])]);
    $add('roles', '使用中の権限IDの変更', '外部キーで拒否', [$e("UPDATE roles SET id=200 WHERE name='admin'", [1451])]);
    $add('roles', '既存名への権限変更', '一意制約で拒否', [$e("UPDATE roles SET name='operator' WHERE name='general'", [1062])]);
    $add('roles', '全権限の講師が担当を持つ', '3権限とも担当あり', [$v('SELECT COUNT(DISTINCT t.role_id) FROM teachers t JOIN student_teacher st ON st.teacher_id=t.id', 3)]);

    $add('teachers', '架空講師の件数', '10件', [$v('SELECT COUNT(*) FROM teachers', 10)]);
    $add('teachers', '日本語氏名とハッシュの格納', '10件とも日本語氏名・ハッシュあり', [$v("SELECT COUNT(*) FROM teachers WHERE last_name='試験講師' AND CHAR_LENGTH(password)>=60 AND password<>'SqlTest123!'", 10)]);
    $add('teachers', 'メールの重複', '一意制約で拒否', [$e("UPDATE teachers SET email='teacher1@example.invalid' WHERE id=10002", [1062])]);
    $add('teachers', 'メールの大文字小文字違い', '同じメールとして拒否', [$e("UPDATE teachers SET email='TEACHER1@EXAMPLE.INVALID' WHERE id=10002", [1062])]);
    $add('teachers', '存在しない権限', '外部キーで拒否', [$e('UPDATE teachers SET role_id=250 WHERE id=10001', [1452])]);
    $add('teachers', 'NULLの姓', 'NOT NULLで拒否', [$e('UPDATE teachers SET last_name=NULL WHERE id=10001', [1048])]);
    $add('teachers', '姓の51文字超過', '文字数超過で拒否', [$e("UPDATE teachers SET last_name=REPEAT('あ',51) WHERE id=10001", [1406])]);
    $add('teachers', '初回変更フラグの範囲', '2はCHECKで拒否', [$e('UPDATE teachers SET must_change_password=2 WHERE id=10001', [4025, 3819])]);
    $add('teachers', '更新と古いversionによる更新', '最初の更新だけ反映', [$q("UPDATE teachers SET notes='変更済み',version=version+1 WHERE id=10001 AND version=0"), $q("UPDATE teachers SET notes='古い編集',version=version+1 WHERE id=10001 AND version=0"), $v("SELECT CONCAT(notes,':',version) FROM teachers WHERE id=10001", '変更済み:1')]);
    $add('teachers', '担当のない講師の削除', '削除できる', [$q('DELETE FROM teachers WHERE id=10002'), $v('SELECT COUNT(*) FROM teachers WHERE id=10002', 0)]);

    $add('students', '架空生徒の件数', '10件', [$v('SELECT COUNT(*) FROM students', 10)]);
    $add('students', '日本語の特記事項', '入力した文字列と一致', [$v('SELECT notes FROM students WHERE id=10003', 'テスト用の特記事項3')]);
    $add('students', '実在するうるう日', '2012-02-29', [$v('SELECT birth_date FROM students WHERE id=10001', '2012-02-29')]);
    $add('students', '存在しない日付', '日付エラーで拒否', [$e("UPDATE students SET birth_date='2011-02-29' WHERE id=10001", [1292])]);
    $add('students', 'NULLの生年月日', 'NOT NULLで拒否', [$e('UPDATE students SET birth_date=NULL WHERE id=10001', [1048])]);
    $add('students', '姓の51文字超過', '文字数超過で拒否', [$e("UPDATE students SET last_name=REPEAT('あ',51) WHERE id=10001", [1406])]);
    $add('students', '更新と古いversionによる更新', '最初の更新だけ反映', [$q("UPDATE students SET notes='保存済み',version=version+1 WHERE id=10001 AND version=0"), $q("UPDATE students SET notes='競合',version=version+1 WHERE id=10001 AND version=0"), $v("SELECT CONCAT(notes,':',version) FROM students WHERE id=10001", '保存済み:1')]);
    $add('students', '生徒削除時の担当関係', '担当関係もCASCADEで削除', [$q('DELETE FROM students WHERE id=10001'), $v('SELECT COUNT(*) FROM student_teacher WHERE student_id=10001', 0), $v('SELECT COUNT(*) FROM teachers WHERE id=10001', 1)]);
    $add('students', '特記事項のNULLと空文字', 'それぞれ保持', [$v('SELECT notes IS NULL FROM students WHERE id=10001', 1), $v("SELECT notes='' FROM students WHERE id=10002", 1)]);
    $add('students', 'アプリ側で制限する入力', 'DB単独では空の姓・未来日を保存できる', [$q("UPDATE students SET last_name='',birth_date='2099-01-01' WHERE id=10001"), $v("SELECT COUNT(*) FROM students WHERE id=10001 AND last_name='' AND birth_date='2099-01-01'", 1)]);

    $add('student_teacher', '初期担当関係の件数', '10件', [$v('SELECT COUNT(*) FROM student_teacher', 10)]);
    $add('student_teacher', '同じ担当関係の重複', '複合主キーで拒否', [$e('INSERT INTO student_teacher VALUES(10001,10001)', [1062])]);
    $add('student_teacher', '存在しない生徒', '外部キーで拒否', [$e('INSERT INTO student_teacher VALUES(99999,10001)', [1452])]);
    $add('student_teacher', '存在しない講師', '外部キーで拒否', [$e('INSERT INTO student_teacher VALUES(10001,99999)', [1452])]);
    $add('student_teacher', 'NULLの生徒ID', 'NOT NULLで拒否', [$e('INSERT INTO student_teacher VALUES(NULL,10001)', [1048])]);
    $add('student_teacher', '生徒1人に複数講師', '2名を割り当て可能', [$q('INSERT INTO student_teacher VALUES(10001,10002)'), $v('SELECT COUNT(*) FROM student_teacher WHERE student_id=10001', 2)]);
    $add('student_teacher', '講師1人に複数生徒', '2人を担当', [$v('SELECT COUNT(*) FROM student_teacher WHERE teacher_id=10001', 2)]);
    $add('student_teacher', '全生徒に担当が存在', '未担当の生徒は0人', [$v('SELECT COUNT(*) FROM students s WHERE NOT EXISTS(SELECT 1 FROM student_teacher st WHERE st.student_id=s.id)', 0)]);
    $add('student_teacher', '担当関係だけの削除', '生徒と講師は残る', [$q('DELETE FROM student_teacher WHERE student_id=10001 AND teacher_id=10001'), $v('SELECT COUNT(*) FROM students WHERE id=10001', 1), $v('SELECT COUNT(*) FROM teachers WHERE id=10001', 1)]);
    $add('student_teacher', '担当のある講師の削除', 'RESTRICTで拒否', [$e('DELETE FROM teachers WHERE id=10001', [1451])]);

    $add('system_locks', '初期ロックの件数', '1件', [$v('SELECT COUNT(*) FROM system_locks', 1)]);
    $add('system_locks', '管理用ロックの取得', 'managementを取得', [$v("SELECT name FROM system_locks WHERE name='management' FOR UPDATE", 'management')]);
    $add('system_locks', '同じロック名の登録', '主キーで拒否', [$e("INSERT INTO system_locks VALUES('management')", [1062])]);
    $add('system_locks', 'NULLのロック名', 'NOT NULLで拒否', [$e('INSERT INTO system_locks VALUES(NULL)', [1048])]);
    $add('system_locks', 'ロック名の51文字超過', '文字数超過で拒否', [$e("INSERT INTO system_locks VALUES(REPEAT('x',51))", [1406])]);
    $add('system_locks', '非ASCIIのロック名', '文字コードで拒否', [$e("INSERT INTO system_locks VALUES('管理')", [1366])]);
    $add('system_locks', '大文字小文字違いのロック名', '同じ名前として拒否', [$e("INSERT INTO system_locks VALUES('MANAGEMENT')", [1062])]);
    $add('system_locks', '別名ロックの追加', 'DBは別名を許可（試験後に戻す）', [$q("INSERT INTO system_locks VALUES('test_aux')"), $v('SELECT COUNT(*) FROM system_locks', 2)]);
    $add('system_locks', 'ロック行の削除', 'DBは削除を許可（試験後に戻す）', [$q("DELETE FROM system_locks WHERE name='management'"), $v('SELECT COUNT(*) FROM system_locks', 0)]);
    $add('system_locks', 'ロック名の変更', 'DBは変更を許可（試験後に戻す）', [$q("UPDATE system_locks SET name='test_management' WHERE name='management'"), $v("SELECT name FROM system_locks", 'test_management')]);

    $bucket = fixture_literal(hash('sha256', 'beta-sql-test-bucket-1'));
    $add('rate_limits', '架空バケットの件数', '10件', [$v('SELECT COUNT(*) FROM rate_limits', 10)]);
    $add('rate_limits', 'バケットキーの重複', '主キーで拒否', [$e("INSERT INTO rate_limits VALUES({$bucket},1,946684800)", [1062])]);
    $add('rate_limits', 'NULLのキー', 'NOT NULLで拒否', [$e('INSERT INTO rate_limits VALUES(NULL,1,946684800)', [1048])]);
    $add('rate_limits', '負の試行回数', 'UNSIGNEDで拒否', [$e("UPDATE rate_limits SET hits=-1 WHERE bucket_key={$bucket}", [1264])]);
    $add('rate_limits', '試行回数の既定値', '0で登録', [$q("INSERT INTO rate_limits(bucket_key,window_started) VALUES(REPEAT('z',64),946684800)"), $v("SELECT hits FROM rate_limits WHERE bucket_key=REPEAT('z',64)", 0)]);
    $add('rate_limits', 'キーの65文字超過', '文字数超過で拒否', [$e("INSERT INTO rate_limits VALUES(REPEAT('x',65),1,946684800)", [1406])]);
    $add('rate_limits', '負のウィンドウ開始時刻', 'UNSIGNEDで拒否', [$e("UPDATE rate_limits SET window_started=-1 WHERE bucket_key={$bucket}", [1264])]);
    $add('rate_limits', '同じキーの試行回数加算', '1から2に増加', [$q("INSERT INTO rate_limits VALUES({$bucket},1,946684800) ON DUPLICATE KEY UPDATE hits=hits+1"), $v("SELECT hits FROM rate_limits WHERE bucket_key={$bucket}", 2)]);
    $add('rate_limits', '期限を過ぎたバケットの掃除', '架空10件を削除', [$q('DELETE FROM rate_limits WHERE window_started<946688400'), $v('SELECT COUNT(*) FROM rate_limits', 0)]);
    $add('rate_limits', 'キーの大文字小文字', 'ascii_binで別キーとして保持', [$q("INSERT INTO rate_limits VALUES(REPEAT('a',64),0,946684800),(REPEAT('A',64),0,946684800)"), $v('SELECT COUNT(*) FROM rate_limits', 12)]);

    $add('password_changes', '期限切れの保留情報の件数', '10件', [$v('SELECT COUNT(*) FROM password_changes', 10)]);
    $add('password_changes', '同じ講師の保留を追加', '主キーで拒否', [$e('INSERT INTO password_changes SELECT * FROM password_changes WHERE teacher_id=10001', [1062])]);
    $add('password_changes', 'トークンの重複', '一意制約で拒否', [$e("UPDATE password_changes SET token=" . fixture_literal(hash('sha256', 'beta-sql-test-token-1')) . ' WHERE teacher_id=10002', [1062])]);
    $add('password_changes', '存在しない講師', '外部キーで拒否', [$e('UPDATE password_changes SET teacher_id=99999 WHERE teacher_id=10001', [1452])]);
    $add('password_changes', '有効期限での抽出', '10件とも期限切れ', [$v('SELECT COUNT(*) FROM password_changes WHERE expires_at<UNIX_TIMESTAMP()', 10)]);
    $add('password_changes', '認証試行回数の加算', '0から1に増加', [$q('UPDATE password_changes SET attempts=attempts+1 WHERE teacher_id=10001'), $v('SELECT attempts FROM password_changes WHERE teacher_id=10001', 1)]);
    $add('password_changes', '講師削除時の保留情報', 'CASCADEで削除', [$q('DELETE FROM teachers WHERE id=10002'), $v('SELECT COUNT(*) FROM password_changes WHERE teacher_id=10002', 0)]);
    $add('password_changes', 'トークンの大文字小文字', '別の値として保持', [$q("UPDATE password_changes SET token=REPEAT('a',64) WHERE teacher_id=10001"), $q("UPDATE password_changes SET token=REPEAT('A',64) WHERE teacher_id=10002"), $v("SELECT COUNT(*) FROM password_changes WHERE token=REPEAT('a',64)", 1)]);
    $add('password_changes', 'NULLの変更後パスワードハッシュ', 'NOT NULLで拒否', [$e('UPDATE password_changes SET pending_hash=NULL WHERE teacher_id=10001', [1048])]);
    $add('password_changes', 'トークンの65文字超過', '文字数超過で拒否', [$e("UPDATE password_changes SET token=REPEAT('x',65) WHERE teacher_id=10001", [1406])]);
    return $cases;
}

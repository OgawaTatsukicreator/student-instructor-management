# SQLテストケース・テストデータ

7テーブルそれぞれ10ケース、合計70ケース。SQLの正常系、重複、必須項目、外部キー、文字数、削除時の関連、編集競合を確認する。実装は [tests/sql.php](../tests/sql.php)、ケース定義は [tests/sql_cases.php](../tests/sql_cases.php)。

## 実行する場所

実際の `βteam` には実行しない。XAMPPで自分で `beta_sql_test_run` という検証用DBを作り、ローカルの `database.sql` だけを読み込む。rolesは3件、system_locksはmanagementの1件、他の5テーブルは空にする。自動テストがDB本体を作成・削除することはない。

プロジェクト直下でPowerShellを開き、次を実行する。ポートは検証用DBが動く番号に合わせる。

```powershell
$env:BETATEAM_SQL_TEST_HOST = '127.0.0.1'
$env:BETATEAM_SQL_TEST_PORT = '3307'
$env:BETATEAM_SQL_TEST_DATABASE = 'beta_sql_test_run'
$env:BETATEAM_SQL_TEST_USER = 'root'
& 'C:\xampp\php\php.exe' tests\sql.php
```

DBユーザーにパスワードがある場合は `BETATEAM_SQL_TEST_PASSWORD` を安全な方法で環境変数に設定する。未設定の場合は空文字。アプリのconfig.php・config.local.phpやBETATEAM_DB_*は読み込まない。専用の接続情報が不足、DB名がbeta_sql_test_で始まらない、初期状態ではない、InnoDB以外の場合は試験を始めず終了する。

共通データをトランザクションで用意し、各ケースをSAVEPOINTで分離する。ケースごとに変更を戻し、全体も最後にROLLBACKする。試験用データは残らない。レポートは `output/sql-tests.json`（Git管理外）。終了コード0は全件成功、1は失敗または準備不足を表す。検証用DBをアプリと同時に使用しない。

## 画面確認などで残して使う架空データ

```powershell
& 'C:\xampp\php\php.exe' tests\export_sql_fixtures.php
```

`tests/test_data.sql` を生成する（Git管理外）。別の空の検証用DB `beta_sql_test_fixtures` を自分で作成し、database.sqlの後に読み込む。生成SQLのUSE句でこの専用DBを指定している。自動70ケース用のDBには読み込まない。

| テーブル | 件数 | 内容 |
|---|---:|---|
| roles | 3 | 初期定義のadmin・operator・generalを使用 |
| teachers | 10 | ID10001〜10010、架空の日本語氏名、teacher1〜10@example.invalid |
| students | 10 | ID10001〜10010、うるう日、特記事項のNULL・空文字・日本語 |
| student_teacher | 10 | 全生徒に担当1名。講師10001は生徒10001・10002の2人を担当 |
| system_locks | 1 | 初期定義のmanagementを使用 |
| rate_limits | 10 | 実際のログインキーと関係のない架空キー、古い開始時刻 |
| password_changes | 10 | 全件期限切れ・未送信の架空保留。メール送信は行わない |

3権限すべてに担当生徒を割り当てている。1人の生徒を複数講師が担当する状態はstudent_teacher-06で追加して検証する。固定マスター2テーブルを10行へ増やすと仕様に合わないため、件数は3・1のままとし、検証ケースだけ10件ずつにした。

講師10人の試験用パスワードは `SqlTest123!`、初回変更必須。SQLにはpassword_hashで生成したハッシュだけを登録する。公開された試験用パスワードなので、実際の運用には使わない。架空データを投入すると初期管理者のCLIセットアップは使用できなくなる。実環境の初期管理者は空の講師テーブルに対して [準備手順](README.md) で作成する。

## ケース一覧

各表のSQLは共通データを投入した直後の状態を前提とする。複数のSQLがある行は上から順に実行する。拒否するケースはMariaDB／MySQLのエラーコードも照合する。手作業で実行するときも必ず検証用DBとトランザクションを使う。

### roles（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| roles-01 | 初期権限の件数 | `SELECT COUNT(*) FROM roles;` | 3件 |
| roles-02 | 権限名の一覧 | `SELECT GROUP_CONCAT(name ORDER BY CAST(name AS CHAR) SEPARATOR ',') FROM roles;` | admin,general,operator |
| roles-03 | 権限名の重複登録 | `INSERT INTO roles(name) VALUES('admin');` | 一意制約で拒否（1062） |
| roles-04 | 定義外の権限 | `INSERT INTO roles(name) VALUES('owner');` | ENUMで拒否（1265） |
| roles-05 | NULLの権限名 | `INSERT INTO roles(name) VALUES(NULL);` | NOT NULLで拒否（1048） |
| roles-06 | 主キーの重複 | `INSERT INTO roles(id,name) SELECT id,'general' FROM roles WHERE name='admin';` | 主キー制約で拒否（1062） |
| roles-07 | 使用中の権限の削除 | `DELETE FROM roles WHERE name='admin';` | 外部キーで拒否（1451） |
| roles-08 | 使用中の権限IDの変更 | `UPDATE roles SET id=200 WHERE name='admin';` | 外部キーで拒否（1451） |
| roles-09 | 既存名への権限変更 | `UPDATE roles SET name='operator' WHERE name='general';` | 一意制約で拒否（1062） |
| roles-10 | 全権限の講師が担当を持つ | `SELECT COUNT(DISTINCT t.role_id) FROM teachers t JOIN student_teacher st ON st.teacher_id=t.id;` | 3権限とも担当あり |

### teachers（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| teachers-01 | 架空講師の件数 | `SELECT COUNT(*) FROM teachers;` | 10件 |
| teachers-02 | 日本語氏名とハッシュの格納 | `SELECT COUNT(*) FROM teachers WHERE last_name='試験講師' AND CHAR_LENGTH(password)>=60 AND password<>'SqlTest123!';` | 10件とも日本語氏名・ハッシュあり |
| teachers-03 | メールの重複 | `UPDATE teachers SET email='teacher1@example.invalid' WHERE id=10002;` | 一意制約で拒否（1062） |
| teachers-04 | メールの大文字小文字違い | `UPDATE teachers SET email='TEACHER1@EXAMPLE.INVALID' WHERE id=10002;` | 同じメールとして拒否（1062） |
| teachers-05 | 存在しない権限 | `UPDATE teachers SET role_id=250 WHERE id=10001;` | 外部キーで拒否（1452） |
| teachers-06 | NULLの姓 | `UPDATE teachers SET last_name=NULL WHERE id=10001;` | NOT NULLで拒否（1048） |
| teachers-07 | 姓の51文字超過 | `UPDATE teachers SET last_name=REPEAT('あ',51) WHERE id=10001;` | 文字数超過で拒否（1406） |
| teachers-08 | 初回変更フラグの範囲 | `UPDATE teachers SET must_change_password=2 WHERE id=10001;` | 2はCHECKで拒否（3819, 4025） |
| teachers-09 | 更新と古いversionによる更新 | `UPDATE teachers SET notes='変更済み',version=version+1 WHERE id=10001 AND version=0;`<br>`UPDATE teachers SET notes='古い編集',version=version+1 WHERE id=10001 AND version=0;`<br>`SELECT CONCAT(notes,':',version) FROM teachers WHERE id=10001;` | 最初の更新だけ反映 |
| teachers-10 | 担当のない講師の削除 | `DELETE FROM teachers WHERE id=10002;`<br>`SELECT COUNT(*) FROM teachers WHERE id=10002;` | 削除できる |

### students（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| students-01 | 架空生徒の件数 | `SELECT COUNT(*) FROM students;` | 10件 |
| students-02 | 日本語の特記事項 | `SELECT notes FROM students WHERE id=10003;` | 入力した文字列と一致 |
| students-03 | 実在するうるう日 | `SELECT birth_date FROM students WHERE id=10001;` | 2012-02-29 |
| students-04 | 存在しない日付 | `UPDATE students SET birth_date='2011-02-29' WHERE id=10001;` | 日付エラーで拒否（1292） |
| students-05 | NULLの生年月日 | `UPDATE students SET birth_date=NULL WHERE id=10001;` | NOT NULLで拒否（1048） |
| students-06 | 姓の51文字超過 | `UPDATE students SET last_name=REPEAT('あ',51) WHERE id=10001;` | 文字数超過で拒否（1406） |
| students-07 | 更新と古いversionによる更新 | `UPDATE students SET notes='保存済み',version=version+1 WHERE id=10001 AND version=0;`<br>`UPDATE students SET notes='競合',version=version+1 WHERE id=10001 AND version=0;`<br>`SELECT CONCAT(notes,':',version) FROM students WHERE id=10001;` | 最初の更新だけ反映 |
| students-08 | 生徒削除時の担当関係 | `DELETE FROM students WHERE id=10001;`<br>`SELECT COUNT(*) FROM student_teacher WHERE student_id=10001;`<br>`SELECT COUNT(*) FROM teachers WHERE id=10001;` | 担当関係もCASCADEで削除 |
| students-09 | 特記事項のNULLと空文字 | `SELECT notes IS NULL FROM students WHERE id=10001;`<br>`SELECT notes='' FROM students WHERE id=10002;` | それぞれ保持 |
| students-10 | アプリ側で制限する入力 | `UPDATE students SET last_name='',birth_date='2099-01-01' WHERE id=10001;`<br>`SELECT COUNT(*) FROM students WHERE id=10001 AND last_name='' AND birth_date='2099-01-01';` | DB単独では空の姓・未来日を保存できる |

### student_teacher（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| student_teacher-01 | 初期担当関係の件数 | `SELECT COUNT(*) FROM student_teacher;` | 10件 |
| student_teacher-02 | 同じ担当関係の重複 | `INSERT INTO student_teacher VALUES(10001,10001);` | 複合主キーで拒否（1062） |
| student_teacher-03 | 存在しない生徒 | `INSERT INTO student_teacher VALUES(99999,10001);` | 外部キーで拒否（1452） |
| student_teacher-04 | 存在しない講師 | `INSERT INTO student_teacher VALUES(10001,99999);` | 外部キーで拒否（1452） |
| student_teacher-05 | NULLの生徒ID | `INSERT INTO student_teacher VALUES(NULL,10001);` | NOT NULLで拒否（1048） |
| student_teacher-06 | 生徒1人に複数講師 | `INSERT INTO student_teacher VALUES(10001,10002);`<br>`SELECT COUNT(*) FROM student_teacher WHERE student_id=10001;` | 2名を割り当て可能 |
| student_teacher-07 | 講師1人に複数生徒 | `SELECT COUNT(*) FROM student_teacher WHERE teacher_id=10001;` | 2人を担当 |
| student_teacher-08 | 全生徒に担当が存在 | `SELECT COUNT(*) FROM students s WHERE NOT EXISTS(SELECT 1 FROM student_teacher st WHERE st.student_id=s.id);` | 未担当の生徒は0人 |
| student_teacher-09 | 担当関係だけの削除 | `DELETE FROM student_teacher WHERE student_id=10001 AND teacher_id=10001;`<br>`SELECT COUNT(*) FROM students WHERE id=10001;`<br>`SELECT COUNT(*) FROM teachers WHERE id=10001;` | 生徒と講師は残る |
| student_teacher-10 | 担当のある講師の削除 | `DELETE FROM teachers WHERE id=10001;` | RESTRICTで拒否（1451） |

### system_locks（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| system_locks-01 | 初期ロックの件数 | `SELECT COUNT(*) FROM system_locks;` | 1件 |
| system_locks-02 | 管理用ロックの取得 | `SELECT name FROM system_locks WHERE name='management' FOR UPDATE;` | managementを取得 |
| system_locks-03 | 同じロック名の登録 | `INSERT INTO system_locks VALUES('management');` | 主キーで拒否（1062） |
| system_locks-04 | NULLのロック名 | `INSERT INTO system_locks VALUES(NULL);` | NOT NULLで拒否（1048） |
| system_locks-05 | ロック名の51文字超過 | `INSERT INTO system_locks VALUES(REPEAT('x',51));` | 文字数超過で拒否（1406） |
| system_locks-06 | 非ASCIIのロック名 | `INSERT INTO system_locks VALUES('管理');` | 文字コードで拒否（1366） |
| system_locks-07 | 大文字小文字違いのロック名 | `INSERT INTO system_locks VALUES('MANAGEMENT');` | 同じ名前として拒否（1062） |
| system_locks-08 | 別名ロックの追加 | `INSERT INTO system_locks VALUES('test_aux');`<br>`SELECT COUNT(*) FROM system_locks;` | DBは別名を許可（試験後に戻す） |
| system_locks-09 | ロック行の削除 | `DELETE FROM system_locks WHERE name='management';`<br>`SELECT COUNT(*) FROM system_locks;` | DBは削除を許可（試験後に戻す） |
| system_locks-10 | ロック名の変更 | `UPDATE system_locks SET name='test_management' WHERE name='management';`<br>`SELECT name FROM system_locks;` | DBは変更を許可（試験後に戻す） |

### rate_limits（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| rate_limits-01 | 架空バケットの件数 | `SELECT COUNT(*) FROM rate_limits;` | 10件 |
| rate_limits-02 | バケットキーの重複 | `INSERT INTO rate_limits VALUES('936b565ef9b72e50f0316d52efd4e1531db8fea5ca0ed34db4b968b0b008196c',1,946684800);` | 主キーで拒否（1062） |
| rate_limits-03 | NULLのキー | `INSERT INTO rate_limits VALUES(NULL,1,946684800);` | NOT NULLで拒否（1048） |
| rate_limits-04 | 負の試行回数 | `UPDATE rate_limits SET hits=-1 WHERE bucket_key='936b565ef9b72e50f0316d52efd4e1531db8fea5ca0ed34db4b968b0b008196c';` | UNSIGNEDで拒否（1264） |
| rate_limits-05 | 試行回数の既定値 | `INSERT INTO rate_limits(bucket_key,window_started) VALUES(REPEAT('z',64),946684800);`<br>`SELECT hits FROM rate_limits WHERE bucket_key=REPEAT('z',64);` | 0で登録 |
| rate_limits-06 | キーの65文字超過 | `INSERT INTO rate_limits VALUES(REPEAT('x',65),1,946684800);` | 文字数超過で拒否（1406） |
| rate_limits-07 | 負のウィンドウ開始時刻 | `UPDATE rate_limits SET window_started=-1 WHERE bucket_key='936b565ef9b72e50f0316d52efd4e1531db8fea5ca0ed34db4b968b0b008196c';` | UNSIGNEDで拒否（1264） |
| rate_limits-08 | 同じキーの試行回数加算 | `INSERT INTO rate_limits VALUES('936b565ef9b72e50f0316d52efd4e1531db8fea5ca0ed34db4b968b0b008196c',1,946684800) ON DUPLICATE KEY UPDATE hits=hits+1;`<br>`SELECT hits FROM rate_limits WHERE bucket_key='936b565ef9b72e50f0316d52efd4e1531db8fea5ca0ed34db4b968b0b008196c';` | 1から2に増加 |
| rate_limits-09 | 期限を過ぎたバケットの掃除 | `DELETE FROM rate_limits WHERE window_started<946688400;`<br>`SELECT COUNT(*) FROM rate_limits;` | 架空10件を削除 |
| rate_limits-10 | キーの大文字小文字 | `INSERT INTO rate_limits VALUES(REPEAT('a',64),0,946684800),(REPEAT('A',64),0,946684800);`<br>`SELECT COUNT(*) FROM rate_limits;` | ascii_binで別キーとして保持 |

### password_changes（10ケース）

| ID | 確認内容 | SQL | 期待結果 |
|---|---|---|---|
| password_changes-01 | 期限切れの保留情報の件数 | `SELECT COUNT(*) FROM password_changes;` | 10件 |
| password_changes-02 | 同じ講師の保留を追加 | `INSERT INTO password_changes SELECT * FROM password_changes WHERE teacher_id=10001;` | 主キーで拒否（1062） |
| password_changes-03 | トークンの重複 | `UPDATE password_changes SET token='758d210110d29cba7bba4846ede520f8832dd8cb2b1dfef726be7338c9ffaf5a' WHERE teacher_id=10002;` | 一意制約で拒否（1062） |
| password_changes-04 | 存在しない講師 | `UPDATE password_changes SET teacher_id=99999 WHERE teacher_id=10001;` | 外部キーで拒否（1452） |
| password_changes-05 | 有効期限での抽出 | `SELECT COUNT(*) FROM password_changes WHERE expires_at<UNIX_TIMESTAMP();` | 10件とも期限切れ |
| password_changes-06 | 認証試行回数の加算 | `UPDATE password_changes SET attempts=attempts+1 WHERE teacher_id=10001;`<br>`SELECT attempts FROM password_changes WHERE teacher_id=10001;` | 0から1に増加 |
| password_changes-07 | 講師削除時の保留情報 | `DELETE FROM teachers WHERE id=10002;`<br>`SELECT COUNT(*) FROM password_changes WHERE teacher_id=10002;` | CASCADEで削除 |
| password_changes-08 | トークンの大文字小文字 | `UPDATE password_changes SET token=REPEAT('a',64) WHERE teacher_id=10001;`<br>`UPDATE password_changes SET token=REPEAT('A',64) WHERE teacher_id=10002;`<br>`SELECT COUNT(*) FROM password_changes WHERE token=REPEAT('a',64);` | 別の値として保持 |
| password_changes-09 | NULLの変更後パスワードハッシュ | `UPDATE password_changes SET pending_hash=NULL WHERE teacher_id=10001;` | NOT NULLで拒否（1048） |
| password_changes-10 | トークンの65文字超過 | `UPDATE password_changes SET token=REPEAT('x',65) WHERE teacher_id=10001;` | 文字数超過で拒否（1406） |

## DBとアプリの検証範囲

空の姓・未来の生年月日・パスワードポリシー・特記事項2,000文字・担当1名以上・権限による操作制限・最後のadmin保護・メール認証の期限と試行回数はアプリでも検証する。DB単独で拒否しない条件を、この70ケースが拒否すると誤解しないこと。students-10はその区別を確認するケース。system_locksの行の削除・別名追加・変更もDB単独では可能で、アプリはmanagementを必須として使う。

system_locks-02はFOR UPDATEの取得確認。別接続が待機する競合試験ではない。メールの実配送とMySQL 8.0本体での実行は今回の確認範囲に含めない。アプリの権限・認証の確認結果は [実装・検証結果](実装・検証結果.md) を参照。

## 今回の検証結果

2026年10月21日、PHP 8.2.12・MariaDB 10.4.32で、一時領域の独立したDBサーバーを使って実施。

- 70ケースすべて成功。同じDBで2回実行し、再実行も70/70成功。
- 生成したSQLを別の専用DBへ読み込み、可変5テーブル各10件、roles3件、system_locks1件を確認。
- 講師10人のハッシュをpassword_verifyで照合し、すべて一致。
- 実DB名の指定、接続設定不足、データ投入済みの専用DBの3条件で試験開始を拒否。
- 共通処理の既存テスト31件も成功。チームの実DBは作成・変更していない。

SQLと実行レポートはGit管理外。架空データの生成元・自動テスト・このケース一覧をGit管理する。

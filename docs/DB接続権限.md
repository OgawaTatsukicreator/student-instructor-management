# DB接続アカウント

2026年10月22日の設定確認では、アプリの接続ユーザーはroot、パスワードは空だった。実際の値はログやGitに記録していない。この状態は開発用のXAMPP既定値に依存しており、DB全体への権限が大きい。

DB本体の作成・管理はチームで行う方針のため、今回、実DBのアカウントや権限を自動変更していない。次の内容を管理者で実施し、アプリだけの接続先へ切り替える。

1. `beta_app`などの専用ユーザーを、実際のTCP接続元だけに限定して作る。例ではconfig.phpの既定接続先127.0.0.1を使う。
2. 長いランダムなパスワードを設定し、Gitや共有資料に書かない。
3. βteamに必要なテーブル権限だけを付ける。CREATE、DROP、ALTER、GRANT、他DBへの権限はアプリに与えない。
4. config.example.phpを参考に、各自のconfig.local.phpへ接続情報を設定する。ログイン・CRUD・パスワード変更を専用DBで確認してから利用する。

次のSQLのパスワード部分は必ず各自で置き換える。既に同名ユーザーがいる場合は内容を確認し、勝手に変更せず別名を選ぶ。SQLはローカルに保管し、Gitには含めない。

```sql
CREATE USER 'beta_app'@'127.0.0.1' IDENTIFIED BY '各自で生成した長いランダムなパスワードに置き換える';
GRANT SELECT ON `βteam`.`roles` TO 'beta_app'@'127.0.0.1';
GRANT SELECT, UPDATE ON `βteam`.`system_locks` TO 'beta_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `βteam`.`teachers` TO 'beta_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `βteam`.`students` TO 'beta_app'@'127.0.0.1';
GRANT SELECT, INSERT, DELETE ON `βteam`.`student_teacher` TO 'beta_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `βteam`.`rate_limits` TO 'beta_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON `βteam`.`password_changes` TO 'beta_app'@'127.0.0.1';
```

system_locksのUPDATE権限は、SELECT FOR UPDATEによる行ロックの権限条件も満たすため。アプリは管理用ロック行自体の変更・削除を行わない。

このSQLはDB本体・テーブル・実データを生成するSQLではない。接続先のDB名は引き続きβteamとする。DB名が異なる専用検証環境では、そのDB名へ読み替える。

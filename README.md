# βteam 学習塾の生徒・講師管理

XAMPPのPHP 8.2以降とMariaDB 10.4以降／MySQL 8.0向け。フレームワークとビルド作業は不要です。

## 準備

1. このディレクトリを `C:\xampp\htdocs\βteam` に配置します。
2. XAMPPでApacheとDBを起動します。phpMyAdminで `βteam` という空のDBをutf8mb4で作成し、選択します。DB本体の作成は自動実行しません。
3. `database.sql` をインポートします。元のAlphaTeam.sqlを読込済みのDBへ重ねて実行せず、必要なデータをバックアップしてから空のDBを用意してください。
4. `config.example.php` を `config.local.php` にコピーし、接続先・ポート・ユーザー・パスワードを設定します。この環境の既定ポートは3307です。他のPCでは実際の設定に合わせてください。
5. プロジェクトフォルダーでPowerShellを開き、`powershell -ExecutionPolicy Bypass -File .\setup_admin.ps1` を実行します。姓・名・メール・初期パスワードを入力してください。既に講師がいる場合は初期管理者を追加できません。
6. [ログイン画面](http://localhost/%CE%B2team/login.php)を開きます。Apacheのポートが80以外ならURLへ追加してください。初回はパスワードを変更します。

PowerShell以外でセットアップする場合は `C:\xampp\php\php.exe setup_admin.php` の標準入力へ、last_name・first_name・email・passwordを持つJSONを渡せます。パスワードをコマンド引数や履歴へ残さないでください。

## 機能とルール

権限はadmin、operator、general。認証・入力検証・権限制御はサーバー側で実行します。パスワードは半角10〜50文字、英大文字・英小文字・数字・記号から2種類以上を使います。空白は使用できません。

ログインの無操作期限は30分。パスワード変更後はログインしたまま元の画面へ戻り、他のログインは失効します。初回変更だけは生徒一覧へ移動します。

## ローカル検証

`C:\xampp\php\php.exe tests\unit.php` でパスワード条件や出力処理を確認できます。テストはDBを作成・削除しません。DB設計は [docs/DB設計.md](docs/DB設計.md) を参照してください。

## 公開範囲と提出

Apacheのmod_rewriteとAllowOverrideを有効にし、`.htaccess` を適用してください。docs、内部PHP、設定・SQL・Git情報はブラウザーから取得させません。実運用でアクセスする場合はHTTPSを使用します。

提出時は、完成したDBをエクスポートしたSQLをソースと同じディレクトリへ格納してZIPにしてください。`database.sql` は初期定義であり、最終エクスポートの代わりではありません。config.local.phpやSMTP認証情報、実際の生徒の個人情報をGitHubへpushしないでください。

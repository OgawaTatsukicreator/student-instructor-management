# βteam 学習塾の生徒・講師管理

XAMPPのPHP 8.2以降とMariaDB 10.4以降／MySQL 8.0向け。フレームワークとビルド作業は不要です。

## 準備

1. このディレクトリを `C:\xampp\htdocs\βteam` に配置します。
2. XAMPPでApacheとDBを起動します。phpMyAdminで `βteam` という空のDBをutf8mb4で作成し、選択します。DB本体の作成は自動実行しません。
3. `database.sql` をインポートします。このファイルはローカル保管のためGitには含まれません。新しくcloneしたPCでは、チーム内で別途受け取ってください。元のAlphaTeam.sqlを読込済みのDBへ重ねて実行せず、必要なデータをバックアップしてから空のDBを用意してください。
4. `config.example.php` を `config.local.php` にコピーし、接続先・ポート・ユーザー・パスワードを設定します。この環境の既定ポートは3307です。他のPCでは実際の設定に合わせてください。
5. プロジェクトフォルダーでPowerShellを開き、`powershell -ExecutionPolicy Bypass -File .\setup_admin.ps1` を実行します。姓・名・メール・初期パスワードを入力してください。既に講師がいる場合は初期管理者を追加できません。
6. [ログイン画面](http://localhost:8080/%CE%B2team/login.php)を開きます。この環境のApacheは8080番です。別のPCでは実際のポートに合わせてください。初回はパスワードを変更します。

PowerShell以外でセットアップする場合は `C:\xampp\php\php.exe setup_admin.php` の標準入力へ、last_name・first_name・email・passwordを持つJSONを渡せます。パスワードをコマンド引数や履歴へ残さないでください。

## 機能とルール

権限はadmin、operator、general。認証・入力検証・権限制御はサーバー側で実行します。パスワードは半角10〜50文字、英大文字・英小文字・数字・記号から2種類以上を使います。空白は使用できません。

ログインの無操作期限は30分。パスワード変更後はログインしたまま元の画面へ戻り、他のログインは失効します。初回変更だけは生徒一覧へ移動します。

## メール認証の設定

初期状態ではメール認証は無効で、現在のパスワードの照合により変更できます。SMTPの準備後、config.local.phpのmail_verificationをtrueにし、app_keyへ32文字以上のランダムな秘密鍵、smtpへ送信元・ホスト・ポート・認証情報を設定すると、メール認証が必須になります。

秘密鍵は `C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"` で生成できます。出力した値をconfig.local.phpに設定し、Gitには含めないでください。securityはSTARTTLSならtls、465番等の暗黙TLSならsslです。証明書検証を無効にしないでください。noneはローカルのメール試験サーバーに限ります。

有効時は6桁のコード、10分の期限、5回の誤入力上限、60秒の再送間隔、1時間に5回の送信試行上限（失敗を含む）を適用します。送信に失敗した場合はパスワードを変更しません。再送すると以前のコードは無効です。実際のメールを使う確認はチームのSMTP設定後に実施してください。

## ローカル検証

`C:\xampp\php\php.exe tests\unit.php` でパスワード条件や出力処理を確認できます。テストはDBを作成・削除しません。DB設計は [DB設計](DB設計.md) を参照してください。

SQLは7テーブル×10ケースを追加しました。実行手順と架空データ生成方法は [SQLテストケース](SQLテストケース.md) を参照してください。

## 公開範囲と提出

Apacheのmod_rewriteとAllowOverrideを有効にし、`.htaccess` を適用してください。docs、内部PHP、設定・SQL・Git情報はブラウザーから取得させません。実運用でアクセスする場合はHTTPSを使用します。

提出時は、完成したDBをエクスポートしたSQLをソースと同じディレクトリへ格納してZIPにしてください。`database.sql` は初期定義であり、最終エクスポートの代わりではありません。config.local.phpやSMTP認証情報、実際の生徒の個人情報をGitHubへpushしないでください。

実装済みの範囲と確認結果は [実装・検証結果](実装・検証結果.md) を参照してください。

## 資料とGit管理

Markdownはこのdocsフォルダーに集約しています。[仕様書](仕様書.md)、[UI/UX・DBの検討記録](UIUX・DB検討結果.md)、[同梱ライブラリ](THIRD_PARTY.md)、[Git管理方針](Git管理方針.md)を参照してください。コマンドはすべてdocsの親にあるプロジェクト直下で実行します。

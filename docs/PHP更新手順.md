# PHP本体の更新計画

2026年10月28日更新。共有XAMPPはPHP 8.2.12。利用者の指定により、アプリ修正と本手順の作成までとする。PHP本体の切り替え・Apache再起動は実施しない。以下は後日更新を行う際の手順である。ApacheのPHPモジュールは他のXAMPPプロジェクト・phpMyAdminとも共有されるため、実施時には全体への影響と作業範囲を確認する。

## 更新対象の配布物

[PHP公式の2026-10-26告知](https://www.php.net/archive/2026.php)では、Windows配布物が8.2.35-1へ再ビルドされた。Brotli 1.2.1を同梱し、CVE-2026-111497を修正する。自動HTTP展開を有効にしたcURLが到達経路となる。PHP本体の表示は8.2.35のままなので、表示版だけで再ビルド適用を判断しない。

- 対象：PHP 8.2.35-1 VS16 x64 Thread Safe
- [公式ZIP](https://downloads.php.net/~windows/releases/archives/php-8.2.35-1-Win32-vs16-x64.zip)
- SHA256：`811d13185e401ef6ec286b21a58dc004ab6d4e04494923d88add19551faa1117`
- 根拠：[公式配布ページ](https://www.php.net/downloads.php?os=windows&version=8.2)と[公式配布メタデータ](https://downloads.php.net/~windows/releases/releases.json)の値が一致。

今回、この新ZIP本体のダウンロードと動作確認は実施していない。切り替え前に取得・ハッシュ照合・全試験をやり直す。以前に検証した以下の旧ZIPを、そのまま最新のWindows修正適用済みとして使わない。

## 2026年10月22日に検証した旧配布物

[PHP公式の2026-10-21告知](https://www.php.net/archive/2026.php#2026-10-21-5)でCVE-2026-109618・CVE-2026-109619を確認。mod_phpも対象で、アプリ実行前に到達しうる。コード上の例外処理・入力制限はPHP本体の修正の代わりにはならない。

- [公式Windows配布ページ](https://www.php.net/downloads.php?os=windows&version=8.2)
- 検証対象：PHP 8.2.35 VS16 x64 Thread Safe
- [公式ZIP](https://downloads.php.net/~windows/releases/archives/php-8.2.35-Win32-vs16-x64.zip)
- SHA256：`cfa6000be835931961e40740bd3b51111700061d6655ddbfe5a2125807947457`

ダウンロードしたZIPのハッシュ一致を確認済み。別フォルダーでPHPの起動、PDO_mysql・mbstring・openssl等、セッション保存、単体・HTTP・メール・障害注入の試験を実施した。Apacheの共有モジュール切り替えを実施したという意味ではない。

## 切り替え

1. Apacheの短時間停止と他プロジェクトへの影響を確認する。MySQLとDB本体は変更しない。
2. `C:\xampp\apache\conf\extra\httpd-xampp.conf`、現在のPHP設定・必要なCA証明書等をhtdocs外へバックアップする。設定内容や秘密情報をGitへ含めない。
3. 新しい再ビルドZIPを取得・ハッシュ照合し、別環境で検証してから `C:\xampp\php-8.2.35-1` へ展開する。既存 `C:\xampp\php` は戻せるよう保持する。古い拡張DLLを新しいPHPへ混ぜない。
4. production版iniを基に、DB・mbstring・OpenSSL等の拡張、タイムゾーン、CAパス、書き込み可能なセッション・ログ保存先を設定する。extension_dirには新しいASCII絶対パスを使う。日本語を含む絶対extension_dirではDLL読込が失敗したため、検証用フォルダーでは相対extと起動時の作業フォルダー固定を使った。
5. `session.upload_progress.enabled=0` をPHP起動前のiniへ設定する。Webのdisplay_errorsはOff、expose_phpはOff。2026-10-23の公式追記にある特殊な構成の問題も踏まえ、upload_progressを無効にし、初期化後にsession.serialize_handlerを切り替えない。これら単独で古いPHPの問題を完全に解消したとは扱わない。
6. Apacheを通常のXAMPP操作で停止する。httpd-xampp.confのPHPに関する参照だけを、新しいフォルダーへ合わせる。php8ts.dll、必要な関連DLL、php8apache2_4.dll、PHPINIDir、PHPRC、php-cgiのScriptAlias・Directoryを確認する。既存設定にある廃止済みsafe_modeも除去対象とする。
7. `C:\xampp\apache\bin\httpd.exe -t` で構成を検査する。起動エラーを解消してからApacheを起動し、全workerを置き換える。
8. ローカル限定・一時的な確認で、Web経由のPHP版、必要な拡張、OpenSSL版を確かめる。phpinfo全体を公開しない。Apacheが自身のbinにある同名DLLを先に読む場合があるため、CLIの版確認だけで済ませない。確認用ファイルは除去する。
9. βteamの表示、専用DBでのログイン・ログアウト・不正入力、phpMyAdminと他プロジェクトの表示を確認する。実際のパスワードを変更して試験しない。

## 問題が起きた場合

Apacheを停止し、バックアップした設定を戻し、構成検査後に旧PHPで起動する。DB・ソース・認証情報を戻す作業は行わない。旧PHPへの復帰は脆弱性も戻すため、一時復旧として扱い、更新を再開する。

PHP 8.2の公式セキュリティサポートは2026年12月31日まで。8.2.35への修正適用後も、別途サポート中の系列への移行を計画する。

## 現在の状態

アプリ修正、更新手順の作成、旧ZIPの別環境試験まで完了。8.2.35-1は公式のURLとハッシュを確認した段階で、取得・動作確認・切替は未実施。共有XAMPPのPHPは8.2.12のまま。PHP本体の切り替え・Apache再起動は後日の作業として残す。ApacheとMariaDB自体の更新も[サーバー運用強化手順](サーバー運用強化手順.md)に従って別途行う。

# Git管理対象とローカル保管

## 管理しないもの

| 対象 | 理由・扱い |
|---|---|
| database.sql、すべての.sql／.SQL | DB定義、DBエクスポート、試験SQLをローカル保管する。共有時はチーム内で別途受け渡す |
| docs/参考/AlphaTeam.sql | 提供された元SQLも同じ方針で追跡を解除。手元のファイルは保持 |
| tests/test_data.sql | [生成プログラム](../tests/export_sql_fixtures.php)から再生成できる |
| config.local.php、.env、.env.* | 各自の接続先・パスワード・SMTP認証情報。値のない.env.exampleは除外しない |
| runtime/、output/ | 実行時データ、SQLテストのJSONレポートなど |
| .log、.tmp、.bak、.swp、末尾~ | ログ・一時ファイル・バックアップ |
| __pycache__/、.pyc等 | セキュリティ試験用Pythonのコンパイル済みキャッシュ |
| .zip、.7z、.tar、.gz | 提出・配布用アーカイブ。DBエクスポートを含む可能性があるためローカル保管 |
| .idea/、.vscode/、.DS_Store、Thumbs.db、Desktop.ini | PCごとのエディター設定・OS生成ファイル |

database.sqlと参考のAlphaTeam.sqlは以前から追跡されていたため、.gitignoreの追記に加え、`git rm --cached`で追跡を解除する。ファイル本体を削除する操作ではない。過去のコミットにあるSQLは残る。今回、Git履歴の書き換えは行わない。

新しくcloneしたPCにはdatabase.sqlは含まれない。初期定義をチーム内で受け取るか、[DB設計](DB設計.md)とローカル保管した定義を照合して準備する。SQLを変更した場合は、チーム内で共有するファイルとDB設計書も更新する。

## 管理を続けるもの

PHP・CSS・JavaScript、.htaccess、config.php、config.example.php、tests内のPHP、同梱PHPMailerとLICENSEは動作・再検証に必要なため保持する。config.phpは環境変数やconfig.local.phpを読む処理で、各自の秘密情報を直接記載する場所にはしない。

docs内の設計書、仕様書、過去の検証結果JSON、画面構成図、確認用画像は、設計・検証の記録として保持する。新しい実行レポートはoutputへ出力する。JSON・PNG・PHPを拡張子だけで一括除外しない。

## Markdownの配置

README.mdはプロジェクト直下に置き、概要・ログイン方法・使い方・運用・ライセンスの入口とする。2026年10月25日のREADME更新では、利用者が直下へ移動していた配置を引き継いだ。README以外の.mdはdocsへ集約する。[README](../README.md)を入口とし、各資料のリンクは配置先に合わせる。

コマンドの実行位置はdocsの親のプロジェクト直下。Web画面用のPHPは従来どおり直下に置く。

## Web公開と提出

Gitで除外してもWebからの取得を防げるわけではない。.htaccessでdocs・tests・output・runtime・third_party・.git、設定・SQL・環境ファイルなどの直接取得を拒否する。

提出用ZIPはGit管理ファイルの一覧からではなく、必要なソースとチームが最終エクスポートしたSQLを選んで作成する。.gitignoreはZIP作成時の除外指定ではない。config.local.php、SMTP認証情報、実行レポート、試験用DBの不要な情報を混入させない。

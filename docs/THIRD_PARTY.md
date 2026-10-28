# 同梱ライブラリ

PHPMailer 7.1.1のException.php、PHPMailer.php、SMTP.phpおよびライセンスを [third_party/PHPMailer](../third_party/PHPMailer/) に同梱しています。SMTPのパスワード認証、STARTTLS、暗黙TLSによる認証コード送信に使用します。Composerの実行は不要です。

- 公式ソース：[PHPMailer v7.1.1](https://github.com/PHPMailer/PHPMailer/tree/v7.1.1)
- ライセンス：LGPL-2.1-only（[同梱LICENSE](../third_party/PHPMailer/LICENSE)参照）
- このアプリではソースを変更していません。更新時には公式リリースと互換性を確認してください。

2026年10月28日、同梱する3つのPHPファイル、composer.json、LICENSEの計5ファイルを、公式v7.1.1タグのファイルと照合し、すべてバイト単位で一致することを確認しました。供給元、確認日、SHA-256は[依存関係チェックサム.json](依存関係チェックサム.json)に記録しています。

プロジェクト直下から、使用するPHPのパスを指定して検証できます。

```powershell
& 'C:\xampp\php\php.exe' tests/verify_dependencies.php
```

検証はネットワークやDBへ接続せず、一覧の形式、指定された5ファイルのSHA-256、PHPMailerのVERSIONを確認します。すべて一致すれば終了コード0、不一致・欠落・一覧の不備があれば終了コード1になります。

ライブラリ更新時は、まず公式リリースとセキュリティ告知を確認し、公式ファイルとの照合と互換性試験を行ってから、確認日・版・チェックサム・検証コードの期待版を更新してください。手元のファイルだけを基に一覧を自動で再生成しないでください。ハッシュ一致は記録した公式ファイルとの同一性の確認であり、以後の脆弱性が存在しないことの保証ではありません。

公式ファイルの改行はLFのまま保持し、`.gitattributes`で同梱フォルダーの自動改行変換を無効にしています。既存WindowsチェックアウトではCRLFへの変換だけでもチェックサムが不一致になります。原因を確認せず期待ハッシュを変更せず、公式LF版との内容一致を確認してからファイルを揃えてください。

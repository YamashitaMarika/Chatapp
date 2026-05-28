# Small Community Chat for Sakura Rental Server

さくらのレンタルサーバーで動かせる、5人くらいの小さなコミュニティー向けチャットです。
Node.jsは使いません。PHPで動きます。
PHP 8系をおすすめしますが、古いPHPでも構文エラーになりにくい書き方にしています。

## アップロードするファイル

以下を、さくらのレンタルサーバーの公開フォルダ、たとえば `www/chat` にアップロードします。

```text
index.php
api.php
lib.php
app.js
styles.css
.htaccess
config.example.php
data/
```

`.htaccess` は先頭がドットなので、FTPソフトでは「隠しファイルを表示する」設定が必要な場合があります。

`data/` の中には次のファイルがあります。

```text
data/allowed-emails.json
data/state.json
data/uploads/
data/uploads/.htaccess
data/.htaccess
```

`data/.htaccess` も必ずアップロードしてください。チャット履歴ファイルをブラウザから直接見られないようにするためです。

## 最初にやること

`config.example.php` をコピーして `config.php` を作ります。

FTPソフトやファイルマネージャーを使う場合は、`config.example.php` を複製して、名前を `config.php` に変更してください。

SSHを使える場合は、アプリのフォルダで次を実行します。

```bash
cp config.example.php config.php
```

そのあと、`config.php` の中身を自分の環境に合わせて編集します。

```php
'app_url' => 'https://あなたのドメイン/chat',
'session_secret' => '32文字以上のランダムな文字列',
'mail_from' => 'no-reply@あなたのドメイン',
'mail_from_name' => 'Community Chat',
```

## 参加者のメールアドレス

`data/allowed-emails.json` に、参加を許可するメールアドレスを入れます。

```json
[
  "member1@example.com",
  "member2@example.com",
  "member3@example.com",
  "member4@example.com",
  "member5@example.com"
]
```

## 開くURL

たとえば `www/chat` にアップロードした場合は、次のURLで開きます。

```text
https://あなたのドメイン/chat/
```

メールアドレスを入力すると、6桁の確認コードがメールで届きます。
そのコードを入力すると、1つのチャットルームに入れます。

## 注意

- `config.php` には秘密情報が入るので、人に送らないでください。
- `data/state.json` にチャット履歴が保存されます。
- 添付ファイルは `data/uploads/` に保存されます。
- 添付は画像ファイルとPDFファイルを選択できます（対応ブラウザでは選択画面で絞り込み表示されます）。
- `data/state.json` は定期的にバックアップしてください。
- 大人数や長期の本格運用では、ファイル保存ではなくDB保存に移すのがおすすめです。

より具体的な手順は `SAKURA_DEPLOYMENT.md` にまとめています。

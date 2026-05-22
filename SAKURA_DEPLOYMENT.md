# さくらのレンタルサーバー実運用手順

## 1. アップロード先を決める

さくらのレンタルサーバーでは、公開するファイルは通常 `www` フォルダに置きます。
PHPはできれば8系を選んでください。古いPHPでも動くようにしていますが、セキュリティ面では新しいPHPが安心です。

例:

```text
www/chat/
```

この場合、URLは次のようになります。

```text
https://あなたのドメイン/chat/
```

## 2. ファイルをアップロードする

このフォルダ内の次のファイルとフォルダを、`www/chat/` にアップロードします。

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

`server.js` や `package.json` はもう使いません。
`.htaccess` と `data/.htaccess` は隠しファイル扱いになることがあります。
FTPソフトで見えない場合は、「隠しファイルを表示する」設定をオンにしてください。
添付ファイル用の `data/uploads/.htaccess` も同じくアップロードしてください。

## 3. config.php を作る

`config.example.php` をコピーして、名前を `config.php` にします。

FTPソフトなら:

1. `config.example.php` を複製
2. 複製したファイル名を `config.php` に変更
3. `config.php` を編集

SSHなら:

```bash
cd ~/www/chat
cp config.example.php config.php
```

## 4. config.php を編集する

最低限、次の4つを変えます。

```php
'app_url' => 'https://あなたのドメイン/chat',
'session_secret' => '32文字以上のランダムな文字列',
'mail_from' => 'no-reply@あなたのドメイン',
'mail_from_name' => 'Community Chat',
```

`session_secret` はログイン状態や確認コードを守るための秘密文字列です。
英数字を混ぜた長い文字列にしてください。

例:

```php
'session_secret' => 'x8UQ9qK6uJw4nE2vRb7mL0pC3zY5tA1s',
```

## 5. 参加者メールを設定する

`data/allowed-emails.json` を開いて、参加者のメールアドレスを入れます。

```json
[
  "taro@example.com",
  "hanako@example.com",
  "sato@example.com",
  "suzuki@example.com",
  "yamada@example.com"
]
```

ここにないメールアドレスでは入室できません。

## 6. SSLを有効にする

さくらのコントロールパネルで、対象ドメインのSSLを有効にしてください。

公開URLは `http://` ではなく、必ず `https://` を使います。

## 7. ブラウザで開く

```text
https://あなたのドメイン/chat/
```

最初の確認:

1. メールアドレスを入力
2. 確認コードを受け取る
3. コードを入力
4. チャット画面に入れる
5. メッセージを送れる

## 8. メールが届かない場合

まず `config.php` の `mail_from` を確認してください。
さくらのサーバーで使う場合、できれば自分のドメインのメールアドレスにしてください。

例:

```php
'mail_from' => 'no-reply@あなたのドメイン',
```

迷惑メールフォルダも確認してください。

## 9. バックアップ

最低限、次の2つを定期的に保存してください。

```text
data/state.json
data/allowed-emails.json
data/uploads/
```

`state.json` にユーザー情報とチャット履歴が入ります。
添付ファイルは `data/uploads/` に入ります。

## 10. トラブル時の見方

画面で `config.php を作ってください` と出る場合:

`config.example.php` をコピーして `config.php` を作ります。

`session_secret` のエラーが出る場合:

`config.php` の `session_secret` を32文字以上のランダム文字列にします。

招待メールのエラーが出る場合:

`data/allowed-emails.json` に参加者のメールアドレスを入れます。

メール送信に失敗する場合:

`mail_from` を自分のドメインのメールアドレスに変え、さくら側のメール設定を確認します。

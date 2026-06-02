<?php

require __DIR__ . '/lib.php';

ensure_files();
send_security_headers();
header('Content-Type: text/html; charset=UTF-8');

$config = config();
$appName = get_value($config, 'app_name', 'ISHII DESIGN Original Chat');
?>
<!doctype html>
<html lang="ja">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?php echo h($appName); ?></title>
    <link rel="stylesheet" href="styles.css" />
  </head>
  <body>
    <main class="shell">
      <section id="authView" class="auth-view" aria-labelledby="authTitle">
        <form id="emailForm" class="auth-panel">
          <p class="eyebrow">Simple community</p>
          <h1 id="authTitle"><?php echo h($appName); ?></h1>
          <label>
            <span>メールアドレス</span>
            <input id="emailInput" name="email" type="email" autocomplete="email" required />
          </label>
          <button type="submit">コードを受け取る</button>
        </form>

        <form id="codeForm" class="auth-panel hidden">
          <p class="eyebrow">Email check</p>
          <h1>確認コード</h1>
          <label>
            <span>表示名</span>
            <input id="nameInput" name="displayName" type="text" autocomplete="name" maxlength="32" />
          </label>
          <label>
            <span>6桁コード</span>
            <input id="codeInput" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required />
          </label>
          <button type="submit">入室する</button>
          <button id="backButton" class="ghost" type="button">戻る</button>
        </form>

        <p id="authStatus" class="status" role="status" aria-live="polite"></p>
      </section>

      <section id="chatView" class="chat-view hidden" aria-labelledby="chatTitle">
        <header class="chat-header">
          <div>
            <p class="eyebrow">Text &amp; files</p>
            <h1 id="chatTitle"><?php echo h($appName); ?></h1>
          </div>
          <div class="account">
            <div class="presence" aria-live="polite">
              <span class="presence-label">入室中</span>
              <span id="presenceList">-</span>
            </div>
            <span id="accountLabel"></span>
            <button id="logoutButton" class="ghost" type="button">退出</button>
          </div>
        </header>

        <ol id="messageList" class="message-list" aria-live="polite"></ol>

        <form id="messageForm" class="composer">
          <label class="visually-hidden" for="messageInput">メッセージ</label>
          <textarea id="messageInput" maxlength="1000" rows="1" placeholder="メッセージを書く"></textarea>
          <label class="file-button">
            添付
            <input id="fileInput" class="file-input-overlay" type="file" accept="image/*,.pdf" />
          </label>
          <button id="sendButton" type="submit">送信</button>
          <div id="filePreview" class="file-preview hidden">
            <span id="fileName"></span>
            <button id="clearFileButton" class="ghost file-clear" type="button">削除</button>
          </div>
        </form>
      </section>
    </main>

    <script src="app.js" defer></script>
  </body>
</html>

<?php

define('DATA_DIR', __DIR__ . '/data');
define('STATE_PATH', DATA_DIR . '/state.json');
define('ALLOWED_EMAILS_PATH', DATA_DIR . '/allowed-emails.json');
define('UPLOAD_DIR', DATA_DIR . '/uploads');
define('MAX_MESSAGE_LENGTH', 1000);
define('MAX_MESSAGES', 500);
define('DEFAULT_MAX_UPLOAD_BYTES', 10485760);
define('AUTH_CODE_TTL_SECONDS', 600);
define('SESSION_TTL_SECONDS', 2592000);
define('AUTH_ATTEMPT_LIMIT', 5);

function config()
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $defaults = array(
        'app_name' => 'Community Chat',
        'app_url' => '',
        'require_invites' => true,
        'debug_codes' => false,
        'session_secret' => '',
        'mail_from' => '',
        'mail_from_name' => 'Community Chat',
        'mail_return_path' => '',
        'cookie_secure' => is_https(),
        'max_upload_bytes' => DEFAULT_MAX_UPLOAD_BYTES,
        'presence_timeout_seconds' => 120,
        'allowed_upload_extensions' => array(
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf', 'txt', 'csv',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
            'zip'
        ),
    );

    $file = __DIR__ . '/config.php';
    $userConfig = file_exists($file) ? require $file : array();
    $config = array_merge($defaults, is_array($userConfig) ? $userConfig : array());
    return $config;
}

function ensure_files()
{
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0700, true);
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0700, true);
    }

    if (!file_exists(STATE_PATH)) {
        write_json_file(STATE_PATH, default_state());
    }

    if (!file_exists(ALLOWED_EMAILS_PATH)) {
        write_json_file(ALLOWED_EMAILS_PATH, array());
    }

    @chmod(DATA_DIR, 0700);
    @chmod(UPLOAD_DIR, 0700);
    @chmod(STATE_PATH, 0600);
    @chmod(ALLOWED_EMAILS_PATH, 0600);
}

function default_state()
{
    return array(
        'users' => array(),
        'authCodes' => array(),
        'messages' => array(),
        'lastSequence' => 0,
        'rateLimits' => array(),
        'presence' => array(),
    );
}

function read_state()
{
    ensure_files();
    $handle = fopen(STATE_PATH, 'c+');
    if (!$handle) {
        throw new Exception('データファイルを開けません。');
    }

    flock($handle, LOCK_SH);
    rewind($handle);
    $raw = stream_get_contents($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $state = trim((string) $raw) === '' ? array() : json_decode((string) $raw, true);
    return normalize_state(is_array($state) ? $state : array());
}

function update_state($callback)
{
    ensure_files();
    $handle = fopen(STATE_PATH, 'c+');
    if (!$handle) {
        throw new Exception('データファイルを開けません。');
    }

    flock($handle, LOCK_EX);
    rewind($handle);
    $raw = stream_get_contents($handle);
    $state = trim((string) $raw) === '' ? array() : json_decode((string) $raw, true);
    $state = normalize_state(is_array($state) ? $state : array());
    prune_state($state);

    $result = call_user_func_array($callback, array(&$state));

    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode_compat($state) . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $result;
}

function normalize_state($state)
{
    return array_merge(default_state(), $state);
}

function prune_state(&$state)
{
    $now = time();

    foreach ($state['authCodes'] as $email => $authCode) {
        if ((int) get_value($authCode, 'expiresAt', 0) < $now) {
            unset($state['authCodes'][$email]);
        }
    }

    foreach ($state['rateLimits'] as $key => $entry) {
        if ((int) get_value($entry, 'resetAt', 0) < $now) {
            unset($state['rateLimits'][$key]);
        }
    }

    $timeout = (int) config_value('presence_timeout_seconds', 120);
    foreach ($state['presence'] as $userId => $lastSeenAt) {
        if ((int) $lastSeenAt < $now - $timeout) {
            unset($state['presence'][$userId]);
        }
    }
}

function write_json_file($path, $value)
{
    $tmpPath = $path . '.' . getmypid() . '.' . time() . '.tmp';
    file_put_contents($tmpPath, json_encode_compat($value) . "\n", LOCK_EX);
    @chmod($tmpPath, 0600);
    rename($tmpPath, $path);
}

function json_encode_compat($value)
{
    if (defined('JSON_UNESCAPED_UNICODE') && defined('JSON_PRETTY_PRINT')) {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    return json_encode($value);
}

function bootstrap_session()
{
    $config = config();
    session_name('community_chat_session');
    session_set_cookie_params(
        SESSION_TTL_SECONDS,
        '/',
        '',
        (bool) $config['cookie_secure'],
        true
    );
    session_start();
}

function require_ready_config()
{
    $config = config();
    $errors = array();

    if (!file_exists(__DIR__ . '/config.php')) {
        $errors[] = 'config.example.php をコピーして config.php を作ってください。';
    }

    if (strlen((string) $config['session_secret']) < 32 || strpos((string) $config['session_secret'], 'replace-with') !== false) {
        $errors[] = 'config.php の session_secret を32文字以上のランダム文字列にしてください。';
    }

    if (trim((string) $config['app_url']) === '' || strpos((string) $config['app_url'], 'example.') !== false) {
        $errors[] = 'config.php の app_url を実際の公開URLにしてください。';
    }

    if (!filter_var((string) $config['mail_from'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'config.php の mail_from に送信元メールアドレスを設定してください。';
    }

    if (strpos((string) $config['mail_from'], 'example.') !== false) {
        $errors[] = 'config.php の mail_from を実際に使う送信元メールアドレスにしてください。';
    }

    if ((string) $config['mail_return_path'] !== '' && !filter_var((string) $config['mail_return_path'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'config.php の mail_return_path は空欄か、正しいメールアドレスにしてください。';
    }

    if ((bool) $config['require_invites'] && count(read_allowed_emails()) === 0) {
        $errors[] = 'data/allowed-emails.json に参加者のメールアドレスを入れてください。';
    }

    if ($errors) {
        send_json(500, array(
            'error' => 'CONFIG_NOT_READY',
            'message' => implode("\n", $errors),
        ));
    }
}

function read_allowed_emails()
{
    ensure_files();
    $raw = file_get_contents(ALLOWED_EMAILS_PATH);
    $emails = json_decode((string) $raw, true);
    if (!is_array($emails)) {
        return array();
    }
    return array_values(array_filter(array_map('normalize_email', $emails)));
}

function is_email_allowed($email)
{
    $allowed = read_allowed_emails();
    $config = config();
    if (!(bool) $config['require_invites'] && count($allowed) === 0) {
        return true;
    }
    return in_array($email, $allowed, true);
}

function normalize_email($value)
{
    $email = strtolower(trim((string) $value));
    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function clean_display_name($value, $email)
{
    $name = preg_replace('/\s+/u', ' ', trim((string) $value));
    if ($name === null) {
        $name = '';
    }
    if ($name !== '') {
        return text_substr($name, 0, 32);
    }
    $fallback = strtok($email, '@');
    return text_substr($fallback ? $fallback : 'member', 0, 32);
}

function current_user()
{
    $state = read_state();

    $userId = get_value($_SESSION, 'user_id', '');
    if ($userId !== '') {
        foreach ($state['users'] as $user) {
            if (get_value($user, 'id', '') === $userId) {
                return $user;
            }
        }
    }

    $user = user_from_remember_cookie($state);
    if ($user) {
        $_SESSION['user_id'] = get_value($user, 'id', '');
        return $user;
    }

    return null;
}

function issue_remember_cookie($userId)
{
    if ($userId === '') {
        return;
    }

    $expiresAt = time() + SESSION_TTL_SECONDS;
    $payload = $userId . '|' . $expiresAt;
    $signature = hash_hmac('sha256', $payload, (string) config_value('session_secret', ''));

    setcookie(
        'community_chat_remember',
        $payload . '|' . $signature,
        $expiresAt,
        '/',
        '',
        (bool) config_value('cookie_secure', is_https()),
        true
    );
}

function clear_remember_cookie()
{
    setcookie(
        'community_chat_remember',
        '',
        time() - 42000,
        '/',
        '',
        (bool) config_value('cookie_secure', is_https()),
        true
    );
}

function user_from_remember_cookie($state)
{
    $cookie = trim((string) get_value($_COOKIE, 'community_chat_remember', ''));
    if ($cookie === '') {
        return null;
    }

    $parts = explode('|', $cookie);
    if (count($parts) !== 3) {
        clear_remember_cookie();
        return null;
    }

    $userId = (string) $parts[0];
    $expiresAt = (int) $parts[1];
    $signature = (string) $parts[2];

    if ($userId === '' || $expiresAt < time()) {
        clear_remember_cookie();
        return null;
    }

    $payload = $userId . '|' . $expiresAt;
    $expected = hash_hmac('sha256', $payload, (string) config_value('session_secret', ''));
    if (!safe_hash_equals($expected, $signature)) {
        clear_remember_cookie();
        return null;
    }

    foreach ($state['users'] as $user) {
        if (get_value($user, 'id', '') === $userId) {
            issue_remember_cookie($userId);
            return $user;
        }
    }

    clear_remember_cookie();
    return null;
}

function public_user($user)
{
    return array(
        'id' => get_value($user, 'id', ''),
        'displayName' => get_value($user, 'displayName', 'member'),
    );
}

function touch_presence($user)
{
    if (!$user) {
        return array();
    }

    $userId = get_value($user, 'id', '');
    if ($userId === '') {
        return array();
    }

    return update_state(function (&$state) use ($userId) {
        $state['presence'][$userId] = time();
        return public_presence_members($state);
    });
}

function clear_presence($userId)
{
    if ($userId === '') {
        return;
    }

    update_state(function (&$state) use ($userId) {
        unset($state['presence'][$userId]);
        return null;
    });
}

function public_presence_members($state)
{
    $timeout = (int) config_value('presence_timeout_seconds', 120);
    $now = time();
    $members = array();

    foreach ($state['users'] as $user) {
        $userId = get_value($user, 'id', '');
        $lastSeenAt = (int) get_value($state['presence'], $userId, 0);
        if ($userId !== '' && $lastSeenAt >= $now - $timeout) {
            $members[] = array(
                'id' => $userId,
                'displayName' => get_value($user, 'displayName', 'member'),
                'lastSeenAt' => $lastSeenAt,
            );
        }
    }

    usort($members, function ($a, $b) {
        if ($a['lastSeenAt'] === $b['lastSeenAt']) {
            return 0;
        }
        return $a['lastSeenAt'] > $b['lastSeenAt'] ? -1 : 1;
    });

    return $members;
}

function public_message($message)
{
    $public = array(
        'id' => get_value($message, 'id', ''),
        'sequence' => (int) get_value($message, 'sequence', 0),
        'userId' => get_value($message, 'userId', ''),
        'displayName' => get_value($message, 'displayName', 'member'),
        'text' => get_value($message, 'text', ''),
        'createdAt' => get_value($message, 'createdAt', gmdate('c')),
    );

    $attachment = get_value($message, 'attachment', null);
    if (is_array($attachment)) {
        $public['attachment'] = public_attachment($attachment);
    }

    return $public;
}

function public_attachment($attachment)
{
    $id = get_value($attachment, 'id', '');
    return array(
        'id' => $id,
        'name' => get_value($attachment, 'name', 'attachment'),
        'size' => (int) get_value($attachment, 'size', 0),
        'mime' => get_value($attachment, 'mime', 'application/octet-stream'),
        'url' => 'api.php?action=file&id=' . rawurlencode($id),
    );
}

function save_uploaded_attachment($file)
{
    if (!is_array($file) || get_value($file, 'error', UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if ((int) get_value($file, 'error', UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('ファイルのアップロードに失敗しました。');
    }

    $size = (int) get_value($file, 'size', 0);
    $maxBytes = (int) config_value('max_upload_bytes', DEFAULT_MAX_UPLOAD_BYTES);
    if ($size <= 0) {
        throw new Exception('空のファイルは添付できません。');
    }
    if ($size > $maxBytes) {
        throw new Exception('添付ファイルは' . format_bytes($maxBytes) . '以内にしてください。');
    }

    $originalName = sanitize_file_name(get_value($file, 'name', 'attachment'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = config_value('allowed_upload_extensions', array());
    if ($extension === '' || !in_array($extension, $allowed, true)) {
        throw new Exception('この種類のファイルは添付できません。');
    }

    $id = uuid();
    $storedName = $id . '.' . $extension;
    $destination = UPLOAD_DIR . '/' . $storedName;
    $tmpName = get_value($file, 'tmp_name', '');

    if (!is_uploaded_file($tmpName) || !move_uploaded_file($tmpName, $destination)) {
        throw new Exception('ファイルを保存できませんでした。');
    }
    @chmod($destination, 0600);

    return array(
        'id' => $id,
        'name' => $originalName,
        'storedName' => $storedName,
        'size' => $size,
        'mime' => detect_mime_type($destination),
        'createdAt' => gmdate('c'),
    );
}

function find_attachment($id)
{
    $state = read_state();
    foreach ($state['messages'] as $message) {
        $attachment = get_value($message, 'attachment', null);
        if (is_array($attachment) && get_value($attachment, 'id', '') === $id) {
            return $attachment;
        }
    }
    return null;
}

function send_attachment_file($attachment)
{
    $storedName = basename(get_value($attachment, 'storedName', ''));
    if ($storedName === '') {
        send_json(404, array('error' => 'NOT_FOUND', 'message' => 'ファイルが見つかりません。'));
    }

    $path = UPLOAD_DIR . '/' . $storedName;
    if (!is_file($path)) {
        send_json(404, array('error' => 'NOT_FOUND', 'message' => 'ファイルが見つかりません。'));
    }

    set_status_code(200);
    send_security_headers();
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: attachment; filename="' . ascii_fallback_file_name(get_value($attachment, 'name', 'attachment')) . '"; filename*=UTF-8\'\'' . rawurlencode(get_value($attachment, 'name', 'attachment')));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

function sanitize_file_name($name)
{
    $name = str_replace(array("\0", '/', '\\'), '', (string) $name);
    $name = trim($name);
    if ($name === '') {
        return 'attachment';
    }
    return text_substr($name, 0, 120);
}

function detect_mime_type($path)
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $path);
            finfo_close($finfo);
            if ($mime) {
                return $mime;
            }
        }
    }
    return 'application/octet-stream';
}

function ascii_fallback_file_name($name)
{
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $name);
    if (!$fallback) {
        return 'attachment';
    }
    return $fallback;
}

function format_bytes($bytes)
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . 'MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . 'KB';
    }
    return $bytes . 'B';
}

function send_login_code($email, $code)
{
    $config = config();
    $appName = (string) $config['app_name'];
    $appUrl = app_url();
    $subject = $appName . ' 確認コード';
    $body = implode("\n", array(
        $appName . 'の確認コードです。',
        '',
        $code,
        '',
        'このコードは10分で無効になります。',
        'ログイン: ' . $appUrl,
    ));

    $fromName = encode_mime_header((string) $config['mail_from_name']);
    $headers = implode("\r\n", array(
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: ' . $fromName . ' <' . $config['mail_from'] . '>',
    ));
    $returnPath = trim((string) $config['mail_return_path']);
    $params = $returnPath !== '' ? '-f' . $returnPath : '';

    if (function_exists('mb_language')) {
        mb_language('Japanese');
    }
    if (function_exists('mb_internal_encoding')) {
        mb_internal_encoding('UTF-8');
    }

    $ok = false;

    if (function_exists('mb_send_mail')) {
        $ok = $params !== ''
            ? mb_send_mail($email, $subject, $body, $headers, $params)
            : mb_send_mail($email, $subject, $body, $headers);
    }

    // 環境依存で mb_send_mail が失敗するケースがあるため、mail() にフォールバックする。
    if (!$ok) {
        $encodedSubject = encode_mime_header($subject);
        $ok = $params !== ''
            ? mail($email, $encodedSubject, $body, $headers, $params)
            : mail($email, $encodedSubject, $body, $headers);
    }

    if (!$ok) {
        throw new Exception('確認コードのメール送信に失敗しました。');
    }
}

function encode_mime_header($value)
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($value, 'UTF-8');
    }
    return '=?UTF-8?B?' . base64_encode($value) . '?=';
}

function hash_auth_code($email, $code)
{
    return hash_hmac('sha256', $email . ':' . $code, (string) config_value('session_secret', ''));
}

function uuid()
{
    $data = secure_random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function secure_random_int($min, $max)
{
    if (function_exists('random_int')) {
        return random_int($min, $max);
    }

    $range = $max - $min;
    $bytes = secure_random_bytes(4);
    $value = unpack('N', $bytes);
    return $min + ($value[1] % ($range + 1));
}

function secure_random_bytes($length)
{
    if (function_exists('random_bytes')) {
        return random_bytes($length);
    }
    if (function_exists('openssl_random_pseudo_bytes')) {
        $strong = false;
        $bytes = openssl_random_pseudo_bytes($length, $strong);
        if ($bytes !== false && strlen($bytes) === $length) {
            return $bytes;
        }
    }
    throw new Exception('安全な乱数を生成できません。PHPのバージョンを上げてください。');
}

function safe_hash_equals($known, $user)
{
    if (function_exists('hash_equals')) {
        return hash_equals($known, $user);
    }
    if (strlen($known) !== strlen($user)) {
        return false;
    }
    $result = 0;
    for ($i = 0; $i < strlen($known); $i++) {
        $result |= ord($known[$i]) ^ ord($user[$i]);
    }
    return $result === 0;
}

function check_rate_limit($key, $limit, $windowSeconds)
{
    $now = time();
    $allowed = update_state(function (&$state) use ($key, $limit, $windowSeconds, $now) {
        $entry = get_value($state['rateLimits'], $key, null);
        if (!$entry || (int) get_value($entry, 'resetAt', 0) <= $now) {
            $state['rateLimits'][$key] = array(
                'count' => 1,
                'resetAt' => $now + $windowSeconds,
            );
            return true;
        }

        $entry['count'] = (int) get_value($entry, 'count', 0) + 1;
        $state['rateLimits'][$key] = $entry;
        return $entry['count'] <= $limit;
    });

    if (!$allowed) {
        send_json(429, array(
            'error' => 'RATE_LIMITED',
            'message' => '少し時間をおいてからもう一度お試しください。',
        ));
    }

    return true;
}

function read_json_body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return array();
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        send_json(400, array(
            'error' => 'BAD_JSON',
            'message' => '送信内容の形式が正しくありません。',
        ));
    }
    return $json;
}

function require_method($method)
{
    if (get_value($_SERVER, 'REQUEST_METHOD', 'GET') !== $method) {
        send_json(405, array(
            'error' => 'METHOD_NOT_ALLOWED',
            'message' => '許可されていない操作です。',
        ));
    }
}

function require_same_origin_for_post()
{
    if (get_value($_SERVER, 'REQUEST_METHOD', 'GET') !== 'POST') {
        return;
    }

    $origin = get_value($_SERVER, 'HTTP_ORIGIN', '');
    if ($origin === '') {
        return;
    }

    $given = parse_url($origin);
    if (!is_array($given)) {
        send_json(403, array(
            'error' => 'BAD_ORIGIN',
            'message' => '許可されていない送信元です。',
        ));
    }

    foreach (array(app_url(), current_origin()) as $allowedOrigin) {
        $allowed = parse_url($allowedOrigin);
        if (
            is_array($allowed) &&
            get_value($allowed, 'scheme', '') === get_value($given, 'scheme', '') &&
            get_value($allowed, 'host', '') === get_value($given, 'host', '')
        ) {
            return;
        }
    }

    send_json(403, array(
        'error' => 'BAD_ORIGIN',
        'message' => '許可されていない送信元です。',
    ));
}

function client_ip()
{
    return get_value($_SERVER, 'REMOTE_ADDR', 'unknown');
}

function app_url()
{
    $configured = trim((string) config_value('app_url', ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    $scheme = is_https() ? 'https' : 'http';
    $host = get_value($_SERVER, 'HTTP_HOST', 'localhost');
    $dir = str_replace('\\', '/', dirname(get_value($_SERVER, 'SCRIPT_NAME', '/')));
    $dir = $dir === '/' ? '' : rtrim($dir, '/');
    return $scheme . '://' . $host . $dir;
}

function current_origin()
{
    $scheme = is_https() ? 'https' : 'http';
    $host = get_value($_SERVER, 'HTTP_HOST', 'localhost');
    return $scheme . '://' . $host;
}

function is_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (get_value($_SERVER, 'HTTP_X_FORWARDED_PROTO', '') === 'https');
}

function send_security_headers()
{
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Referrer-Policy: same-origin');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), geolocation=(), microphone=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function send_json($status, $payload)
{
    set_status_code($status);
    send_security_headers();
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode_compat($payload);
    exit;
}

function set_status_code($status)
{
    if (function_exists('http_response_code')) {
        http_response_code($status);
        return;
    }

    $messages = array(
        200 => 'OK',
        201 => 'Created',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
    );
    $message = get_value($messages, $status, 'OK');
    header('HTTP/1.1 ' . $status . ' ' . $message, true, $status);
}

function h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function text_substr($value, $start, $length)
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, $start, $length, 'UTF-8');
    }
    return substr($value, $start, $length);
}

function text_length($value)
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    return strlen($value);
}

function get_value($array, $key, $default)
{
    return is_array($array) && array_key_exists($key, $array) ? $array[$key] : $default;
}

function config_value($key, $default)
{
    $config = config();
    return get_value($config, $key, $default);
}

<?php

require __DIR__ . '/lib.php';

ensure_files();
bootstrap_session();
require_same_origin_for_post();

$action = get_value($_GET, 'action', '');

try {
    if ($action === 'health') {
        send_json(200, array('ok' => true));
    }

    if ($action === 'file') {
        require_method('GET');
        $user = current_user();
        if (!$user) {
            send_json(401, array('error' => 'UNAUTHORIZED'));
        }
        touch_presence($user);

        $attachment = find_attachment(get_value($_GET, 'id', ''));
        if (!$attachment) {
            send_json(404, array(
                'error' => 'NOT_FOUND',
                'message' => 'ファイルが見つかりません。',
            ));
        }
        send_attachment_file($attachment);
    }

    if ($action === 'me') {
        require_method('GET');
        $user = current_user();
        if (!$user) {
            send_json(401, array('error' => 'UNAUTHORIZED'));
        }
        $members = touch_presence($user);
        send_json(200, array(
            'user' => public_user($user),
            'members' => $members,
        ));
    }

    if ($action === 'auth_start') {
        require_method('POST');
        require_ready_config();

        $body = read_json_body();
        $email = normalize_email(get_value($body, 'email', ''));
        if ($email === '') {
            send_json(400, array(
                'error' => 'INVALID_EMAIL',
                'message' => 'メールアドレスを確認してください。',
            ));
        }

        check_rate_limit('auth-start-ip:' . client_ip(), 10, 900);
        check_rate_limit('auth-start-email:' . $email, 3, 900);

        if (!is_email_allowed($email)) {
            send_json(403, array(
                'error' => 'EMAIL_NOT_ALLOWED',
                'message' => 'このメールアドレスは招待リストにありません。',
            ));
        }

        $code = (string) secure_random_int(100000, 999999);
        update_state(function (&$state) use ($email, $code) {
            $state['authCodes'][$email] = array(
                'codeHash' => hash_auth_code($email, $code),
                'attempts' => 0,
                'createdAt' => time(),
                'expiresAt' => time() + AUTH_CODE_TTL_SECONDS,
            );
            return null;
        });

        send_login_code($email, $code);

        $response = array(
            'ok' => true,
            'expiresInMinutes' => 10,
        );
        $config = config();
        if ((bool) get_value($config, 'debug_codes', false)) {
            $response['devCode'] = $code;
        }
        send_json(200, $response);
    }

    if ($action === 'auth_verify') {
        require_method('POST');
        require_ready_config();
        check_rate_limit('auth-verify-ip:' . client_ip(), 20, 900);

        $body = read_json_body();
        $email = normalize_email(get_value($body, 'email', ''));
        $code = trim((string) get_value($body, 'code', ''));
        $displayName = clean_display_name(get_value($body, 'displayName', ''), $email);

        if ($email === '' || !preg_match('/^\d{6}$/', $code)) {
            send_json(401, array(
                'error' => 'INVALID_CODE',
                'message' => '確認コードが違うか、期限が切れています。',
            ));
        }

        $user = update_state(function (&$state) use ($email, $code, $displayName) {
            $pending = get_value($state['authCodes'], $email, null);
            if (!$pending || (int) get_value($pending, 'expiresAt', 0) < time()) {
                return null;
            }

            $pending['attempts'] = (int) get_value($pending, 'attempts', 0) + 1;
            if ($pending['attempts'] > AUTH_ATTEMPT_LIMIT) {
                unset($state['authCodes'][$email]);
                return null;
            }

            if (!safe_hash_equals((string) get_value($pending, 'codeHash', ''), hash_auth_code($email, $code))) {
                $state['authCodes'][$email] = $pending;
                return null;
            }

            unset($state['authCodes'][$email]);
            foreach ($state['users'] as $index => $existing) {
                if (get_value($existing, 'email', '') === $email) {
                    $state['users'][$index]['displayName'] = $displayName ? $displayName : get_value($existing, 'displayName', 'member');
                    $state['users'][$index]['lastLoginAt'] = gmdate('c');
                    return $state['users'][$index];
                }
            }

            $newUser = array(
                'id' => uuid(),
                'email' => $email,
                'displayName' => $displayName,
                'createdAt' => gmdate('c'),
            );
            $state['users'][] = $newUser;
            return $newUser;
        });

        if (!$user) {
            send_json(401, array(
                'error' => 'INVALID_CODE',
                'message' => '確認コードが違うか、期限が切れています。',
            ));
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        issue_remember_cookie($user['id']);
        $members = touch_presence($user);
        send_json(200, array(
            'user' => public_user($user),
            'members' => $members,
        ));
    }

    if ($action === 'logout') {
        require_method('POST');
        $user = current_user();
        if ($user) {
            clear_presence(get_value($user, 'id', ''));
        }
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                get_value($params, 'path', '/'),
                get_value($params, 'domain', ''),
                (bool) get_value($params, 'secure', false),
                (bool) get_value($params, 'httponly', true)
            );
        }
        session_destroy();
        clear_remember_cookie();
        send_json(200, array('ok' => true));
    }

    if ($action === 'messages') {
        $user = current_user();
        if (!$user) {
            send_json(401, array('error' => 'UNAUTHORIZED'));
        }
        $members = touch_presence($user);

        if (get_value($_SERVER, 'REQUEST_METHOD', 'GET') === 'GET') {
            $after = max(0, (int) get_value($_GET, 'after', 0));
            $state = read_state();
            $messages = array_values(array_filter($state['messages'], function ($message) use ($after) {
                return $after === 0 || (int) get_value($message, 'sequence', 0) > $after;
            }));
            $messages = array_slice($messages, -MAX_MESSAGES);
            $messages = array_map('public_message', $messages);
            send_json(200, array(
                'messages' => $messages,
                'latestSequence' => (int) get_value($state, 'lastSequence', 0),
                'members' => $members,
            ));
        }

        require_method('POST');
        check_rate_limit('message:' . $user['id'], 60, 60);

        $contentType = get_value($_SERVER, 'CONTENT_TYPE', '');
        $attachment = null;
        if (stripos($contentType, 'multipart/form-data') !== false) {
            $text = trim(str_replace("\r\n", "\n", (string) get_value($_POST, 'text', '')));
            $attachment = save_uploaded_attachment(get_value($_FILES, 'file', null));
        } else {
            $body = read_json_body();
            $text = trim(str_replace("\r\n", "\n", (string) get_value($body, 'text', '')));
        }

        if ($text === '' && !$attachment) {
            send_json(400, array(
                'error' => 'EMPTY_MESSAGE',
                'message' => 'メッセージかファイルを入力してください。',
            ));
        }
        if (text_length($text) > MAX_MESSAGE_LENGTH) {
            send_json(400, array(
                'error' => 'MESSAGE_TOO_LONG',
                'message' => MAX_MESSAGE_LENGTH . '文字以内で入力してください。',
            ));
        }

        $message = update_state(function (&$state) use ($user, $text, $attachment) {
            $state['lastSequence'] = (int) get_value($state, 'lastSequence', 0) + 1;
            $message = array(
                'id' => uuid(),
                'sequence' => $state['lastSequence'],
                'userId' => $user['id'],
                'displayName' => get_value($user, 'displayName', 'member'),
                'text' => $text,
                'createdAt' => gmdate('c'),
            );
            if ($attachment) {
                $message['attachment'] = $attachment;
            }
            $state['messages'][] = $message;
            $state['messages'] = array_slice($state['messages'], -MAX_MESSAGES);
            return $message;
        });

        $members = touch_presence($user);
        send_json(201, array(
            'message' => public_message($message),
            'members' => $members,
        ));
    }

    send_json(404, array(
        'error' => 'NOT_FOUND',
        'message' => '見つかりません。',
    ));
} catch (Exception $error) {
    error_log($error);
    send_json(500, array(
        'error' => 'SERVER_ERROR',
        'message' => 'サーバーで問題が起きました。',
    ));
}

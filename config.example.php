<?php

return array(
    'app_name' => 'Community Chat',

    // Public URL. If this app is in a subdirectory, include it here.
    // Example: https://example.sakura.ne.jp/chat
    'app_url' => 'https://example.com',

    // Keep this true for a small private community.
    'require_invites' => true,

    // Keep false on the real server. Set true only when testing.
    'debug_codes' => false,

    // Replace with a random string of at least 32 characters.
    'session_secret' => 'replace-with-at-least-32-random-characters',

    // Use an address that belongs to your Sakura domain when possible.
    'mail_from' => 'no-reply@example.com',
    'mail_from_name' => 'Community Chat',
    'mail_return_path' => '',

    // On the real Sakura server with SSL enabled, keep this true.
    'cookie_secure' => true,

    // Attachment settings. 10485760 bytes = 10MB.
    'max_upload_bytes' => 10485760,

    // Members active within this many seconds are shown as in-room.
    'presence_timeout_seconds' => 120,

    'allowed_upload_extensions' => array(
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'pdf', 'txt', 'csv',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'zip'
    ),
);

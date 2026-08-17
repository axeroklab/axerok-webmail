<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'AxerOK Mail',
        'version' => '0.4.0-preview16',
        'mode' => 'cpanel',
        'base_url' => '',
        // In cPanel mode every system account creates its own private key in HOME.
        'key' => '0000000000000000000000000000000000000000000000000000000000000000',
        'session_name' => 'axerok_mail_cpanel',
        'session_lifetime' => 28800,
        'session_idle_timeout' => 0,
    ],
    'mail' => [
        'imap_host' => '__CPANEL_HOSTNAME__',
        'imap_port' => 993,
        'imap_encryption' => 'ssl',
        'smtp_host' => '__CPANEL_HOSTNAME__',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'allow_self_signed' => false,
        'page_size' => 40,
        'max_message_bytes' => 26214400,
    ],
    // Replaced at runtime with HOME/.axerok-mail/axerok.sqlite.
    'contacts' => ['dsn' => 'sqlite::memory:', 'username' => null, 'password' => null],
];

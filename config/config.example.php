<?php
declare(strict_types=1);

return [
    'app' => [
        'name' => 'AxerOK Mail',
        'version' => '0.4.0-preview22',
        // standalone: subdomain normal. cpanel: integrated Webmail application.
        'mode' => 'standalone',
        'base_url' => 'https://mail.example.com',
        // Generate with: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
        'key' => 'CHANGE_THIS_TO_64_RANDOM_HEX_CHARACTERS',
        'session_name' => 'axerok_mail',
        'session_lifetime' => 28800,
        // 0 disables application-level idle logout. cPanel SSO binding still applies.
        'session_idle_timeout' => 0,
    ],
    'mail' => [
        // Use the cPanel server hostname. One installation can serve every
        // email domain hosted by this same Dovecot/Exim server.
        'imap_host' => 'ns41.pronexo.com',
        'imap_port' => 993,
        'imap_encryption' => 'ssl', // ssl, tls or none
        'smtp_host' => 'ns41.pronexo.com',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl', // ssl, tls or none
        'allow_self_signed' => false,
        'page_size' => 40,
        'max_message_bytes' => 26214400, // 25 MiB
    ],
    'contacts' => [
        // Create this MySQL database and user from cPanel.
        'dsn' => 'mysql:host=localhost;dbname=CPANELUSER_axerokmail;charset=utf8mb4',
        'username' => 'CPANELUSER_axerok',
        'password' => 'CHANGE_THIS_DATABASE_PASSWORD',
    ],
];

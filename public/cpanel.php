<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AxerokMail\Mail\ImapClient;
use AxerokMail\Security\Credentials;

if ((string)config('app.mode') !== 'cpanel') {
    http_response_code(404);
    exit('AxerOK Mail no está configurado en modo cPanel.');
}

$email = strtolower(trim((string)($_SERVER['REMOTE_USER'] ?? getenv('REMOTE_USER') ?: '')));
$password = (string)($_SERVER['REMOTE_PASSWORD'] ?? getenv('REMOTE_PASSWORD') ?: '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    http_response_code(401);
    exit('La sesión autenticada de Webmail no está disponible. Volvé a Webmail e ingresá nuevamente.');
}

try {
    $imap = new ImapClient((array)config('mail'));
    $imap->connect($email, $password);
    $imap->close();
    Credentials::store($email, $password, app_credential_key());
} catch (Throwable $error) {
    error_log('[AxerOK Mail cPanel] Authentication bootstrap failed: ' . str_replace(["\r", "\n"], ' ', $error->getMessage()));
    http_response_code(401);
    exit('No se pudo validar la cuenta autenticada de Webmail.');
}

$index = file_get_contents(__DIR__ . '/index.html');
if ($index === false) {
    http_response_code(500);
    exit('No se encontró la interfaz de AxerOK Mail.');
}
header('Content-Type: text/html; charset=utf-8');
echo $index;

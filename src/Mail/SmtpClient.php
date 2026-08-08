<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

final class SmtpClient
{
    /** @var resource|null */ private $socket = null;
    public function __construct(private readonly array $options) {}

    /** @param array<int,array{name:string,type:string,data:string}> $attachments */
    public function send(string $username, string $password, string $to, string $cc, string $bcc, string $subject, string $body, string $html, bool $receiptRequested = false, string $priority = 'normal', array $attachments = [], string $displayName = '', string $replyTo = '', string $organization = ''): string
    {
        $toRecipients = $this->recipients($to); $ccRecipients = $this->recipients($cc); $bccRecipients = $this->recipients($bcc);
        if ($toRecipients === []) { throw new MailException('Ingresá al menos un destinatario válido.'); }
        $recipients = array_values(array_unique([...$toRecipients, ...$ccRecipients, ...$bccRecipients]));
        if (count($recipients) > 50) { throw new MailException('El mensaje supera el límite de 50 destinatarios.'); }
        $host = (string)$this->options['smtp_host']; $port = (int)$this->options['smtp_port'];
        $encryption = (string)($this->options['smtp_encryption'] ?? 'ssl');
        $context = stream_context_create(['ssl' => ['verify_peer' => !($this->options['allow_self_signed'] ?? false), 'verify_peer_name' => !($this->options['allow_self_signed'] ?? false), 'allow_self_signed' => (bool)($this->options['allow_self_signed'] ?? false), 'peer_name' => $host]]);
        $this->socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($this->socket)) { throw new MailException("No se pudo conectar con SMTP: {$error} ({$errno})"); }
        stream_set_timeout($this->socket, 25); $this->expect([220]);
        $this->write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost')); $this->expect([250]);
        if ($encryption === 'tls') {
            $this->write('STARTTLS'); $this->expect([220]);
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { throw new MailException('No se pudo activar TLS para SMTP.'); }
            $this->write('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost')); $this->expect([250]);
        }
        $this->write('AUTH LOGIN'); $this->expect([334]); $this->write(base64_encode($username)); $this->expect([334]); $this->write(base64_encode($password)); $this->expect([235]);
        $this->write('MAIL FROM:<' . $username . '>'); $this->expect([250]);
        foreach ($recipients as $recipient) { $this->write('RCPT TO:<' . $recipient . '>'); $this->expect([250, 251]); }
        $this->write('DATA'); $this->expect([354]);
        $message = $this->buildMessage($username, $toRecipients, $ccRecipients, $subject, $body, $html, $receiptRequested, $priority, $attachments, $displayName, $replyTo, $organization);
        fwrite($this->socket, preg_replace('/(?m)^\./', '..', $message) . "\r\n.\r\n");
        $this->expect([250]); // Desde este punto el servidor ya aceptó el mensaje.
        $this->write('QUIT');
        fclose($this->socket); $this->socket = null; return $message;
    }

    private function buildMessage(string $from, array $to, array $cc, string $subject, string $body, string $html, bool $receiptRequested, string $priority, array $attachments, string $displayName = '', string $replyTo = '', string $organization = ''): string
    {
        $boundary = 'axerok_mixed_' . bin2hex(random_bytes(12));
        $alternative = 'axerok_alt_' . bin2hex(random_bytes(12));
        $displayName=trim(str_replace(["\r","\n"],' ',$displayName));$replyTo=trim($replyTo);$organization=trim(str_replace(["\r","\n"],' ',$organization));
        $fromHeader=$displayName!==''?'=?UTF-8?B?'.base64_encode($displayName).'?= <'.$from.'>':'<'.$from.'>';
        $headers = ['Date: ' . date(DATE_RFC2822), 'From: ' . $fromHeader, 'To: ' . implode(', ', $to), 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=', 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . substr(strrchr($from, '@') ?: '@localhost', 1) . '>', 'MIME-Version: 1.0'];
        if($replyTo!==''&&filter_var($replyTo,FILTER_VALIDATE_EMAIL))$headers[]='Reply-To: <'.$replyTo.'>';
        if($organization!=='')$headers[]='Organization: =?UTF-8?B?'.base64_encode($organization).'?=';
        if ($cc !== []) { $headers[] = 'Cc: ' . implode(', ', $cc); }
        if ($receiptRequested) { $headers[] = 'Disposition-Notification-To: <' . $from . '>'; $headers[] = 'Return-Receipt-To: <' . $from . '>'; }
        if($priority==='high'){$headers[]='X-Priority: 1 (Highest)';$headers[]='Importance: High';$headers[]='Priority: urgent';}
        elseif($priority==='low'){$headers[]='X-Priority: 5 (Lowest)';$headers[]='Importance: Low';$headers[]='Priority: non-urgent';}
        $alternativeBody = "--{$alternative}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($body)
            . "\r\n--{$alternative}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . quoted_printable_encode($html)
            . "\r\n--{$alternative}--";
        if ($attachments === []) { $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alternative . '"'; return implode("\r\n", $headers) . "\r\n\r\n" . $alternativeBody; }
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $message = implode("\r\n", $headers) . "\r\n\r\n--{$boundary}\r\nContent-Type: multipart/alternative; boundary=\"{$alternative}\"\r\n\r\n" . $alternativeBody;
        foreach ($attachments as $attachment) {
            $filename = str_replace(['"', "\r", "\n"], '', $attachment['name']);
            $message .= "\r\n--{$boundary}\r\nContent-Type: " . ($attachment['type'] ?: 'application/octet-stream') . "; name=\"{$filename}\"\r\nContent-Disposition: attachment; filename=\"{$filename}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($attachment['data']), 76, "\r\n");
        }
        return $message . "\r\n--{$boundary}--";
    }

    /** @return array<int,string> */
    private function recipients(string $value): array
    {
        $items = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $value) ?: []), static fn(string $item): bool => $item !== ''));
        foreach ($items as $item) { if (!filter_var($item, FILTER_VALIDATE_EMAIL)) { throw new MailException('Hay una dirección de correo inválida.'); } }
        return array_values(array_unique($items));
    }

    private function write(string $line): void { fwrite($this->socket, $line . "\r\n"); }
    private function expect(array $codes): string
    {
        $response = '';
        do { $line = fgets($this->socket); if ($line === false) { throw new MailException('El servidor SMTP cerró la conexión.'); } $response .= $line; } while (isset($line[3]) && $line[3] === '-');
        $code = (int)substr($line, 0, 3); if (!in_array($code, $codes, true)) { throw new MailException('SMTP: ' . trim($response)); }
        return $response;
    }
}

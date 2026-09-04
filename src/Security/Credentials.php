<?php
declare(strict_types=1);

namespace AxerokMail\Security;

final class Credentials
{
    private const MAX_ACCOUNTS = 8;

    public static function store(string $email, string $password, string $hexKey, ?string $imapUser = null): void
    {
        self::migrateLegacy();
        $email = strtolower(trim($email));
        $accounts = self::accountMap();
        if (!isset($accounts[$email]) && count($accounts) >= self::MAX_ACCOUNTS) {
            throw new \RuntimeException('Podés mantener abiertas hasta 8 cuentas.');
        }
        $entry = ['secret' => self::encrypt($password, $hexKey), 'added_at' => time()];
        // Usuario real de login IMAP/SMTP cuando difiere del email (p.ej. master user
        // de dovecot en el SSO: "casilla*nxsso"). El email se guarda limpio para que
        // la identidad mostrada y el "De:" al enviar sean la dirección real.
        if ($imapUser !== null && $imapUser !== '' && strtolower(trim($imapUser)) !== $email) {
            $entry['imap_user'] = trim($imapUser);
        }
        $accounts[$email] = $entry;
        session_regenerate_id(true);
        $_SESSION['mail_accounts'] = $accounts;
        $_SESSION['mail_active'] = $email;
        unset($_SESSION['mail_user'], $_SESSION['mail_secret']);
    }

    /** Usuario de autenticación IMAP/SMTP para la cuenta (imap_user si existe, si no el email). */
    public static function imapUser(?string $requested = null): ?string
    {
        $email = self::email($requested);
        if ($email === null) return null;
        $user = (string)(self::accountMap()[$email]['imap_user'] ?? '');
        return $user !== '' ? $user : $email;
    }

    /** @return list<string> */
    public static function accounts(): array
    {
        self::migrateLegacy();
        return array_keys(self::accountMap());
    }

    public static function email(?string $requested = null): ?string
    {
        self::migrateLegacy();
        $accounts = self::accountMap();
        $candidate = strtolower(trim((string)($requested ?? $_SESSION['mail_active'] ?? '')));
        if ($candidate !== '' && isset($accounts[$candidate])) return $candidate;
        return array_key_first($accounts);
    }

    public static function has(string $email): bool
    {
        return isset(self::accountMap()[strtolower(trim($email))]);
    }

    public static function password(string $hexKey, ?string $requested = null): ?string
    {
        $email = self::email($requested);
        if ($email === null) return null;
        $secret = (string)(self::accountMap()[$email]['secret'] ?? '');
        return self::decrypt($secret, $hexKey);
    }

    public static function setActive(string $email): bool
    {
        $email = strtolower(trim($email));
        if (!isset(self::accountMap()[$email])) return false;
        $_SESSION['mail_active'] = $email;
        return true;
    }

    public static function remove(string $email): void
    {
        $email = strtolower(trim($email));
        $accounts = self::accountMap();
        unset($accounts[$email]);
        $_SESSION['mail_accounts'] = $accounts;
        if (($_SESSION['mail_active'] ?? null) === $email) {
            $_SESSION['mail_active'] = array_key_first($accounts);
        }
    }

    public static function clear(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    /** @return array<string,array{secret:string,added_at:int}> */
    private static function accountMap(): array
    {
        $value = $_SESSION['mail_accounts'] ?? [];
        return is_array($value) ? $value : [];
    }

    private static function migrateLegacy(): void
    {
        if (isset($_SESSION['mail_accounts']) || empty($_SESSION['mail_user']) || empty($_SESSION['mail_secret'])) return;
        $email = strtolower(trim((string)$_SESSION['mail_user']));
        $_SESSION['mail_accounts'] = [$email => ['secret' => (string)$_SESSION['mail_secret'], 'added_at' => time()]];
        $_SESSION['mail_active'] = $email;
        unset($_SESSION['mail_user'], $_SESSION['mail_secret']);
    }

    private static function encrypt(string $password, string $hexKey): string
    {
        $key = hex2bin($hexKey);
        if ($key === false || strlen($key) !== 32) throw new \RuntimeException('La clave de aplicación no es válida.');
        $iv = random_bytes(12); $tag = '';
        $encrypted = openssl_encrypt($password, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($encrypted === false) throw new \RuntimeException('No se pudieron proteger las credenciales.');
        return base64_encode($iv . $tag . $encrypted);
    }

    private static function decrypt(string $secret, string $hexKey): ?string
    {
        $payload = base64_decode($secret, true); $key = hex2bin($hexKey);
        if ($payload === false || strlen($payload) < 29 || $key === false || strlen($key) !== 32) return null;
        $plain = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
        return $plain === false ? null : $plain;
    }
}

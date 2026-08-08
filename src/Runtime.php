<?php
declare(strict_types=1);

namespace AxerokMail;

final class Runtime
{
    public static function isCpanel(): bool
    {
        return (string)config('app.mode', 'standalone') === 'cpanel';
    }

    public static function home(): string
    {
        $candidates = [];
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $user = posix_getpwuid(posix_geteuid());
            if (is_array($user) && isset($user['dir'])) $candidates[] = (string)$user['dir'];
        }
        $candidates[] = (string)($_SERVER['HOME'] ?? getenv('HOME') ?: '');
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && preg_match('#^/home/[a-zA-Z0-9._-]+$#', $real)) return $real;
        }
        throw new \RuntimeException('No se pudo determinar la cuenta cPanel activa.');
    }

    public static function storage(string $path = ''): string
    {
        $base = self::isCpanel() ? self::home() . '/.axerok-mail' : dirname(__DIR__) . '/storage';
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException('No se pudo crear el almacenamiento privado de AxerOK Mail.');
        }
        @chmod($base, 0700);
        $path = ltrim($path, '/');
        return $path === '' ? $base : $base . '/' . $path;
    }

    public static function credentialKey(string $masterKey): string
    {
        if (!self::isCpanel()) return $masterKey;
        $keyFile = self::storage('app.key');
        if (!is_file($keyFile)) {
            $temporary = $keyFile . '.' . bin2hex(random_bytes(4));
            if (@file_put_contents($temporary, bin2hex(random_bytes(32)), LOCK_EX) === false) {
                throw new \RuntimeException('No se pudo crear la clave privada de la cuenta.');
            }
            @chmod($temporary, 0600);
            if (!@rename($temporary, $keyFile) && !is_file($keyFile)) {
                @unlink($temporary);
                throw new \RuntimeException('No se pudo activar la clave privada de la cuenta.');
            }
        }
        $key = trim((string)@file_get_contents($keyFile));
        if (!preg_match('/^[a-f0-9]{64}$/i', $key)) throw new \RuntimeException('La clave privada de la cuenta no es válida.');
        @chmod($keyFile, 0600);
        return strtolower($key);
    }

    public static function webmailHomeUrl(): ?string
    {
        if (!self::isCpanel()) return null;
        $token = (string)($_SERVER['cp_security_token'] ?? getenv('cp_security_token') ?: '');
        return preg_match('#^/cpsess[0-9]+$#', $token) ? $token . '/webmail/jupiter/index.html?mailclient=none' : null;
    }
}

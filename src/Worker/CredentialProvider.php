<?php
declare(strict_types=1);

namespace AxerokMail\Worker;

use RuntimeException;

final class CredentialProvider
{
    /** @return array{email:string,password:string} */
    public static function forAccount(string $email): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Worker account is invalid.');
        $key = 'AXEROK_WORKER_' . strtoupper(hash('sha256', $email));
        $password = getenv($key);
        if (!is_string($password) || $password === '') throw new RuntimeException("No worker credential is configured for {$email}.");
        return ['email' => $email, 'password' => $password];
    }
}

<?php
declare(strict_types=1);

namespace AxerokMail\Security;

final class LoginRateLimiter
{
    private const WINDOW_SECONDS = 600;
    private const MAX_PER_ACCOUNT = 8;
    private const MAX_PER_IP = 30;

    public function __construct(private readonly string $directory) {}

    public function assertAllowed(string $ip, string $email): void
    {
        $this->assertScope($this->ipKey($ip), self::MAX_PER_IP);
        $this->assertScope($this->accountKey($email), self::MAX_PER_ACCOUNT);
    }

    public function failure(string $ip, string $email): void
    {
        foreach ([$this->ipKey($ip), $this->accountKey($email)] as $key) {
            $this->append($key, time());
        }
    }

    public function success(string $ip, string $email): void
    {
        $file = $this->file($this->accountKey($email));
        if (is_file($file)) { @unlink($file); }
    }

    private function assertScope(string $key, int $maximum): void
    {
        $attempts = $this->read($key);
        if (count($attempts) >= $maximum) {
            $remaining = max(1, self::WINDOW_SECONDS - (time() - min($attempts)));
            throw new \RuntimeException('Demasiados intentos. Esperá ' . (int)ceil($remaining / 60) . ' minutos.');
        }
    }

    private function ipKey(string $ip): string { return 'ip-' . hash('sha256', trim($ip)); }
    private function accountKey(string $email): string { return 'account-' . hash('sha256', strtolower(trim($email))); }

    /** @return array<int,int> */
    private function read(string $key): array
    {
        $file = $this->file($key);
        if (!is_file($file)) { return []; }
        $handle = @fopen($file, 'c+');
        if (!$handle) { return []; }
        flock($handle, LOCK_SH);
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN); fclose($handle);
        $decoded = json_decode($contents ?: '[]', true);
        $cutoff = time() - self::WINDOW_SECONDS;
        return array_values(array_filter(is_array($decoded) ? array_map('intval', $decoded) : [], static fn(int $time): bool => $time >= $cutoff));
    }

    /** @param array<int,int> $attempts */
    private function write(string $key, array $attempts): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('No se pudo inicializar la protección de acceso.');
        }
        $handle = fopen($this->file($key), 'c+');
        if (!$handle) { throw new \RuntimeException('No se pudo registrar el intento de acceso.'); }
        flock($handle, LOCK_EX); ftruncate($handle, 0); rewind($handle);
        fwrite($handle, json_encode($attempts, JSON_THROW_ON_ERROR)); fflush($handle);
        flock($handle, LOCK_UN); fclose($handle); @chmod($this->file($key), 0600);
    }

    private function append(string $key, int $timestamp): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('No se pudo inicializar la protección de acceso.');
        }
        $file=$this->file($key);$handle=fopen($file,'c+');
        if(!$handle||!flock($handle,LOCK_EX)){if(is_resource($handle))fclose($handle);throw new \RuntimeException('No se pudo registrar el intento de acceso.');}
        rewind($handle);$decoded=json_decode(stream_get_contents($handle)?:'[]',true);$cutoff=$timestamp-self::WINDOW_SECONDS;
        $attempts=array_values(array_filter(is_array($decoded)?array_map('intval',$decoded):[],static fn(int $time):bool=>$time>=$cutoff));$attempts[]=$timestamp;
        ftruncate($handle,0);rewind($handle);$written=fwrite($handle,json_encode($attempts,JSON_THROW_ON_ERROR));$flushed=fflush($handle);flock($handle,LOCK_UN);fclose($handle);@chmod($file,0600);
        if($written===false||!$flushed)throw new \RuntimeException('No se pudo registrar el intento de acceso.');
    }

    private function file(string $key): string { return rtrim($this->directory, '/') . '/' . $key . '.json'; }
}

<?php
declare(strict_types=1);

namespace AxerokMail\Worker;

use PDO;
use RuntimeException;

final class JobQueue
{
    public function __construct(private readonly PDO $db) { $this->install(); }

    public function enqueue(string $owner, string $type, string $idempotencyKey, array $payload, int $availableAt): string
    {
        if (!preg_match('/^[a-z0-9._:-]{1,160}$/i', $type) || !preg_match('/^[a-zA-Z0-9._:-]{8,180}$/', $idempotencyKey)) throw new RuntimeException('Invalid worker job identity.');
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) > 262144) throw new RuntimeException('Worker payload exceeds 256 KiB.');
        $id = bin2hex(random_bytes(16));
        $statement = $this->db->prepare('INSERT INTO axerok_jobs(id,owner,type,idempotency_key,payload,status,available_at,attempts,created_at) VALUES(?,?,?,?,?,?,?,?,?)');
        try { $statement->execute([$id, strtolower(trim($owner)), $type, $idempotencyKey, $encoded, 'queued', $availableAt, 0, time()]); }
        catch (\PDOException) { $existing = $this->db->prepare('SELECT id FROM axerok_jobs WHERE owner=? AND idempotency_key=?'); $existing->execute([strtolower(trim($owner)), $idempotencyKey]); return (string)$existing->fetchColumn(); }
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function claim(string $workerId, int $leaseSeconds = 300): ?array
    {
        $now = time(); $this->db->beginTransaction();
        try {
            $query = $this->db->prepare("SELECT * FROM axerok_jobs WHERE (status='queued' AND available_at<=?) OR (status='running' AND locked_at<?) ORDER BY available_at,id LIMIT 1");
            $query->execute([$now, $now - max(30, $leaseSeconds)]); $job = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) { $this->db->commit(); return null; }
            $update = $this->db->prepare("UPDATE axerok_jobs SET status='running',locked_at=?,locked_by=?,attempts=attempts+1 WHERE id=? AND (status='queued' OR locked_at<?)");
            $update->execute([$now, $workerId, $job['id'], $now - max(30, $leaseSeconds)]);
            if ($update->rowCount() !== 1) { $this->db->rollBack(); return null; }
            $this->db->commit(); $job['payload'] = json_decode((string)$job['payload'], true, 32, JSON_THROW_ON_ERROR); return $job;
        } catch (\Throwable $error) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $error; }
    }

    public function finish(string $id, bool $success, ?string $error = null, int $retryAt = 0): void
    {
        if ($success) $statement = $this->db->prepare("UPDATE axerok_jobs SET status='done',locked_at=NULL,locked_by=NULL,last_error=NULL,finished_at=? WHERE id=?");
        elseif ($retryAt > time()) $statement = $this->db->prepare("UPDATE axerok_jobs SET status='queued',available_at=?,locked_at=NULL,locked_by=NULL,last_error=? WHERE id=?");
        else $statement = $this->db->prepare("UPDATE axerok_jobs SET status='failed',locked_at=NULL,locked_by=NULL,last_error=?,finished_at=? WHERE id=?");
        if ($success) $statement->execute([time(), $id]); elseif ($retryAt > time()) $statement->execute([$retryAt, substr((string)$error, 0, 1000), $id]); else $statement->execute([substr((string)$error, 0, 1000), time(), $id]);
    }

    private function install(): void
    {
        $this->db->exec("CREATE TABLE IF NOT EXISTS axerok_jobs (id VARCHAR(32) PRIMARY KEY, owner VARCHAR(255) NOT NULL, type VARCHAR(40) NOT NULL, idempotency_key VARCHAR(180) NOT NULL, payload TEXT NOT NULL, status VARCHAR(16) NOT NULL, available_at INTEGER NOT NULL, attempts INTEGER NOT NULL DEFAULT 0, locked_at INTEGER NULL, locked_by VARCHAR(120) NULL, last_error VARCHAR(1000) NULL, created_at INTEGER NOT NULL, finished_at INTEGER NULL, UNIQUE(owner,idempotency_key))");
    }
}

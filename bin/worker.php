<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "AxerOK worker is CLI-only.\n"); exit(2); }
$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';
if (!is_file($configFile)) { fwrite(STDERR, "Missing config/config.php.\n"); exit(2); }
$config = require $configFile;
$lockPath = $root . '/storage/worker.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Another AxerOK worker is running.\n"); exit(0); }
require $root . '/src/Worker/JobQueue.php';
$contacts = (array)($config['contacts'] ?? []);
$db = new PDO((string)$contacts['dsn'], $contacts['username'] ?? null, $contacts['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$queue = new AxerokMail\Worker\JobQueue($db);
$limit = 20;
foreach ($argv as $argument) if (str_starts_with($argument, '--limit=')) $limit = max(1, min(100, (int)substr($argument, 8)));
$workerId = gethostname() . ':' . getmypid();
for ($processed = 0; $processed < $limit; $processed++) {
    $job = $queue->claim($workerId);
    if ($job === null) break;
    $queue->finish((string)$job['id'], false, 'No credential provider is configured for scheduled mailbox jobs.', time() + 3600);
    fwrite(STDERR, "Job {$job['id']} deferred: credential provider is not configured.\n");
}
flock($lock, LOCK_UN); fclose($lock);

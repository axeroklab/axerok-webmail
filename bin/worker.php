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
require $root . '/src/Worker/CredentialProvider.php';
require $root . '/src/Mail/MailException.php';
require $root . '/src/Mail/SmtpClient.php';
$contacts = (array)($config['contacts'] ?? []);
$db = new PDO((string)$contacts['dsn'], $contacts['username'] ?? null, $contacts['password'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$queue = new AxerokMail\Worker\JobQueue($db);
$limit = 20;
foreach ($argv as $argument) if (str_starts_with($argument, '--limit=')) $limit = max(1, min(100, (int)substr($argument, 8)));
$workerId = gethostname() . ':' . getmypid();
for ($processed = 0; $processed < $limit; $processed++) {
    $job = $queue->claim($workerId);
    if ($job === null) break;
    try {
        $credentials = AxerokMail\Worker\CredentialProvider::forAccount((string)$job['owner']);
        if ((string)$job['type'] !== 'mail.send') throw new RuntimeException('Unsupported worker job type.');
        $payload = (array)$job['payload'];
        $smtp = new AxerokMail\Mail\SmtpClient((array)($config['mail'] ?? []));
        $smtp->send($credentials['email'], $credentials['password'], (string)($payload['to'] ?? ''), (string)($payload['cc'] ?? ''), (string)($payload['bcc'] ?? ''), (string)($payload['subject'] ?? '(Sin asunto)'), (string)($payload['body'] ?? ''), (string)($payload['html'] ?? ''), !empty($payload['receipt_requested']), (string)($payload['priority'] ?? 'normal'), [], (string)($payload['display_name'] ?? ''), (string)($payload['reply_to'] ?? ''), (string)($payload['organization'] ?? ''));
        $queue->finish((string)$job['id'], true);
        fwrite(STDOUT, "Job {$job['id']} completed.\n");
    } catch (Throwable $error) {
        $queue->finish((string)$job['id'], false, $error->getMessage(), time() + 3600);
        fwrite(STDERR, "Job {$job['id']} deferred: {$error->getMessage()}\n");
    }
}
flock($lock, LOCK_UN); fclose($lock);

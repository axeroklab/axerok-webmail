# AxerOK worker for cPanel

The browser session is intentionally not a worker credential. `Credentials` encrypts mailbox passwords inside the authenticated PHP session, so a cron process must not read session files or copy those secrets into a queue.

## Required deployment contract

Before enabling scheduled send, vacation replies, or server-side filters, the cPanel administrator must provide one of these supported authentication designs:

1. A Dovecot/Exim service account with a restricted scope and a secret stored outside the web root, readable only by the cron user; or
2. A cPanel-managed secret reference that the worker can resolve at runtime without persisting the mailbox password in MySQL, the queue, logs, or job payloads.

The application key alone is not sufficient. It protects data that is already stored; it does not authorize a worker to authenticate to IMAP or SMTP. The bundled worker accepts test credentials only through an environment variable named `AXEROK_WORKER_` followed by the uppercase SHA-256 hash of the full mailbox address. The value must be injected by the cPanel process environment and must never be written to the database, queue, payload, or log.

## Queue invariants

The future worker must use a database queue with:

- an idempotency key unique per logical operation;
- `available_at`, `attempts`, `locked_at`, `locked_by`, and `last_error` fields;
- an atomic claim transaction with a short lease and a reclaim timeout;
- bounded exponential retries and a terminal failed state;
- an append-only audit record containing event type, account identifier, job identifier, result, and timestamps, never credentials or message bodies;
- a payload containing only non-secret references and user-selected message data;
- a per-account concurrency limit so one mailbox cannot exhaust IMAP/SMTP connections.

The worker must acquire the lease before opening IMAP/SMTP, release it in a `finally` path, and treat repeated execution of the same idempotency key as a no-op. It must use the same TLS and certificate validation settings as the web application.

## cPanel cron shape

After the authentication contract is configured, the cron entry should invoke a CLI-only entry point with an absolute PHP path and a lock, for example:

```text
*/1 * * * * /usr/local/bin/php /home/CPANELUSER/axerok/bin/worker.php --limit=20 >> /home/CPANELUSER/axerok/storage/worker.log 2>&1
```

The entry point must use `flock`, refuse web requests, set a finite execution time, and exit non-zero when a job remains failed after its retry budget. No scheduled feature is enabled by this document alone; cPanel/Dovecot/Exim validation is required first.

# phpqdrv

A small, dependency-light queue system for PHP with interchangeable backends.

```
RTIGLOBAL\Phpqdrv
```

## Features

- **Five drivers**: Redis, MariaDB/MySQL, PostgreSQL, SQLite, Beanstalkd
- **Nothing is silently lost**: reserved jobs are recovered if a worker dies
- **Real retries**: failed jobs are released with exponential backoff, then parked in a dead letter store
- **Atomic claims**: no two workers can ever handle the same job (Lua scripts for Redis, `SKIP LOCKED` / guarded updates for SQL databases)
- **Delayed jobs** (`push($queue, $payload, $delay)`)
- **Optional dependencies**: predis and Pheanstalk are `suggest`-ed; the package itself only needs `ext-pdo` + `psr/log`
- **PSR-3 logging**, graceful `SIGTERM`/`SIGINT` shutdown, static analysis clean at PHPStan level 8

## Requirements

- PHP 8.1+
- `ext-pdo` and the matching PDO driver (`pdo_sqlite`, `pdo_mysql`, `pdo_pgsql`)
- `ext-pcntl` (optional, for graceful shutdown)

## Installation

```bash
composer require rtiglobal/phpqdrv
```

Then install the client for the backend you actually use:

```bash
composer require predis/predis   # Redis
composer require pda/pheanstalk  # Beanstalkd
```

## Quick start

### SQLite

```php
use RTIGLOBAL\Phpqdrv\QueueFactory;
use RTIGLOBAL\Phpqdrv\Worker;

$pdo = new PDO('sqlite:' . __DIR__ . '/queue.sqlite');
$queue = QueueFactory::sqlite($pdo);

$queue->push('emails', ['to' => 'user@example.com', 'subject' => 'Welcome']);

$worker = new Worker($queue, maxAttempts: 3, sleepSeconds: 1);
$worker->work('emails', function (array $payload): void {
    sendEmail($payload['to'], $payload['subject']);
});
```

### Redis

```php
use Predis\Client;
use RTIGLOBAL\Phpqdrv\Adapters\PredisAdapter;
use RTIGLOBAL\Phpqdrv\Drivers\RedisQueue;

$queue = new RedisQueue(new PredisAdapter(new Client('redis://127.0.0.1:6379')));
// or: QueueFactory::predis(new Client(...));
// or: QueueFactory::redis($anyRedisClientInterfaceImplementation);
```

### PostgreSQL / MariaDB

```php
use RTIGLOBAL\Phpqdrv\QueueFactory;

$queue = QueueFactory::postgresql(new PDO('pgsql:host=localhost;dbname=app', 'user', 'pass'));
$queue = QueueFactory::mariadb(new PDO('mysql:host=localhost;dbname=app', 'user', 'pass'));
```

### Beanstalkd

```php
use Pheanstalk\Pheanstalk;
use RTIGLOBAL\Phpqdrv\QueueFactory;

$queue = QueueFactory::pheanstalk(new Pheanstalk('127.0.0.1', 11300));
// or: QueueFactory::beanstalkd($anyBeanstalkdConnectionInterfaceImpl);
```

## Queue API

```php
interface QueueInterface
{
    public function push(string $queue, array $payload, int $delay = 0): JobInterface;
    public function pop(string $queue): ?JobInterface;
    public function ack(JobInterface $job): void;
    public function fail(JobInterface $job): void;
    public function release(JobInterface $job, int $delay = 0): void;
    public function size(string $queue): int;
    public function requeueExpired(): int;
}
```

`Job::getAttempts()` reports the number of deliveries **including the current one**: a freshly pushed job reports `0`, the first delivery reports `1`.

## Worker

```php
$worker = new Worker(
    queue: $queue,
    maxAttempts: 3,     // deliveries before a job is parked as failed
    sleepSeconds: 1,    // idle polling interval
    logger: $psrLogger, // optional PSR-3 logger
);

$worker->work('emails', $processor);              // daemon: runs until stopped
$worker->work('emails', $processor, maxJobs: 50); // process 50 jobs, then return
$worker->processNext('emails', $processor);       // handle a single job, returns false when empty
```

`work()` is a daemon loop: it keeps polling forever (until `maxJobs` jobs were *processed* or `stop()` is called). For cron-style runs use `processNext()` in your own bounded loop.

Failure policy:

1. The processor throws `\Throwable` → job is logged and `release()`d with a backoff of 1s, 2s, 4s… (capped at 300s).
2. Once `getAttempts() >= maxAttempts` → `fail()` parks the job in the dead letter store.
3. Infrastructure errors (queue unreachable) are logged and retried; the worker never dies.

Graceful shutdown: `SIGTERM`/`SIGINT` (when `ext-pcntl` is available) finish the in-flight job, then the loop returns. Call `$worker->stop()` from your processor to stop after the current job.

## Reliability model

A job handed to a worker is *reserved*, not deleted:

| Backend | How a reserved job survives a dead worker |
|---|---|
| SQLite / MariaDB / PostgreSQL | `status = 'processing'` + `reserved_at`; the worker periodically calls `requeueExpired()` |
| Redis | body moved into a `reserved` sorted set scored by deadline; `requeueExpired()` puts it back |
| Beanstalkd | the server's TTR handles it natively |

Each driver takes a `visibilityTimeout` (default 300s) in its constructor. Set it slightly above your slowest job. Workers sweep every 30 seconds automatically.

`fail()` writes to a dead letter store per backend: a `failed` status for SQL, a `{prefix}:{queue}:failed` list for Redis, a `{queue}.failed` tube for Beanstalkd.

## Payloads

Payloads are arrays encoded as JSON with strict error handling:

- invalid UTF-8 or resources → `InvalidPayloadException` on push
- corrupt stored payloads → `InvalidPayloadException` on pop
- floats keep their zero fraction (`3.0` stays `3.0`)

Payloads are stored as opaque JSON text on every backend, so they round-trip exactly. (PostgreSQL's `JSONB` would normalise key order; subclass `PostgreSQLQueue` and override `payloadSelectExpression()`/`payloadParameter()` if you want that trade-off.)

## Schema

Drivers create their table/index on construction (`ensureSchema()`), idempotently. For production, pass `ensureSchema: false` and apply the DDL yourself:

```sql
-- PostgreSQL
CREATE TABLE queue_jobs (
    id VARCHAR(64) PRIMARY KEY NOT NULL,
    queue VARCHAR(191) NOT NULL,
    payload TEXT NOT NULL,
    attempts INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    available_at BIGINT NOT NULL DEFAULT 0,
    reserved_at BIGINT NULL,
    created_at BIGINT NOT NULL,
    updated_at BIGINT NOT NULL
);
CREATE INDEX queue_jobs_pop_idx ON queue_jobs (queue, status, available_at, created_at);
```

All timestamps are integer **microseconds** since the epoch. `status` is one of `pending`, `processing`, `completed`, `failed`.

The underlying `PDO` is switched to `ERRMODE_EXCEPTION` — a queue that fails silently is worse than one that throws.

## Running the tests

```bash
composer install
vendor/bin/phpunit        # unit tests + SQLite integration tests
vendor/bin/phpstan analyse
```

Redis, MariaDB and PostgreSQL tests are skipped unless their DSN is provided:

```bash
export QDRV_REDIS_DSN="redis://127.0.0.1:6379"
export QDRV_MYSQL_DSN="mysql:host=127.0.0.1;port=3306;dbname=phpqdrv_test"
export QDRV_MYSQL_USER=root QDRV_MYSQL_PASSWORD=
export QDRV_PGSQL_DSN="pgsql:host=127.0.0.1;port=5432;dbname=phpqdrv_test"
export QDRV_PGSQL_USER=postgres QDRV_PGSQL_PASSWORD=
vendor/bin/phpunit
```

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/AmazingFeature`)
3. Add tests and run `composer check`
4. Open a Pull Request

## License

MIT — see [LICENSE.md](LICENSE.md).

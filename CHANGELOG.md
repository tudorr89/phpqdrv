# Changelog

## 0.2.0

Complete correctness pass. `0.1.0` could lose every failed job; this release
makes "a pushed job is eventually acked or parked" an invariant.

### Breaking

- Vendor namespace renamed `Tudorr89\Phpqdrv` → `RTIGLOBAL\Phpqdrv`; package
  renamed `tudorr89/phpqdrv` → `rtiglobal/phpqdrv`.
- `QueueInterface::count()` → `size()`.
- `QueueInterface` gained `release(JobInterface $job, int $delay = 0)` and
  `requeueExpired(): int`.
- `QueueInterface::push()` gained a `$delay` parameter.
- `JobInterface` dropped the no-op `markAsFailed()` / `markAsCompleted()`.
- `Job::getAttempts()` now reports the current delivery number (1 on first
  delivery) instead of a stale pre-increment value.
- Drivers no longer accept third-party clients directly:
  - `RedisQueue` takes a `Contracts\RedisClientInterface` (`PredisAdapter`)
  - `BeanstalkdQueue` takes a `Contracts\BeanstalkdConnectionInterface`
    (`PheanstalkAdapter`)
- `pear/net_beanstalkd` removed (it is not installable from Packagist);
  Beanstalkd support now goes through `pda/pheanstalk`.
- `predis/predis` and `pda/pheanstalk` moved from `require` to `suggest`.
- Unused `php-di/php-di` dependency removed.
- The PDO table layout changed (new status, microsecond timestamps,
  `reserved_at`). Recreate `queue_jobs`.

### Fixed

- The worker never lost track of a failing job: it was removed from the queue
  and then re-thrown, so it vanished (Redis) or stayed `processing` forever
  (SQL). Failures now `release()` with backoff and finally `fail()`.
- `MariaDBQueue::pop()` left the job `pending`, so the same job was processed
  repeatedly and concurrently. It now claims with `status = 'processing'`, and
  the enum includes that value.
- `SQLiteQueue::pop()` could hand the same row to two workers (deferred
  transaction, unlocked read). It now uses `BEGIN IMMEDIATE` plus a guarded
  `UPDATE ... WHERE status = 'pending'`.
- `BeanstalkdQueue` deleted by its own generated id instead of the server job
  id, so reserved jobs were never removed and were redelivered after TTR.
  It also never persisted the attempt counter and called `put()` with a single
  argument. All three are fixed.
- `RedisQueue` used a destructive `LPOP` with a no-op `ack()`, silently losing
  jobs on crash. It now uses an atomic Lua claim into a `reserved` set with
  visibility deadlines.
- Redis popped in LIFO order; jobs are now FIFO.
- `Worker` caught `\Exception` only, so `\TypeError`/`\Error` killed the worker.
- `Worker` reported the wrong attempt count (off by one) and requeued nothing.
- Unchecked `json_encode`/`json_decode` could persist `false` or return `null`
  where an array is required.
- `uniqid()` job ids replaced with `random_bytes()`.
- Floats lost their zero fraction through JSON (`3.0` came back as `3`).
- `count()` returned a string from `fetchColumn()`.
- `Drivers/MariDBQueue.php` did not match its class name, so
  `Drivers\MariaDBQueue` could not be autoloaded at all (PSR-4).
- Drivers no longer run DDL on every instantiation; `ensureSchema()` is
  idempotent and can be skipped.
- Added the missing `(queue, status, available_at, created_at)` indexes.

### Added

- Exponential backoff on retries; dead letter store for all backends.
- Delayed jobs on every backend.
- Crash recovery via `requeueExpired()` + `visibilityTimeout`.
- PSR-3 logging, graceful `SIGTERM`/`SIGINT` shutdown.
- 97 tests (unit + SQLite + real Redis/MariaDB/PostgreSQL integration),
  PHPStan level 8, GitHub Actions matrix.

<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Drivers;

use PDO;
use PDOStatement;
use RTIGLOBAL\Phpqdrv\Contracts\JobInterface;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Job;
use RTIGLOBAL\Phpqdrv\Support\Id;
use RTIGLOBAL\Phpqdrv\Support\Json;

/**
 * Shared implementation for the PDO backed drivers.
 *
 * Design notes:
 *  - All timestamps are integer **microseconds** since the epoch. That keeps
 *    ordering precise and avoids every dialect's date/time functions.
 *  - Claiming a job is a `SELECT ... FOR UPDATE SKIP LOCKED` (where available)
 *    followed by a guarded `UPDATE ... WHERE status = 'pending'`. The guard
 *    means a lost race can never hand the same job to two workers, even on
 *    SQLite where row locking does not exist.
 *  - `attempts` is incremented on claim, so `Job::getAttempts()` always reports
 *    the current delivery number.
 *  - DDL is idempotent (`IF NOT EXISTS`) so concurrent workers cannot trip over
 *    each other during bootstrapping.
 */
abstract class AbstractPdoQueue implements QueueInterface
{
    protected const STATUS_PENDING = 'pending';
    protected const STATUS_PROCESSING = 'processing';
    protected const STATUS_COMPLETED = 'completed';
    protected const STATUS_FAILED = 'failed';

    public function __construct(
        protected PDO $pdo,
        bool $ensureSchema = true,
        protected readonly int $visibilityTimeout = 300,
    ) {
        if ($visibilityTimeout < 1) {
            throw new \InvalidArgumentException('visibilityTimeout must be at least 1 second.');
        }

        if ($this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            // Failing silently on a queue write is worse than changing the mode.
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }

        $this->configureConnection();

        if ($ensureSchema) {
            $this->ensureSchema();
        }
    }

    public function push(string $queue, array $payload, int $delay = 0): JobInterface
    {
        $id = Id::generate();
        $now = $this->now();
        $encoded = Json::encode($payload);

        $sql = sprintf(
            'INSERT INTO queue_jobs (id, queue, payload, attempts, status, available_at, reserved_at, created_at, updated_at)
             VALUES (?, ?, %s, 0, ?, ?, NULL, ?, ?)',
            $this->payloadParameter()
        );

        $this->execute($sql, [
            $id,
            $queue,
            $encoded,
            self::STATUS_PENDING,
            $now + $this->secondsToMicros($delay),
            $now,
            $now,
        ]);

        return new Job($id, $queue, $payload, 0);
    }

    public function pop(string $queue): ?JobInterface
    {
        $this->beginTransaction();

        try {
            $row = $this->claim($queue);
            $this->commit();
        } catch (\Throwable $e) {
            $this->rollback();

            throw $e;
        }

        if ($row === null) {
            return null;
        }

        return new Job(
            (string) $row['id'],
            $queue,
            Json::decode((string) $row['payload']),
            (int) $row['attempts'],
        );
    }

    public function ack(JobInterface $job): void
    {
        $this->finish($job, self::STATUS_COMPLETED);
    }

    public function fail(JobInterface $job): void
    {
        $this->finish($job, self::STATUS_FAILED);
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $now = $this->now();

        $this->execute(
            'UPDATE queue_jobs SET status = ?, reserved_at = NULL, available_at = ?, updated_at = ? WHERE id = ?',
            [self::STATUS_PENDING, $now + $this->secondsToMicros($delay), $now, $job->getId()]
        );
    }

    public function size(string $queue): int
    {
        $statement = $this->execute(
            'SELECT COUNT(*) FROM queue_jobs WHERE queue = ? AND status = ?',
            [$queue, self::STATUS_PENDING]
        );

        return (int) $statement->fetchColumn();
    }

    public function requeueExpired(): int
    {
        $now = $this->now();

        $statement = $this->execute(
            'UPDATE queue_jobs SET status = ?, reserved_at = NULL, available_at = ?, updated_at = ?
             WHERE status = ? AND reserved_at IS NOT NULL AND reserved_at <= ?',
            [
                self::STATUS_PENDING,
                $now,
                $now,
                self::STATUS_PROCESSING,
                $now - $this->secondsToMicros($this->visibilityTimeout),
            ]
        );

        return $statement->rowCount();
    }

    /** Create the backing table and indexes if they do not exist yet. */
    public function ensureSchema(): void
    {
        foreach ($this->schemaStatements() as $sql) {
            $this->pdo->exec($sql);
        }
    }

    /** Dialect specific DDL. Every statement must be idempotent. */
    /** @return list<string> */
    abstract protected function schemaStatements(): array;

    /** Hook for connection level pragmas/session settings. */
    protected function configureConnection(): void
    {
    }

    /** Row locking clause appended to the claim query. */
    protected function lockClause(): string
    {
        return '';
    }

    /** Expression used to read the payload column back as a string. */
    protected function payloadSelectExpression(): string
    {
        return 'payload';
    }

    /** Placeholder used when writing the payload column. */
    protected function payloadParameter(): string
    {
        return '?';
    }

    protected function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    protected function commit(): void
    {
        $this->pdo->commit();
    }

    protected function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @return array{id: string, payload: string, attempts: int}|null
     */
    private function claim(string $queue): ?array
    {
        $now = $this->now();

        $select = $this->execute(
            sprintf(
                'SELECT id, %s AS payload, attempts FROM queue_jobs
                 WHERE queue = ? AND status = ? AND available_at <= ?
                 ORDER BY created_at ASC, id ASC
                 LIMIT 1%s',
                $this->payloadSelectExpression(),
                $this->lockClause()
            ),
            [$queue, self::STATUS_PENDING, $now]
        );

        $row = $select->fetch(PDO::FETCH_ASSOC);
        $select->closeCursor();

        if (!is_array($row)) {
            return null;
        }

        $update = $this->execute(
            'UPDATE queue_jobs SET status = ?, attempts = attempts + 1, reserved_at = ?, updated_at = ?
             WHERE id = ? AND status = ?',
            [self::STATUS_PROCESSING, $now, $now, $row['id'], self::STATUS_PENDING]
        );

        if ($update->rowCount() !== 1) {
            // Another worker claimed the row between SELECT and UPDATE.
            return null;
        }

        return [
            'id' => (string) $row['id'],
            'payload' => (string) $row['payload'],
            'attempts' => (int) $row['attempts'] + 1,
        ];
    }

    private function finish(JobInterface $job, string $status): void
    {
        $this->execute(
            'UPDATE queue_jobs SET status = ?, reserved_at = NULL, updated_at = ? WHERE id = ?',
            [$status, $this->now(), $job->getId()]
        );
    }

    /**
     * @param list<mixed> $parameters
     */
    private function execute(string $sql, array $parameters): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);

        return $statement;
    }

    private int $lastTimestamp = 0;

    private function now(): int
    {
        $now = (int) floor(microtime(true) * 1_000_000);

        // `microtime()` can return the same microsecond twice in a tight loop.
        // Guaranteeing a strictly increasing value keeps FIFO ordering stable.
        if ($now <= $this->lastTimestamp) {
            $now = $this->lastTimestamp + 1;
        }

        return $this->lastTimestamp = $now;
    }

    private function secondsToMicros(int $seconds): int
    {
        return max(0, $seconds) * 1_000_000;
    }
}

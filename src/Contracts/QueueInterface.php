<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Contracts;

interface QueueInterface
{
    /**
     * Append a job to a queue.
     *
     * @param array<array-key, mixed> $payload
     * @param int                     $delay   Seconds to wait before the job becomes available.
     */
    public function push(string $queue, array $payload, int $delay = 0): JobInterface;

    /**
     * Reserve the next available job, or null when the queue is empty.
     *
     * Reserving a job makes it invisible to other workers until it is acked,
     * failed or released. If the worker dies, {@see self::requeueExpired()}
     * returns the job to the queue.
     */
    public function pop(string $queue): ?JobInterface;

    /** Confirm the job finished successfully. Idempotent. */
    public function ack(JobInterface $job): void;

    /** Park the job in the dead letter store. Idempotent. */
    public function fail(JobInterface $job): void;

    /**
     * Return a reserved job to its queue for another delivery.
     *
     * @param int $delay Seconds to wait before it becomes available again.
     */
    public function release(JobInterface $job, int $delay = 0): void;

    /** Number of jobs waiting to be processed, including delayed ones. */
    public function size(string $queue): int;

    /**
     * Recover jobs that were reserved by a worker that never came back.
     *
     * Drivers that handle this natively (Beanstalkd TTR) return 0.
     *
     * @return int Number of jobs returned to the queue.
     */
    public function requeueExpired(): int;
}

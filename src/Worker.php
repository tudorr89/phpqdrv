<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RTIGLOBAL\Phpqdrv\Contracts\JobInterface;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;

/**
 * Pulls jobs off a queue and hands their payloads to a processor callable.
 *
 * Failure policy: a job that throws is released back to the queue with an
 * exponential backoff until `$maxAttempts` deliveries have been used up, at
 * which point it is parked with {@see QueueInterface::fail()}. Nothing is ever
 * silently dropped.
 */
final class Worker
{
    /** Upper bound of the exponential backoff, in seconds. */
    private const MAX_BACKOFF = 300;

    /** Seconds between crash-recovery sweeps. */
    private const SWEEP_INTERVAL = 30;

    private readonly LoggerInterface $logger;

    private bool $stopRequested = false;

    private bool $signalsInstalled = false;

    private float $lastSweepAt = 0.0;

    public function __construct(
        private readonly QueueInterface $queue,
        private readonly int $maxAttempts = 3,
        private readonly int $sleepSeconds = 1,
        ?LoggerInterface $logger = null,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($sleepSeconds < 0) {
            throw new \InvalidArgumentException('sleepSeconds must not be negative.');
        }

        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Process jobs until stopped or `$maxJobs` jobs have been handled.
     *
     * @param callable(array<array-key, mixed>): mixed $processor
     *
     * @return int Number of jobs handed to the processor.
     */
    public function work(string $queue, callable $processor, int $maxJobs = PHP_INT_MAX): int
    {
        $this->installSignalHandlers();
        $this->sweep(force: true);

        $processed = 0;

        while (!$this->stopRequested && $processed < $maxJobs) {
            try {
                if ($this->processNext($queue, $processor)) {
                    ++$processed;

                    continue;
                }

                $this->sweep();
                $this->sleep();
            } catch (\Throwable $e) {
                // Only infrastructure failures reach this point: job errors are
                // handled inside processNext().
                $this->logger->error('Queue worker error', [
                    'queue' => $queue,
                    'exception' => $e,
                ]);

                $this->sleep();
            }
        }

        return $processed;
    }

    /**
     * Reserve and process a single job.
     *
     * @param callable(array<array-key, mixed>): mixed $processor
     *
     * @return bool False when the queue was empty.
     */
    public function processNext(string $queue, callable $processor): bool
    {
        $job = $this->queue->pop($queue);

        if ($job === null) {
            return false;
        }

        try {
            $processor($job->getPayload());
            $this->queue->ack($job);

            $this->logger->info('Job processed', [
                'id' => $job->getId(),
                'queue' => $job->getQueue(),
                'attempt' => $job->getAttempts(),
            ]);
        } catch (\Throwable $e) {
            $this->handleFailure($job, $e);
        }

        return true;
    }

    /** Ask the worker to stop after the job currently in flight. */
    public function stop(): void
    {
        $this->stopRequested = true;
    }

    public function isStopping(): bool
    {
        return $this->stopRequested;
    }

    private function handleFailure(JobInterface $job, \Throwable $e): void
    {
        $this->logger->error('Job failed', [
            'id' => $job->getId(),
            'queue' => $job->getQueue(),
            'attempt' => $job->getAttempts(),
            'maxAttempts' => $this->maxAttempts,
            'exception' => $e,
        ]);

        if ($job->getAttempts() >= $this->maxAttempts) {
            $this->queue->fail($job);

            return;
        }

        $delay = $this->backoffSeconds($job->getAttempts());

        $this->queue->release($job, $delay);

        $this->logger->warning('Job released for retry', [
            'id' => $job->getId(),
            'queue' => $job->getQueue(),
            'delay' => $delay,
        ]);
    }

    /** 1, 2, 4, 8, ... seconds, capped at MAX_BACKOFF. */
    private function backoffSeconds(int $attempts): int
    {
        return (int) min(self::MAX_BACKOFF, 2 ** max(0, $attempts - 1));
    }

    private function sweep(bool $force = false): void
    {
        $now = microtime(true);

        if (!$force && ($now - $this->lastSweepAt) < self::SWEEP_INTERVAL) {
            return;
        }

        $this->lastSweepAt = $now;

        try {
            $requeued = $this->queue->requeueExpired();

            if ($requeued > 0) {
                $this->logger->notice('Requeued expired jobs', ['count' => $requeued]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Unable to requeue expired jobs', ['exception' => $e]);
        }
    }

    private function sleep(): void
    {
        // A zero sleep would spin a CPU while an idle queue is polled; keep a
        // small floor so tests stay fast without pegging a core.
        $microseconds = max(1, $this->sleepSeconds) * 1_000_000;

        usleep($this->sleepSeconds === 0 ? 50_000 : $microseconds);
    }

    private function installSignalHandlers(): void
    {
        if ($this->signalsInstalled || !function_exists('pcntl_async_signals')) {
            return;
        }

        $this->signalsInstalled = true;
        pcntl_async_signals(true);

        $handler = function (): void {
            $this->stop();
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
    }
}

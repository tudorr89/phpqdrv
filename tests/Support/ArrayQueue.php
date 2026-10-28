<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Support;

use RTIGLOBAL\Phpqdrv\Contracts\JobInterface;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Job;

/**
 * In-memory queue used to exercise the worker without any infrastructure.
 *
 * It models the redis semantics the worker relies on: `pop()` increments the
 * attempt counter and `release()` puts the job back with the counter it had.
 */
final class ArrayQueue implements QueueInterface
{
    /** @var array<string, list<array{payload: array<array-key, mixed>, attempts: int}>> */
    private array $queues = [];

    /** @var list<JobInterface> */
    public array $acked = [];

    /** @var list<JobInterface> */
    public array $failed = [];

    /** @var list<array{job: JobInterface, delay: int}> */
    public array $released = [];

    public int $pops = 0;

    public int $requeueCalls = 0;

    public ?\Throwable $popError = null;

    private int $sequence = 0;

    public function push(string $queue, array $payload, int $delay = 0): JobInterface
    {
        $this->queues[$queue][] = ['payload' => $payload, 'attempts' => 0];

        return new Job('job-' . (++$this->sequence), $queue, $payload, 0);
    }

    public function pop(string $queue): ?JobInterface
    {
        if ($this->popError !== null) {
            $error = $this->popError;
            $this->popError = null;

            throw $error;
        }

        ++$this->pops;

        if (!isset($this->queues[$queue]) || $this->queues[$queue] === []) {
            return null;
        }

        $entry = array_shift($this->queues[$queue]);

        if ($entry === null) {
            return null;
        }

        return new Job(
            'job-' . $this->sequence,
            $queue,
            $entry['payload'],
            $entry['attempts'] + 1
        );
    }

    public function ack(JobInterface $job): void
    {
        $this->acked[] = $job;
    }

    public function fail(JobInterface $job): void
    {
        $this->failed[] = $job;
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $this->released[] = ['job' => $job, 'delay' => $delay];

        $this->queues[$job->getQueue()][] = [
            'payload' => $job->getPayload(),
            'attempts' => $job->getAttempts(),
        ];
    }

    public function size(string $queue): int
    {
        return count($this->queues[$queue] ?? []);
    }

    public function requeueExpired(): int
    {
        ++$this->requeueCalls;

        return 0;
    }
}

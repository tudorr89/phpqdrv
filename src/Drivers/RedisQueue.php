<?php

namespace Tudorr89\Phpqdrv\Drivers;

use Predis\Client;
use Tudorr89\Phpqdrv\Contracts\JobInterface;
use Tudorr89\Phpqdrv\Contracts\QueueInterface;
use Tudorr89\Phpqdrv\Job;

class RedisQueue implements QueueInterface
{
    private Client $redis;

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
    }

    public function push(string $queue, array $payload): JobInterface
    {
        $jobId = uniqid('job_', true);
        $job = new Job($jobId, $queue, $payload);

        $this->redis->rpush("queue:{$queue}", json_encode([
            'id' => $jobId,
            'payload' => $payload,
            'attempts' => 0
        ]));

        return $job;
    }

    public function pop(string $queue): ?JobInterface
    {
        $rawJob = $this->redis->lpop("queue:{$queue}");
        if (!$rawJob) {
            return null;
        }

        $jobData = json_decode($rawJob, true);
        return new Job(
            $jobData['id'],
            $queue,
            $jobData['payload'],
            $jobData['attempts'] ?? 0
        );
    }

    public function ack(JobInterface $job): void
    {
        // Optional: Could implement additional logging or cleanup
    }

    public function fail(JobInterface $job): void
    {
        $this->redis->rpush("failed_queue:{$job->getQueue()}", json_encode([
            'id' => $job->getId(),
            'payload' => $job->getPayload(),
            'failed_at' => time()
        ]));
    }

    public function count(string $queue): int
    {
        return $this->redis->llen("queue:{$queue}");
    }
}
<?php

namespace Tudorr89\Phpqdrv\Drivers;

use Net_Beanstalkd;
use Tudorr89\Phpqdrv\Contracts\JobInterface;
use Tudorr89\Phpqdrv\Contracts\QueueInterface;
use Tudorr89\Phpqdrv\Job;

class BeanstalkdQueue implements QueueInterface
{
    private Net_Beanstalkd $connection;

    public function __construct(Net_Beanstalkd $connection)
    {
        $this->connection = $connection;
    }

    public function push(string $queue, array $payload): JobInterface
    {
        // Ensure the tube (queue) exists
        $this->connection->useTube($queue);

        $jobId = uniqid('job_', true);

        // Create job data including metadata
        $jobData = [
            'id' => $jobId,
            'payload' => $payload,
            'attempts' => 0
        ];

        // Put the job in the queue with a default priority and delay
        $beanstalkdJobId = $this->connection->put(
            json_encode($jobData),
            1024, // priority (lower number = higher priority)
            0,    // delay in seconds
            120   // time to run (TTR) in seconds
        );

        return new Job($jobId, $queue, $payload);
    }

    public function pop(string $queue): ?JobInterface
    {
        // Watch the specific tube (queue)
        $this->connection->watch($queue);
        $this->connection->ignore('default');

        // Reserve a job
        $beanstalkdJob = $this->connection->reserve(1); // 1-second timeout

        if (!$beanstalkdJob) {
            return null;
        }

        // Decode the job data
        $jobData = json_decode($beanstalkdJob->getData(), true);

        // Increment attempts
        $jobData['attempts']++;

        return new Job(
            $jobData['id'],
            $queue,
            $jobData['payload'],
            $jobData['attempts']
        );
    }

    public function ack(JobInterface $job): void
    {
        try {
            $this->connection->delete($job->getId());
        } catch (\Exception $e) {
            // Job might have already been deleted or doesn't exist
        }
    }

    public function fail(JobInterface $job): void
    {
        try {
            // Move to a failed tube/queue
            $this->connection->useTube("failed_{$job->getQueue()}");
            $this->connection->put(
                json_encode([
                    'original_id' => $job->getId(),
                    'payload' => $job->getPayload(),
                    'failed_at' => time()
                ])
            );

            // Delete the original job
            $this->connection->delete($job->getId());
        } catch (\Exception $e) {
            // Handle potential errors during failure processing
        }
    }

    public function count(string $queue): int
    {
        // Watch the tube to get its stats
        $this->connection->watch($queue);
        $stats = $this->connection->statsTube($queue);

        return $stats['current-jobs-ready'] ?? 0;
    }
}
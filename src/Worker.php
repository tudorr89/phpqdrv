<?php

namespace Tudorr89\Phpqdrv;

use Tudorr89\Phpqdrv\Contracts\QueueInterface;

class Worker
{
    private QueueInterface $queue;
    private int $maxAttempts;
    private int $sleepTime;

    public function __construct(
        QueueInterface $queue,
        int $maxAttempts = 3,
        int $sleepTime = 5
    ) {
        $this->queue = $queue;
        $this->maxAttempts = $maxAttempts;
        $this->sleepTime = $sleepTime;
    }

    public function work(string $queue, callable $processor)
    {
        while (true) {
            try {
                $job = $this->queue->pop($queue);

                if (!$job) {
                    sleep($this->sleepTime);
                    continue;
                }

                try {
                    $result = $processor($job->getPayload());
                    $this->queue->ack($job);
                } catch (\Exception $e) {
                    if ($job->getAttempts() >= $this->maxAttempts) {
                        $this->queue->fail($job);
                        // Optional: log the failure
                        continue;
                    }
                    // Requeue or handle retry logic
                    throw $e;
                }
            } catch (\Exception $e) {
                // Log error, potentially send to error tracking
                error_log($e->getMessage());
                sleep($this->sleepTime);
            }
        }
    }
}
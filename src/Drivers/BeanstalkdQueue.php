<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Drivers;

use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdConnectionInterface;
use RTIGLOBAL\Phpqdrv\Contracts\JobInterface;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Exception\InvalidPayloadException;
use RTIGLOBAL\Phpqdrv\Exception\UnknownJobException;
use RTIGLOBAL\Phpqdrv\Job;
use RTIGLOBAL\Phpqdrv\Support\Id;
use RTIGLOBAL\Phpqdrv\Support\Json;

/**
 * Beanstalkd backed queue.
 *
 * Beanstalkd addresses jobs by a server assigned integer id, while the queue
 * package exposes string job ids. The mapping is kept in memory, which mirrors
 * how Beanstalkd is meant to be used: a job is reserved, handled and deleted by
 * the same process. A job reserved by a process that then dies is recovered by
 * the server itself once its TTR expires, hence `requeueExpired()` is a no-op.
 *
 * Because `release` cannot change a job body, retrying deletes the reserved job
 * and publishes a new one carrying the incremented attempt counter.
 */
final class BeanstalkdQueue implements QueueInterface
{
    /** @var array<string, string> package job id => server job id */
    private array $handles = [];

    public function __construct(
        private readonly BeanstalkdConnectionInterface $connection,
        private readonly int $priority = 1024,
        private readonly int $ttr = 120,
        private readonly int $reserveTimeout = 1,
        private readonly string $failedTubeSuffix = '.failed',
    ) {
        if ($ttr < 1) {
            throw new \InvalidArgumentException('ttr must be at least 1 second.');
        }

        if ($reserveTimeout < 0) {
            throw new \InvalidArgumentException('reserveTimeout must not be negative.');
        }
    }

    public function push(string $queue, array $payload, int $delay = 0): JobInterface
    {
        $id = Id::generate();
        $body = $this->encode($id, 0, $payload);

        $this->handles[$id] = $this->connection->putInTube(
            $queue,
            $body,
            $this->priority,
            max(0, $delay),
            $this->ttr
        );

        return new Job($id, $queue, $payload, 0, $body);
    }

    public function pop(string $queue): ?JobInterface
    {
        $reserved = $this->connection->reserveFromTube($queue, $this->reserveTimeout);

        if ($reserved === null) {
            return null;
        }

        $raw = $reserved->getData();

        try {
            $data = Json::decode($raw);
            $payload = $data['payload'] ?? null;

            if (!is_array($payload)) {
                throw new InvalidPayloadException('Beanstalkd job body is missing an array payload.');
            }
        } catch (InvalidPayloadException $e) {
            // Never leave a corrupt job reserved: it would be redelivered forever.
            $this->connection->delete($reserved->getId());

            throw $e;
        }

        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : $reserved->getId();

        $this->handles[$id] = $reserved->getId();

        return new Job($id, $queue, $payload, (int) ($data['attempts'] ?? 0) + 1, $raw);
    }

    public function ack(JobInterface $job): void
    {
        $this->connection->delete($this->handle($job));
        unset($this->handles[$job->getId()]);
    }

    public function fail(JobInterface $job): void
    {
        $this->connection->putInTube(
            $job->getQueue() . $this->failedTubeSuffix,
            Json::encode([
                'original_id' => $job->getId(),
                'payload' => $job->getPayload(),
                'attempts' => $job->getAttempts(),
                'failed_at' => time(),
            ]),
            $this->priority,
            0,
            $this->ttr
        );

        $this->connection->delete($this->handle($job));
        unset($this->handles[$job->getId()]);
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $serverId = $this->handle($job);

        $this->connection->delete($serverId);

        $this->handles[$job->getId()] = $this->connection->putInTube(
            $job->getQueue(),
            $this->encode($job->getId(), $job->getAttempts(), $job->getPayload()),
            $this->priority,
            max(0, $delay),
            $this->ttr
        );
    }

    public function size(string $queue): int
    {
        return $this->connection->tubeSize($queue);
    }

    public function requeueExpired(): int
    {
        // The server releases jobs whose TTR expired on its own.
        return 0;
    }

    private function handle(JobInterface $job): string
    {
        if (!isset($this->handles[$job->getId()])) {
            throw new UnknownJobException(sprintf(
                'No Beanstalkd handle known for job "%s". Jobs can only be acked, failed or released by the process that reserved them.',
                $job->getId()
            ));
        }

        return $this->handles[$job->getId()];
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function encode(string $id, int $attempts, array $payload): string
    {
        return Json::encode([
            'id' => $id,
            'attempts' => $attempts,
            'payload' => $payload,
        ]);
    }
}

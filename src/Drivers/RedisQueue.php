<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Drivers;

use RTIGLOBAL\Phpqdrv\Contracts\JobInterface;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Contracts\RedisClientInterface;
use RTIGLOBAL\Phpqdrv\Exception\InvalidPayloadException;
use RTIGLOBAL\Phpqdrv\Job;
use RTIGLOBAL\Phpqdrv\Support\Id;
use RTIGLOBAL\Phpqdrv\Support\Json;

/**
 * Redis backed reliable queue.
 *
 * Layout (all keys share a configurable prefix):
 *   {prefix}:{queue}:ready    LIST    jobs waiting to be claimed
 *   {prefix}:{queue}:delayed  ZSET    score = unix time the job becomes ready
 *   {prefix}:{queue}:failed   LIST    dead letter store
 *   {prefix}:reserved         ZSET    score = visibility deadline, one set for
 *                                     every queue so crash recovery can scan a
 *                                     single key
 *
 * `pop()` copies the body into `reserved` before handing it out, so a worker
 * that dies never loses a job: {@see self::requeueExpired()} puts it back.
 * Every state transition is a Lua script, so no two clients can interleave.
 */
final class RedisQueue implements QueueInterface
{
    private const READY = ':ready';
    private const DELAYED = ':delayed';
    private const FAILED = ':failed';

    /**
     * Atomically claim the next ready job, promoting due delayed jobs first.
     *
     * KEYS: 1 = ready, 2 = reserved, 3 = delayed
     * ARGV: 1 = now, 2 = visibility timeout
     */
    private const POP_SCRIPT = <<<'LUA'
        local raw = redis.call('LPOP', KEYS[1])

        if not raw then
            local due = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', ARGV[1], 'LIMIT', 0, 100)

            for i = 1, #due do
                if redis.call('ZREM', KEYS[3], due[i]) == 1 then
                    redis.call('RPUSH', KEYS[1], due[i])
                end
            end

            raw = redis.call('LPOP', KEYS[1])
        end

        if not raw then
            return false
        end

        redis.call('ZADD', KEYS[2], tonumber(ARGV[1]) + tonumber(ARGV[2]), raw)

        return raw
        LUA;

    /**
     * KEYS: 1 = reserved, ARGV: 1 = reserved body
     */
    private const ACK_SCRIPT = <<<'LUA'
        return redis.call('ZREM', KEYS[1], ARGV[1])
        LUA;

    /**
     * KEYS: 1 = reserved, 2 = failed, ARGV: 1 = reserved body
     */
    private const FAIL_SCRIPT = <<<'LUA'
        redis.call('ZREM', KEYS[1], ARGV[1])
        redis.call('RPUSH', KEYS[2], ARGV[1])

        return 1
        LUA;

    /**
     * KEYS: 1 = reserved, 2 = delayed, 3 = ready
     * ARGV: 1 = reserved body, 2 = body to store, 3 = delay, 4 = now
     */
    private const RELEASE_SCRIPT = <<<'LUA'
        redis.call('ZREM', KEYS[1], ARGV[1])

        if tonumber(ARGV[3]) > 0 then
            redis.call('ZADD', KEYS[2], tonumber(ARGV[4]) + tonumber(ARGV[3]), ARGV[2])
        else
            redis.call('RPUSH', KEYS[3], ARGV[2])
        end

        return 1
        LUA;

    /**
     * KEYS: 1 = delayed, ARGV: 1 = body, 2 = ready at
     */
    private const DELAY_SCRIPT = <<<'LUA'
        return redis.call('ZADD', KEYS[1], ARGV[2], ARGV[1])
        LUA;

    /**
     * KEYS: 1 = reserved, ARGV: 1 = cutoff
     */
    private const EXPIRED_SCRIPT = <<<'LUA'
        return redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1])
        LUA;

    public function __construct(
        private readonly RedisClientInterface $client,
        private readonly int $visibilityTimeout = 300,
        private readonly string $prefix = 'phpqdrv',
    ) {
        if ($visibilityTimeout < 1) {
            throw new \InvalidArgumentException('visibilityTimeout must be at least 1 second.');
        }
    }

    public function push(string $queue, array $payload, int $delay = 0): JobInterface
    {
        $id = Id::generate();
        $body = $this->encode($id, $queue, 0, $payload);

        if ($delay > 0) {
            $this->client->eval(
                self::DELAY_SCRIPT,
                [$this->key($queue, self::DELAYED)],
                [$body, (string) ($this->now() + $delay)]
            );
        } else {
            $this->client->rpush($this->key($queue, self::READY), $body);
        }

        return new Job($id, $queue, $payload, 0, $body);
    }

    public function pop(string $queue): ?JobInterface
    {
        $raw = $this->client->eval(
            self::POP_SCRIPT,
            [$this->key($queue, self::READY), $this->reservedKey(), $this->key($queue, self::DELAYED)],
            [(string) $this->now(), (string) $this->visibilityTimeout]
        );

        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $data = $this->decode($raw);

        return new Job(
            (string) $data['id'],
            (string) $data['queue'],
            $data['payload'],
            (int) $data['attempts'] + 1,
            $raw,
        );
    }

    public function ack(JobInterface $job): void
    {
        $this->client->eval(self::ACK_SCRIPT, [$this->reservedKey()], [$this->body($job)]);
    }

    public function fail(JobInterface $job): void
    {
        $this->client->eval(
            self::FAIL_SCRIPT,
            [$this->reservedKey(), $this->key($job->getQueue(), self::FAILED)],
            [$this->body($job)]
        );
    }

    public function release(JobInterface $job, int $delay = 0): void
    {
        $this->client->eval(
            self::RELEASE_SCRIPT,
            [
                $this->reservedKey(),
                $this->key($job->getQueue(), self::DELAYED),
                $this->key($job->getQueue(), self::READY),
            ],
            [
                $this->body($job),
                $this->encode($job->getId(), $job->getQueue(), $job->getAttempts(), $job->getPayload()),
                (string) max(0, $delay),
                (string) $this->now(),
            ]
        );
    }

    public function size(string $queue): int
    {
        return $this->client->llen($this->key($queue, self::READY));
    }

    public function requeueExpired(): int
    {
        $expired = $this->client->eval(
            self::EXPIRED_SCRIPT,
            [$this->reservedKey()],
            [(string) ($this->now() - $this->visibilityTimeout)]
        );

        if (!is_array($expired)) {
            return 0;
        }

        $requeued = 0;

        foreach ($expired as $raw) {
            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $data = $this->decode($raw);
            $queue = (string) $data['queue'];

            $this->client->eval(
                self::RELEASE_SCRIPT,
                [$this->reservedKey(), $this->key($queue, self::DELAYED), $this->key($queue, self::READY)],
                [
                    $raw,
                    $this->encode((string) $data['id'], $queue, (int) $data['attempts'] + 1, $data['payload']),
                    '0',
                    (string) $this->now(),
                ]
            );

            ++$requeued;
        }

        return $requeued;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function encode(string $id, string $queue, int $attempts, array $payload): string
    {
        return Json::encode([
            'id' => $id,
            'queue' => $queue,
            'attempts' => $attempts,
            'payload' => $payload,
        ]);
    }

    /**
     * @return array{id: string, queue: string, attempts: int, payload: array<array-key, mixed>}
     */
    private function decode(string $raw): array
    {
        $data = Json::decode($raw);

        foreach (['id', 'queue'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new InvalidPayloadException(sprintf('Redis job body is missing the "%s" field.', $field));
            }
        }

        $attempts = $data['attempts'] ?? 0;

        if (!is_int($attempts)) {
            throw new InvalidPayloadException('Redis job body has a non integer "attempts" field.');
        }

        if (!isset($data['payload']) || !is_array($data['payload'])) {
            throw new InvalidPayloadException('Redis job body is missing an array "payload" field.');
        }

        /** @var array{id: string, queue: string, attempts: int, payload: array<array-key, mixed>} $data */
        return $data;
    }

    private function body(JobInterface $job): string
    {
        if ($job instanceof Job && $job->getRaw() !== null) {
            return $job->getRaw();
        }

        return $this->encode($job->getId(), $job->getQueue(), $job->getAttempts() - 1, $job->getPayload());
    }

    private function key(string $queue, string $suffix): string
    {
        return $this->prefix . ':' . $queue . $suffix;
    }

    private function reservedKey(): string
    {
        return $this->prefix . ':reserved';
    }

    private function now(): int
    {
        return time();
    }
}

<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Drivers;

use PHPUnit\Framework\TestCase;
use Predis\Client;
use RTIGLOBAL\Phpqdrv\Adapters\PredisAdapter;
use RTIGLOBAL\Phpqdrv\Drivers\RedisQueue;

final class RedisQueueTest extends TestCase
{
    private const PREFIX = 'phpqdrv-test';

    private Client $redis;

    private RedisQueue $queue;

    protected function setUp(): void
    {
        $dsn = getenv('QDRV_REDIS_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set QDRV_REDIS_DSN to run Redis tests, e.g. "redis://127.0.0.1:6379".');
        }

        try {
            $this->redis = new Client($dsn);
            $this->redis->ping();
        } catch (\Throwable $e) {
            self::markTestSkipped('Redis is not reachable: ' . $e->getMessage());
        }

        $this->flush();
        $this->queue = new RedisQueue(new PredisAdapter($this->redis), 300, self::PREFIX);
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->flush();
        }
    }

    public function testPushAndPopRoundTripsThePayload(): void
    {
        $payload = ['string' => 'héllo', 'int' => 1, 'bool' => true, 'null' => null, 'nested' => ['a' => [1, 2]]];

        $pushed = $this->queue->push('emails', $payload);
        $popped = $this->queue->pop('emails');

        self::assertNotNull($popped);
        self::assertSame($pushed->getId(), $popped->getId());
        self::assertSame($payload, $popped->getPayload());
        self::assertSame(1, $popped->getAttempts());
    }

    public function testPopReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->queue->pop('emails'));
    }

    public function testJobsAreClaimedInPushOrder(): void
    {
        $ids = [
            $this->queue->push('emails', ['n' => 1])->getId(),
            $this->queue->push('emails', ['n' => 2])->getId(),
            $this->queue->push('emails', ['n' => 3])->getId(),
        ];

        self::assertSame($ids[0], $this->queue->pop('emails')?->getId());
        self::assertSame($ids[1], $this->queue->pop('emails')?->getId());
        self::assertSame($ids[2], $this->queue->pop('emails')?->getId());
    }

    public function testAckDropsTheReservation(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        self::assertSame(1, (int) $this->redis->zcard(self::PREFIX . ':reserved'));

        $this->queue->ack($job);

        self::assertSame(0, (int) $this->redis->zcard(self::PREFIX . ':reserved'));
        self::assertSame(0, $this->queue->size('emails'));
    }

    public function testFailMovesTheJobToTheDeadLetterList(): void
    {
        $this->queue->push('emails', ['x' => 1]);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->fail($job);

        self::assertSame(0, (int) $this->redis->zcard(self::PREFIX . ':reserved'));
        self::assertSame(1, (int) $this->redis->llen(self::PREFIX . ':emails:failed'));
    }

    public function testReleaseMakesTheJobAvailableAgainWithABumpedAttempt(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->release($job);

        $retried = $this->queue->pop('emails');
        self::assertNotNull($retried);
        self::assertSame($job->getId(), $retried->getId());
        self::assertSame(2, $retried->getAttempts());
    }

    public function testDelayedJobsArePromotedOnceDue(): void
    {
        $job = $this->queue->push('emails', ['delayed' => true], delay: 60);

        self::assertNull($this->queue->pop('emails'));

        // Pretend the delay elapsed.
        $this->redis->zadd(self::PREFIX . ':emails:delayed', [$this->redis->zrange(self::PREFIX . ':emails:delayed', 0, 0)[0] => 0]);

        $promoted = $this->queue->pop('emails');
        self::assertNotNull($promoted);
        self::assertSame($job->getId(), $promoted->getId());
        self::assertSame(1, $promoted->getAttempts());
    }

    public function testRequeueExpiredRecoversJobsFromDeadWorkers(): void
    {
        $this->queue->push('emails', ['recovered' => true]);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);

        // Age the reservation past the visibility timeout.
        $members = $this->redis->zrange(self::PREFIX . ':reserved', 0, -1);
        self::assertCount(1, $members);
        $this->redis->zadd(self::PREFIX . ':reserved', [$members[0] => 1]);

        self::assertSame(1, $this->queue->requeueExpired());

        $recovered = $this->queue->pop('emails');
        self::assertNotNull($recovered);
        self::assertSame($job->getId(), $recovered->getId());
        self::assertSame(2, $recovered->getAttempts());
    }

    public function testRequeueExpiredLeavesHealthyJobsAlone(): void
    {
        $this->queue->push('emails', []);
        $this->queue->pop('emails');

        self::assertSame(0, $this->queue->requeueExpired());
    }

    public function testQueuesAreIsolated(): void
    {
        $this->queue->push('emails', ['q' => 'emails']);
        $this->queue->push('reports', ['q' => 'reports']);

        self::assertSame('emails', $this->queue->pop('emails')?->getPayload()['q']);
        self::assertSame('reports', $this->queue->pop('reports')?->getPayload()['q']);
    }

    private function flush(): void
    {
        $keys = $this->redis->keys(self::PREFIX . '*');

        if ($keys !== []) {
            $this->redis->del($keys);
        }
    }
}

<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Drivers;

use PDO;
use PHPUnit\Framework\TestCase;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Drivers\AbstractPdoQueue;
use RTIGLOBAL\Phpqdrv\Exception\InvalidPayloadException;

/**
 * Behaviour every PDO backed driver must share.
 */
abstract class AbstractPdoQueueTestCase extends TestCase
{
    protected PDO $pdo;

    protected AbstractPdoQueue $queue;

    abstract protected function createPdo(): PDO;

    abstract protected function createQueue(PDO $pdo): AbstractPdoQueue;

    protected function setUp(): void
    {
        $this->pdo = $this->createPdo();
        $this->dropSchema();
        $this->queue = $this->createQueue($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->dropSchema();
        }
    }

    public function testPushAndPopRoundTripsThePayload(): void
    {
        $payload = [
            'string' => 'héllo "quoted" / slashed',
            'int' => 42,
            'float' => 1.5,
            'bool' => true,
            'null' => null,
            'nested' => ['a' => ['b' => [1, 2, 3]]],
            'list' => [1, 'two', 3.0],
        ];

        $pushed = $this->queue->push('emails', $payload);
        $popped = $this->queue->pop('emails');

        self::assertNotNull($popped);
        self::assertSame($pushed->getId(), $popped->getId());
        self::assertSame('emails', $popped->getQueue());
        self::assertSame($payload, $popped->getPayload());
        self::assertSame(1, $popped->getAttempts());
    }

    public function testPushReturnsAFreshJob(): void
    {
        $job = $this->queue->push('emails', ['a' => 1]);

        self::assertSame(0, $job->getAttempts());
        self::assertSame(1, $this->queue->size('emails'));
    }

    public function testPopReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->queue->pop('emails'));
    }

    public function testJobsAreClaimedInPushOrder(): void
    {
        $first = $this->queue->push('emails', ['n' => 1]);
        $second = $this->queue->push('emails', ['n' => 2]);
        $third = $this->queue->push('emails', ['n' => 3]);

        $ids = [];

        while (($job = $this->queue->pop('emails')) !== null) {
            $ids[] = $job->getId();
        }

        self::assertSame(
            [$first->getId(), $second->getId(), $third->getId()],
            $ids
        );
    }

    public function testQueuesAreIsolated(): void
    {
        $this->queue->push('emails', ['q' => 'emails']);
        $this->queue->push('reports', ['q' => 'reports']);

        self::assertSame(1, $this->queue->size('emails'));
        self::assertSame(1, $this->queue->size('reports'));
        self::assertSame('emails', $this->queue->pop('emails')?->getPayload()['q']);
        self::assertSame('reports', $this->queue->pop('reports')?->getPayload()['q']);
    }

    public function testAttemptsIncreaseOnEveryDelivery(): void
    {
        $this->queue->push('emails', []);

        $first = $this->queue->pop('emails');
        self::assertSame(1, $first?->getAttempts());
        $this->queue->release($first);

        $second = $this->queue->pop('emails');
        self::assertSame(2, $second?->getAttempts());
        self::assertSame($first?->getId(), $second?->getId());
        $this->queue->release($second);

        $third = $this->queue->pop('emails');
        self::assertSame(3, $third?->getAttempts());
    }

    public function testAckRemovesTheJobFromTheQueue(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->ack($job);

        self::assertSame(0, $this->queue->size('emails'));
        self::assertNull($this->queue->pop('emails'));
        self::assertSame('completed', $this->statusOf($job->getId()));
    }

    public function testAckIsIdempotent(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->ack($job);
        $this->queue->ack($job);

        self::assertSame('completed', $this->statusOf($job->getId()));
    }

    public function testFailParksTheJob(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->fail($job);

        self::assertSame('failed', $this->statusOf($job->getId()));
        self::assertSame(0, $this->queue->size('emails'));
        self::assertNull($this->queue->pop('emails'));
    }

    public function testReleaseMakesTheJobAvailableAgain(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        self::assertSame(0, $this->queue->size('emails'));

        $this->queue->release($job);

        self::assertSame(1, $this->queue->size('emails'));
        self::assertNotNull($this->queue->pop('emails'));
    }

    public function testDelayedJobsAreNotAvailableImmediately(): void
    {
        $this->queue->push('emails', ['delayed' => true], delay: 60);

        self::assertNull($this->queue->pop('emails'));
        self::assertSame(1, $this->queue->size('emails'), 'delayed jobs still count as queued');

        $this->rewind($this->firstJobId(), 'available_at');

        self::assertNotNull($this->queue->pop('emails'));
    }

    public function testReleasedJobHonoursTheDelay(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->release($job, delay: 60);

        self::assertNull($this->queue->pop('emails'));

        $this->rewind($job->getId(), 'available_at');

        self::assertNotNull($this->queue->pop('emails'));
    }

    public function testRequeueExpiredRecoversJobsFromDeadWorkers(): void
    {
        $this->queue->push('emails', ['recovered' => true]);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->rewind($job->getId(), 'reserved_at');

        self::assertSame(1, $this->queue->requeueExpired());

        $recovered = $this->queue->pop('emails');
        self::assertNotNull($recovered);
        self::assertSame($job->getId(), $recovered->getId());
        self::assertSame(2, $recovered->getAttempts());
    }

    public function testRequeueExpiredLeavesHealthyJobsAlone(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);

        self::assertSame(0, $this->queue->requeueExpired());
        self::assertNull($this->queue->pop('emails'));
    }

    public function testSchemaCreationIsIdempotent(): void
    {
        $this->queue->ensureSchema();
        $this->queue->ensureSchema();

        $this->queue->push('emails', []);

        self::assertSame(1, $this->queue->size('emails'));
    }

    public function testSchemaCanBeSkipped(): void
    {
        $queue = $this->createQueueWithoutSchema($this->pdo);

        self::assertInstanceOf(QueueInterface::class, $queue);
        self::assertSame(0, $queue->size('emails'));
    }

    public function testInvalidUtf8PayloadIsRejected(): void
    {
        $this->expectException(InvalidPayloadException::class);

        $this->queue->push('emails', ['bad' => "\xB1\x31"]);
    }

    public function testCorruptStoredPayloadIsRejected(): void
    {
        $this->queue->push('emails', []);
        $id = $this->firstJobId();

        $this->pdo->prepare('UPDATE queue_jobs SET payload = ? WHERE id = ?')
            ->execute([$this->corruptPayloadLiteral(), $id]);

        $this->expectException(InvalidPayloadException::class);
        $this->queue->pop('emails');
    }

    abstract protected function createQueueWithoutSchema(PDO $pdo): QueueInterface;

    protected function dropSchema(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS queue_jobs');
    }

    protected function statusOf(string $id): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM queue_jobs WHERE id = ?');
        $statement->execute([$id]);
        $status = $statement->fetchColumn();

        return is_string($status) ? $status : null;
    }

    protected function firstJobId(): string
    {
        $id = $this->pdo->query('SELECT id FROM queue_jobs')->fetchColumn();

        self::assertIsString($id);

        return $id;
    }

    /** Move a microsecond timestamp far into the past. */
    protected function rewind(string $id, string $column): void
    {
        self::assertContains($column, ['available_at', 'reserved_at', 'created_at']);

        $this->pdo->prepare(sprintf('UPDATE queue_jobs SET %s = 0 WHERE id = ?', $column))
            ->execute([$id]);
    }

    /** Valid JSON that does not decode to an array. */
    protected function corruptPayloadLiteral(): string
    {
        return 'null';
    }
}

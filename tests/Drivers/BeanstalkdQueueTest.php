<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Drivers;

use PHPUnit\Framework\TestCase;
use RTIGLOBAL\Phpqdrv\Drivers\BeanstalkdQueue;
use RTIGLOBAL\Phpqdrv\Exception\InvalidPayloadException;
use RTIGLOBAL\Phpqdrv\Exception\UnknownJobException;
use RTIGLOBAL\Phpqdrv\Tests\Support\FakeBeanstalkdConnection;

final class BeanstalkdQueueTest extends TestCase
{
    private FakeBeanstalkdConnection $connection;

    private BeanstalkdQueue $queue;

    protected function setUp(): void
    {
        $this->connection = new FakeBeanstalkdConnection();
        $this->queue = new BeanstalkdQueue($this->connection);
    }

    public function testPushPublishesToTheTube(): void
    {
        $this->queue->push('emails', ['a' => 1]);

        self::assertSame(1, $this->connection->tubeSize('emails'));
        self::assertSame(1, $this->queue->size('emails'));
    }

    public function testPushHonoursTheDelay(): void
    {
        $this->queue->push('emails', ['a' => 1], delay: 30);

        self::assertSame(1, $this->connection->puts);
    }

    public function testPopReservesAndDecodesTheJob(): void
    {
        $pushed = $this->queue->push('emails', ['a' => ['b' => true]]);
        $popped = $this->queue->pop('emails');

        self::assertNotNull($popped);
        self::assertSame($pushed->getId(), $popped->getId());
        self::assertSame(['a' => ['b' => true]], $popped->getPayload());
        self::assertSame(1, $popped->getAttempts());
        self::assertSame(0, $this->queue->size('emails'));
    }

    public function testPopReturnsNullWhenEmpty(): void
    {
        self::assertNull($this->queue->pop('emails'));
    }

    public function testAckDeletesTheServerJob(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->ack($job);

        self::assertSame(1, $this->connection->deletes);
        self::assertSame([], $this->connection->reserved);
    }

    public function testAckOnAnUnknownHandleThrows(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);

        // A second instance cannot address a job reserved by the first one.
        $other = new BeanstalkdQueue($this->connection);

        $this->expectException(UnknownJobException::class);
        $other->ack($job);
    }

    public function testFailMovesTheJobToTheDeadLetterTube(): void
    {
        $this->queue->push('emails', ['x' => 1]);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->fail($job);

        self::assertSame(1, $this->connection->tubeSize('emails.failed'));
        self::assertSame(1, $this->connection->deletes);
    }

    public function testReleaseRepublishesWithABumpedAttempt(): void
    {
        $this->queue->push('emails', []);
        $job = $this->queue->pop('emails');

        self::assertNotNull($job);
        $this->queue->release($job, delay: 5);

        self::assertSame(1, $this->queue->size('emails'));

        $retried = $this->queue->pop('emails');
        self::assertNotNull($retried);
        self::assertSame($job->getId(), $retried->getId());
        self::assertSame(2, $retried->getAttempts());
    }

    public function testCorruptBodyIsDeletedAndRejected(): void
    {
        $this->connection->putInTube('emails', 'not-json', 1024, 0, 120);

        try {
            $this->queue->pop('emails');
            self::fail('Expected InvalidPayloadException.');
        } catch (InvalidPayloadException) {
            self::assertSame(1, $this->connection->deletes, 'a corrupt job must not stay reserved');
        }
    }

    public function testRequeueExpiredIsHandledByTheServer(): void
    {
        self::assertSame(0, $this->queue->requeueExpired());
    }
}

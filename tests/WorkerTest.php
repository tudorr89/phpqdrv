<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RTIGLOBAL\Phpqdrv\Tests\Support\ArrayQueue;
use RTIGLOBAL\Phpqdrv\Worker;

final class WorkerTest extends TestCase
{
    private ArrayQueue $queue;

    protected function setUp(): void
    {
        $this->queue = new ArrayQueue();
    }

    public function testSuccessAcksTheJob(): void
    {
        $this->queue->push('emails', ['to' => 'a@example.com']);

        $worker = new Worker($this->queue, 3, 0);
        $seen = [];

        $processed = $worker->work('emails', function (array $payload) use (&$seen): void {
            $seen[] = $payload;
        }, maxJobs: 1);

        self::assertSame(1, $processed);
        self::assertSame([['to' => 'a@example.com']], $seen);
        self::assertCount(1, $this->queue->acked);
        self::assertSame([], $this->queue->released);
        self::assertSame([], $this->queue->failed);
    }

    public function testFailingJobIsReleasedWithBackoffThenFailed(): void
    {
        $this->queue->push('emails', ['boom' => true]);

        $worker = new Worker($this->queue, maxAttempts: 3, sleepSeconds: 0);
        $calls = 0;

        $processed = $worker->work('emails', function () use (&$calls): void {
            ++$calls;
            throw new \RuntimeException('nope');
        }, maxJobs: 3);

        self::assertSame(3, $calls, 'the job must be delivered maxAttempts times');
        self::assertSame(3, $processed);
        self::assertSame([], $this->queue->acked);
        self::assertCount(2, $this->queue->released, 'first two failures are retries');
        self::assertCount(1, $this->queue->failed, 'the third failure is terminal');

        self::assertSame([1, 2], array_column($this->queue->released, 'delay'));
        self::assertSame(3, $this->queue->failed[0]->getAttempts());
    }

    public function testPhpErrorsAreHandledNotFatal(): void
    {
        $this->queue->push('emails', []);

        $worker = new Worker($this->queue, maxAttempts: 1, sleepSeconds: 0);

        $worker->work('emails', static function (): void {
            throw new \Error('fatal-ish');
        }, maxJobs: 1);

        self::assertCount(1, $this->queue->failed);
    }

    public function testAJobIsNeverLostWhenTheProcessorThrows(): void
    {
        $this->queue->push('emails', ['id' => 7]);

        $worker = new Worker($this->queue, maxAttempts: 2, sleepSeconds: 0);
        $worker->work('emails', static function (): void {
            throw new \RuntimeException('nope');
        }, maxJobs: 2);

        $handled = count($this->queue->released) + count($this->queue->failed) + count($this->queue->acked);

        self::assertSame(2, $handled);
        self::assertSame(0, $this->queue->size('emails'));
    }

    public function testProcessNextReturnsFalseOnEmptyQueue(): void
    {
        $worker = new Worker($this->queue, sleepSeconds: 0);

        self::assertFalse($worker->processNext('emails', static fn () => null));
    }

    public function testInfrastructureFailuresDoNotStopTheWorker(): void
    {
        $this->queue->push('emails', ['first' => true]);
        $this->queue->popError = new \RuntimeException('connection lost');

        $worker = new Worker($this->queue, sleepSeconds: 0);

        $processed = $worker->work('emails', static fn () => null, maxJobs: 1);

        self::assertSame(1, $processed);
        self::assertCount(1, $this->queue->acked);
    }

    public function testStopRequestedFromTheProcessorEndsTheLoop(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->queue->push('emails', ['i' => $i]);
        }

        $worker = new Worker($this->queue, sleepSeconds: 0);
        $processed = $worker->work('emails', static function () use ($worker): void {
            $worker->stop();
        });

        self::assertSame(1, $processed);
        self::assertTrue($worker->isStopping());
    }

    public function testMaxJobsBoundsTheLoop(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->queue->push('emails', ['i' => $i]);
        }

        $worker = new Worker($this->queue, sleepSeconds: 0);

        self::assertSame(3, $worker->work('emails', static fn () => null, maxJobs: 3));
        self::assertSame(7, $this->queue->size('emails'));
    }

    public function testSweepsExpiredJobsWhileWorking(): void
    {
        $this->queue->push('emails', []);

        $worker = new Worker($this->queue, sleepSeconds: 0);
        $worker->work('emails', static fn () => null, maxJobs: 1);

        self::assertGreaterThanOrEqual(1, $this->queue->requeueCalls);
    }

    public function testLoggerReceivesFailureDetails(): void
    {
        $this->queue->push('emails', []);
        $logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $worker = new Worker($this->queue, maxAttempts: 1, sleepSeconds: 0, logger: $logger);
        $worker->work('emails', static function (): void {
            throw new \RuntimeException('boom');
        }, maxJobs: 1);

        self::assertNotEmpty($logger->records);
        self::assertSame('Job failed', $logger->records[0]['message']);
        self::assertArrayHasKey('exception', $logger->records[0]['context']);
    }

    public function testRejectsInvalidMaxAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Worker($this->queue, maxAttempts: 0);
    }
}

<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RTIGLOBAL\Phpqdrv\Drivers\SQLiteQueue;
use RTIGLOBAL\Phpqdrv\Worker;

/**
 * End to end coverage of the worker against a real (in file) SQLite database.
 */
final class SQLiteWorkerIntegrationTest extends TestCase
{
    private PDO $pdo;

    private SQLiteQueue $queue;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'sqlite:' . sys_get_temp_dir() . '/phpqdrv-e2e-' . bin2hex(random_bytes(6)) . '.sqlite',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->queue = new SQLiteQueue($this->pdo);
    }

    public function testMixedSuccessAndFailureEndToEnd(): void
    {
        $this->queue->push('emails', ['id' => 1]);
        $this->queue->push('emails', ['id' => 2]);
        $this->queue->push('emails', ['id' => 3]);

        $worker = new Worker($this->queue, maxAttempts: 1, sleepSeconds: 0);
        $seen = [];

        $processed = $worker->work('emails', static function (array $payload) use (&$seen): void {
            $seen[] = $payload['id'];

            if ($payload['id'] === 2) {
                throw new \RuntimeException('always fails');
            }
        }, maxJobs: 3);

        self::assertSame(3, $processed);
        self::assertSame([1, 2, 3], $seen);
        self::assertSame(0, $this->queue->size('emails'));

        $statuses = $this->pdo
            ->query('SELECT payload, status FROM queue_jobs ORDER BY created_at ASC')
            ->fetchAll(PDO::FETCH_KEY_PAIR);

        self::assertSame('completed', $statuses['{"id":1}']);
        self::assertSame('failed', $statuses['{"id":2}']);
        self::assertSame('completed', $statuses['{"id":3}']);
    }

    public function testRetriesReachTheDeadLetterAfterMaxAttempts(): void
    {
        $this->queue->push('emails', ['id' => 1]);

        $worker = new Worker($this->queue, maxAttempts: 3, sleepSeconds: 0);
        $attempts = [];

        $worker->work('emails', static function () use (&$attempts): void {
            $attempts[] = count($attempts) + 1;

            throw new \RuntimeException('always fails');
        }, maxJobs: 3);

        self::assertSame([1, 2, 3], $attempts);
        self::assertSame(0, $this->queue->size('emails'));

        $status = $this->pdo->query('SELECT status FROM queue_jobs')->fetchColumn();
        self::assertSame('failed', $status);
    }

    public function testDelayedJobIsPickedUpByALaterWorkerPass(): void
    {
        $this->queue->push('emails', ['id' => 1], delay: 60);

        $worker = new Worker($this->queue, sleepSeconds: 0);
        self::assertFalse($worker->processNext('emails', static fn () => null));

        // Simulate the delay elapsing.
        $this->pdo->exec('UPDATE queue_jobs SET available_at = 0');

        self::assertTrue($worker->processNext('emails', static fn () => null));
        self::assertSame(0, $this->queue->size('emails'));
    }
}

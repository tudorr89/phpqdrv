<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Drivers;

use PDO;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Drivers\AbstractPdoQueue;
use RTIGLOBAL\Phpqdrv\Drivers\SQLiteQueue;

final class SQLiteQueueTest extends AbstractPdoQueueTestCase
{
    public function testClaimsAreExclusiveAcrossConnections(): void
    {
        $this->queue->push('emails', ['n' => 1]);
        $this->queue->push('emails', ['n' => 2]);

        $other = $this->createQueue($this->secondConnection());

        $first = $this->queue->pop('emails');
        $second = $other->pop('emails');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->getId(), $second->getId());
        self::assertNull($other->pop('emails'));
    }

    public function testConnectionUsesWriteAheadLogging(): void
    {
        $mode = $this->pdo->query('PRAGMA journal_mode')->fetchColumn();

        self::assertSame('wal', strtolower((string) $mode));
    }

    public function testConnectionUsesExceptionErrorMode(): void
    {
        self::assertSame(PDO::ERRMODE_EXCEPTION, $this->pdo->getAttribute(PDO::ATTR_ERRMODE));
    }

    protected function createPdo(): PDO
    {
        $path = sys_get_temp_dir() . '/phpqdrv-' . bin2hex(random_bytes(6)) . '.sqlite';

        return new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    protected function createQueue(PDO $pdo): AbstractPdoQueue
    {
        return new SQLiteQueue($pdo);
    }

    protected function createQueueWithoutSchema(PDO $pdo): QueueInterface
    {
        return new SQLiteQueue($pdo, false);
    }

    private function secondConnection(): PDO
    {
        $statement = $this->pdo->query('PRAGMA database_list');
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row);

        return new PDO('sqlite:' . $row['file'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}

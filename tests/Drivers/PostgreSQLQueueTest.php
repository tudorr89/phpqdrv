<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Drivers;

use PDO;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Drivers\AbstractPdoQueue;
use RTIGLOBAL\Phpqdrv\Drivers\PostgreSQLQueue;

final class PostgreSQLQueueTest extends AbstractPdoQueueTestCase
{
    public function testSkipLockedKeepsConcurrentClaimsExclusive(): void
    {
        $this->queue->push('emails', ['n' => 1]);
        $this->queue->push('emails', ['n' => 2]);

        $other = $this->createQueue($this->secondConnection());

        $first = $this->queue->pop('emails');
        $second = $other->pop('emails');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first->getId(), $second->getId());
    }

    public function testPayloadTypesSurviveTheRoundTrip(): void
    {
        $payload = ['int' => 1, 'list' => [1, 2, 3], 'nested' => ['a' => ['b' => true]], 'null' => null, 'float' => 1.5];

        $this->queue->push('emails', $payload);

        self::assertSame($payload, $this->queue->pop('emails')?->getPayload());
    }

    protected function createPdo(): PDO
    {
        $dsn = getenv('QDRV_PGSQL_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped(
                'Set QDRV_PGSQL_DSN to run PostgreSQL tests, e.g. "pgsql:host=127.0.0.1;port=5432;dbname=phpqdrv_test".'
            );
        }

        return new PDO(
            $dsn,
            getenv('QDRV_PGSQL_USER') ?: 'postgres',
            getenv('QDRV_PGSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function createQueue(PDO $pdo): AbstractPdoQueue
    {
        return new PostgreSQLQueue($pdo);
    }

    protected function createQueueWithoutSchema(PDO $pdo): QueueInterface
    {
        return new PostgreSQLQueue($pdo, false);
    }

    private function secondConnection(): PDO
    {
        $dsn = (string) getenv('QDRV_PGSQL_DSN');

        return new PDO(
            $dsn,
            getenv('QDRV_PGSQL_USER') ?: 'postgres',
            getenv('QDRV_PGSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
}

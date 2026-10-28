<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Drivers;

use PDO;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Drivers\AbstractPdoQueue;
use RTIGLOBAL\Phpqdrv\Drivers\MariaDBQueue;

final class MariaDBQueueTest extends AbstractPdoQueueTestCase
{
    public function testServerVersionIsParsable(): void
    {
        $version = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);

        self::assertMatchesRegularExpression('/\d+\.\d+\.\d+/', $version);
        self::assertNull($this->queue->pop('does-not-exist'));
    }

    protected function createPdo(): PDO
    {
        $dsn = getenv('QDRV_MYSQL_DSN');
        $database = getenv('QDRV_MYSQL_DATABASE') ?: 'phpqdrv_test';

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped(
                'Set QDRV_MYSQL_DSN to run MariaDB tests, e.g. "mysql:host=127.0.0.1;port=3306;dbname=phpqdrv_test".'
            );
        }

        $pdo = new PDO(
            $dsn,
            getenv('QDRV_MYSQL_USER') ?: 'root',
            getenv('QDRV_MYSQL_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if ($dsn === 'mysql:') {
            $pdo->exec(sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $database));
            $pdo->exec(sprintf('USE `%s`', $database));
        }

        return $pdo;
    }

    protected function createQueue(PDO $pdo): AbstractPdoQueue
    {
        return new MariaDBQueue($pdo);
    }

    protected function createQueueWithoutSchema(PDO $pdo): QueueInterface
    {
        return new MariaDBQueue($pdo, false);
    }
}

<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv;

use PDO;
use Pheanstalk\Pheanstalk;
use Predis\ClientInterface as PredisClientInterface;
use RTIGLOBAL\Phpqdrv\Adapters\PheanstalkAdapter;
use RTIGLOBAL\Phpqdrv\Adapters\PredisAdapter;
use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdConnectionInterface;
use RTIGLOBAL\Phpqdrv\Contracts\QueueInterface;
use RTIGLOBAL\Phpqdrv\Contracts\RedisClientInterface;
use RTIGLOBAL\Phpqdrv\Drivers\BeanstalkdQueue;
use RTIGLOBAL\Phpqdrv\Drivers\MariaDBQueue;
use RTIGLOBAL\Phpqdrv\Drivers\PostgreSQLQueue;
use RTIGLOBAL\Phpqdrv\Drivers\RedisQueue;
use RTIGLOBAL\Phpqdrv\Drivers\SQLiteQueue;

/**
 * Convenience constructors. Prefer instantiating the drivers directly (or with
 * your DI container) in application code.
 */
final class QueueFactory
{
    public static function redis(
        RedisClientInterface $client,
        int $visibilityTimeout = 300,
        string $prefix = 'phpqdrv',
    ): QueueInterface {
        return new RedisQueue($client, $visibilityTimeout, $prefix);
    }

    /** Wraps a predis client so predis stays an optional dependency. */
    public static function predis(
        PredisClientInterface $client,
        int $visibilityTimeout = 300,
        string $prefix = 'phpqdrv',
    ): QueueInterface {
        return self::redis(new PredisAdapter($client), $visibilityTimeout, $prefix);
    }

    public static function sqlite(
        PDO $pdo,
        bool $ensureSchema = true,
        int $visibilityTimeout = 300,
    ): QueueInterface {
        return new SQLiteQueue($pdo, $ensureSchema, $visibilityTimeout);
    }

    public static function mariadb(
        PDO $pdo,
        bool $ensureSchema = true,
        int $visibilityTimeout = 300,
    ): QueueInterface {
        return new MariaDBQueue($pdo, $ensureSchema, $visibilityTimeout);
    }

    public static function postgresql(
        PDO $pdo,
        bool $ensureSchema = true,
        int $visibilityTimeout = 300,
    ): QueueInterface {
        return new PostgreSQLQueue($pdo, $ensureSchema, $visibilityTimeout);
    }

    public static function beanstalkd(
        BeanstalkdConnectionInterface $connection,
        int $priority = 1024,
        int $ttr = 120,
        int $reserveTimeout = 1,
    ): QueueInterface {
        return new BeanstalkdQueue($connection, $priority, $ttr, $reserveTimeout);
    }

    /** Wraps a Pheanstalk client so Pheanstalk stays an optional dependency. */
    public static function pheanstalk(
        Pheanstalk $client,
        int $priority = 1024,
        int $ttr = 120,
        int $reserveTimeout = 1,
    ): QueueInterface {
        return self::beanstalkd(new PheanstalkAdapter($client), $priority, $ttr, $reserveTimeout);
    }

    /** @deprecated Use {@see self::redis()}. */
    public static function createRedisQueue(
        RedisClientInterface|PredisClientInterface $client,
        int $visibilityTimeout = 300,
        string $prefix = 'phpqdrv',
    ): QueueInterface {
        return $client instanceof RedisClientInterface
            ? self::redis($client, $visibilityTimeout, $prefix)
            : self::predis($client, $visibilityTimeout, $prefix);
    }

    /** @deprecated Use {@see self::mariadb()}. */
    public static function createMariaDBQueue(PDO $pdo): QueueInterface
    {
        return new MariaDBQueue($pdo);
    }

    /** @deprecated Use {@see self::postgresql()}. */
    public static function createPostgreSQLQueue(PDO $pdo): QueueInterface
    {
        return new PostgreSQLQueue($pdo);
    }

    /** @deprecated Use {@see self::sqlite()}. */
    public static function createSQLiteQueue(PDO $pdo): QueueInterface
    {
        return new SQLiteQueue($pdo);
    }

    /** @deprecated Use {@see self::beanstalkd()} or {@see self::pheanstalk()}. */
    public static function createBeanstalkdQueue(BeanstalkdConnectionInterface $connection): QueueInterface
    {
        return new BeanstalkdQueue($connection);
    }
}

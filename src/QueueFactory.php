<?php

namespace Tudorr89\Phpqdrv;

use PDO;
use Predis\Client as RedisClient;
use Net_Beanstalkd;
use Tudorr89\Phpqdrv\Contracts\QueueInterface;
use Tudorr89\Phpqdrv\Drivers\{
    RedisQueue,
    MariaDBQueue,
    PostgreSQLQueue,
    SQLiteQueue,
    BeanstalkdQueue
};

class QueueFactory
{
    public static function createRedisQueue(RedisClient $redis): QueueInterface
    {
        return new RedisQueue($redis);
    }

    public static function createMariaDBQueue(PDO $pdo): QueueInterface
    {
        return new MariaDBQueue($pdo);
    }

    public static function createPostgreSQLQueue(PDO $pdo): QueueInterface
    {
        return new PostgreSQLQueue($pdo);
    }

    public static function createSQLiteQueue(PDO $pdo): QueueInterface
    {
        return new SQLiteQueue($pdo);
    }

    public static function createBeanstalkdQueue(Net_Beanstalkd $beanstalkd): QueueInterface
    {
        return new BeanstalkdQueue($beanstalkd);
    }
}
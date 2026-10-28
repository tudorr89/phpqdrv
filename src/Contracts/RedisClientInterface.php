<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Contracts;

/**
 * Minimal Redis surface required by {@see \RTIGLOBAL\Phpqdrv\Drivers\RedisQueue}.
 *
 * Keeping this interface small means the driver has no hard dependency on
 * predis/phpredis and can be driven by any client, including a fake in tests.
 * Every atomic operation is expressed as a Lua script so the driver only needs
 * EVAL plus two trivial commands.
 */
interface RedisClientInterface
{
    public function rpush(string $key, string $value): int;

    public function llen(string $key): int;

    /**
     * @param list<string>      $keys Values bound to KEYS[1..n]
     * @param list<string|int>  $args Values bound to ARGV[1..n]
     */
    public function eval(string $script, array $keys, array $args = []): mixed;
}

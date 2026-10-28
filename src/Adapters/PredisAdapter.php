<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Adapters;

use Predis\ClientInterface;
use RTIGLOBAL\Phpqdrv\Contracts\RedisClientInterface;

/**
 * Bridges a predis client to {@see RedisClientInterface}.
 *
 * Requiring the interface instead of a concrete predis class keeps predis an
 * optional dependency and lets users plug in phpredis or a test double.
 */
final class PredisAdapter implements RedisClientInterface
{
    public function __construct(private readonly ClientInterface $client)
    {
    }

    public function rpush(string $key, string $value): int
    {
        return (int) $this->client->rpush($key, [$value]);
    }

    public function llen(string $key): int
    {
        return (int) $this->client->llen($key);
    }

    /**
     * @param list<string>     $keys
     * @param list<string|int> $args
     */
    public function eval(string $script, array $keys, array $args = []): mixed
    {
        $arguments = array_map(
            static fn (string|int $value): string => (string) $value,
            [...$keys, ...$args]
        );

        return $this->client->eval($script, count($keys), ...$arguments);
    }
}

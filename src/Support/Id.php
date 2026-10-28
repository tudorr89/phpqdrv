<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Support;

use RTIGLOBAL\Phpqdrv\Exception\QueueException;

/**
 * Collision-resistant job identifiers.
 *
 * `uniqid()` is time-based and collides across processes/hosts that start in
 * the same microsecond, which makes it unsuitable as a queue job id.
 */
final class Id
{
    /** 32 hex characters from a CSPRNG. */
    public static function generate(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            throw new QueueException('Unable to generate a job id: ' . $e->getMessage(), 0, $e);
        }
    }
}

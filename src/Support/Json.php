<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Support;

use RTIGLOBAL\Phpqdrv\Exception\InvalidPayloadException;

/**
 * Strict JSON helpers.
 *
 * Encoding/decoding failures must never be silently persisted as `false` or
 * decoded into `null`, because a job payload is declared as an array.
 */
final class Json
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRESERVE_ZERO_FRACTION
            );
        } catch (\JsonException $e) {
            throw new InvalidPayloadException('Job payload is not JSON encodable.', 0, $e);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidPayloadException('Stored job payload is not valid JSON.', 0, $e);
        }

        if (!is_array($decoded)) {
            throw new InvalidPayloadException('Stored job payload must decode to an array.');
        }

        return $decoded;
    }
}

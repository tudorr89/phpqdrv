<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv;

use RTIGLOBAL\Phpqdrv\Contracts\JobInterface;
use RTIGLOBAL\Phpqdrv\Support\Id;

/**
 * Immutable value object describing a queue job.
 *
 * The optional `$raw` string is the exact serialised representation the driver
 * reserved. Drivers that address jobs by their serialised body (Redis) need
 * the untouched bytes to ack/release reliably.
 */
class Job implements JobInterface
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        private readonly string $id,
        private readonly string $queue,
        private readonly array $payload,
        private readonly int $attempts = 0,
        private readonly ?string $raw = null,
    ) {
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function create(string $queue, array $payload): self
    {
        return new self(Id::generate(), $queue, $payload);
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    public function getQueue(): string
    {
        return $this->queue;
    }

    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /** Driver internal: the serialised form this job was reserved from. */
    public function getRaw(): ?string
    {
        return $this->raw;
    }
}

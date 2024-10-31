<?php

namespace Tudorr89\Phpqdrv;

use Tudorr89\Phpqdrv\Contracts\JobInterface;

class Job implements JobInterface
{
    private string $id;
    private string $queue;
    private array $payload;
    private int $attempts;

    public function __construct(string $id, string $queue, array $payload, int $attempts = 0)
    {
        $this->id = $id;
        $this->queue = $queue;
        $this->payload = $payload;
        $this->attempts = $attempts;
    }

    public function getId(): string
    {
        return $this->id;
    }

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

    public function markAsFailed(): void
    {
        // This method can be implemented by the queue driver
    }

    public function markAsCompleted(): void
    {
        // This method can be implemented by the queue driver
    }
}
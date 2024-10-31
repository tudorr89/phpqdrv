<?php

namespace Tudorr89\Phpqdrv\Contracts;

interface JobInterface
{
    public function getId(): string;
    public function getPayload(): array;
    public function getQueue(): string;
    public function getAttempts(): int;
    public function markAsFailed(): void;
    public function markAsCompleted(): void;
}

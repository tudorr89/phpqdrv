<?php

namespace Tudorr89\Phpqdrv\Contracts;

interface QueueInterface
{
    public function push(string $queue, array $payload): JobInterface;
    public function pop(string $queue): ?JobInterface;
    public function ack(JobInterface $job): void;
    public function fail(JobInterface $job): void;
    public function count(string $queue): int;
}
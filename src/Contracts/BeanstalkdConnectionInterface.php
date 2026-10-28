<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Contracts;

/**
 * Minimal Beanstalkd surface required by {@see \RTIGLOBAL\Phpqdrv\Drivers\BeanstalkdQueue}.
 *
 * Tube selection is part of the operation (rather than a separate `useTube`
 * call) so a single connection can safely serve several queues at once.
 */
interface BeanstalkdConnectionInterface
{
    public function putInTube(string $tube, string $data, int $priority, int $delay, int $ttr): string;

    public function reserveFromTube(string $tube, int $timeoutSeconds): ?BeanstalkdJobInterface;

    public function delete(string $serverJobId): void;

    public function release(string $serverJobId, int $priority, int $delay): void;

    /** Number of jobs ready to be reserved in the tube. */
    public function tubeSize(string $tube): int;
}

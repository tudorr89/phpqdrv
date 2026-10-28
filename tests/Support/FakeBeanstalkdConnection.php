<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Support;

use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdConnectionInterface;
use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdJobInterface;

/**
 * In-memory Beanstalkd server double.
 */
final class FakeBeanstalkdConnection implements BeanstalkdConnectionInterface
{
    /** @var array<string, list<array{id: string, data: string}>> */
    public array $tubes = [];

    /** @var array<string, array{id: string, data: string}> */
    public array $reserved = [];

    public int $puts = 0;

    public int $deletes = 0;

    private int $nextId = 1;

    public function putInTube(string $tube, string $data, int $priority, int $delay, int $ttr): string
    {
        ++$this->puts;

        $id = (string) $this->nextId++;
        $this->tubes[$tube][] = ['id' => $id, 'data' => $data];

        return $id;
    }

    public function reserveFromTube(string $tube, int $timeoutSeconds): ?BeanstalkdJobInterface
    {
        if (!isset($this->tubes[$tube]) || $this->tubes[$tube] === []) {
            return null;
        }

        $job = array_shift($this->tubes[$tube]);

        if ($job === null) {
            return null;
        }

        $this->reserved[$job['id']] = $job;

        return new FakeBeanstalkdJob($job['id'], $job['data']);
    }

    public function delete(string $serverJobId): void
    {
        ++$this->deletes;

        unset($this->reserved[$serverJobId]);
    }

    public function release(string $serverJobId, int $priority, int $delay): void
    {
        unset($this->reserved[$serverJobId]);
    }

    public function tubeSize(string $tube): int
    {
        return count($this->tubes[$tube] ?? []);
    }
}

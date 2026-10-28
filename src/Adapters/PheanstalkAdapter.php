<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Adapters;

use Pheanstalk\Pheanstalk;
use Pheanstalk\Values\Job;
use Pheanstalk\Values\JobId;
use Pheanstalk\Values\TubeName;
use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdConnectionInterface;
use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdJobInterface;

/**
 * Bridges a Pheanstalk client to {@see BeanstalkdConnectionInterface}.
 */
final class PheanstalkAdapter implements BeanstalkdConnectionInterface
{
    public function __construct(private readonly Pheanstalk $client)
    {
    }

    public function putInTube(string $tube, string $data, int $priority, int $delay, int $ttr): string
    {
        $this->client->useTube(new TubeName($tube));

        return $this->client->put($data, $priority, $delay, $ttr)->getId();
    }

    public function reserveFromTube(string $tube, int $timeoutSeconds): ?BeanstalkdJobInterface
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('The reserve timeout must not be negative.');
        }

        $this->client->watch(new TubeName($tube));

        if ($tube !== 'default') {
            $this->client->ignore(new TubeName('default'));
        }

        $job = $this->client->reserveWithTimeout($timeoutSeconds);

        return $job instanceof Job ? new PheanstalkJobAdapter($job) : null;
    }

    public function delete(string $serverJobId): void
    {
        $this->client->delete(new JobId($serverJobId));
    }

    public function release(string $serverJobId, int $priority, int $delay): void
    {
        $this->client->release(new JobId($serverJobId), $priority, $delay);
    }

    public function tubeSize(string $tube): int
    {
        return $this->client->statsTube(new TubeName($tube))->currentJobsReady;
    }
}

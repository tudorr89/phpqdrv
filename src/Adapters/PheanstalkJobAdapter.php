<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Adapters;

use Pheanstalk\Values\Job;
use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdJobInterface;

/**
 * @internal
 */
final class PheanstalkJobAdapter implements BeanstalkdJobInterface
{
    public function __construct(private readonly Job $job)
    {
    }

    public function getId(): string
    {
        return $this->job->getId();
    }

    public function getData(): string
    {
        return $this->job->getData();
    }
}

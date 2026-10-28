<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests\Support;

use RTIGLOBAL\Phpqdrv\Contracts\BeanstalkdJobInterface;

final class FakeBeanstalkdJob implements BeanstalkdJobInterface
{
    public function __construct(
        private readonly string $id,
        private readonly string $data,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getData(): string
    {
        return $this->data;
    }
}

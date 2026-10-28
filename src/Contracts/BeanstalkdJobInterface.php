<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Contracts;

/**
 * A job as returned by a Beanstalkd reserve call.
 */
interface BeanstalkdJobInterface
{
    /** Server side job id, unique per Beanstalkd instance. */
    public function getId(): string;

    /** Raw job body. */
    public function getData(): string;
}

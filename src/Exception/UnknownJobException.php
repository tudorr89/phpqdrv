<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Exception;

/**
 * Thrown by drivers when a job cannot be matched back to its underlying
 * backend record (for example an unknown Beanstalkd handle).
 */
class UnknownJobException extends QueueException
{
}

<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Exception;

/**
 * Thrown when a job payload cannot be safely serialised or restored.
 */
class InvalidPayloadException extends QueueException
{
}

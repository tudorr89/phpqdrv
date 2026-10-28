<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Contracts;

interface JobInterface
{
    /** Stable, driver-wide unique identifier of the job. */
    public function getId(): string;

    /**
     * The user supplied payload.
     *
     * @return array<array-key, mixed>
     */
    public function getPayload(): array;

    /** Name of the queue the job was pushed to. */
    public function getQueue(): string;

    /**
     * Number of deliveries performed so far, *including the current one*.
     *
     * A freshly pushed job reports 0, the first time it is handed to a worker
     * it reports 1. Drivers that increment on claim and drivers that increment
     * on release both honour this contract.
     */
    public function getAttempts(): int;
}

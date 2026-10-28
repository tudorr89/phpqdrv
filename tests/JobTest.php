<?php

declare(strict_types=1);

namespace RTIGLOBAL\Phpqdrv\Tests;

use PHPUnit\Framework\TestCase;
use RTIGLOBAL\Phpqdrv\Job;

final class JobTest extends TestCase
{
    public function testCreateGeneratesUniqueIds(): void
    {
        $a = Job::create('emails', ['to' => 'a@example.com']);
        $b = Job::create('emails', ['to' => 'b@example.com']);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a->getId());
        self::assertNotSame($a->getId(), $b->getId());
    }

    public function testFreshJobHasNoAttempts(): void
    {
        $job = Job::create('emails', []);

        self::assertSame(0, $job->getAttempts());
        self::assertSame('emails', $job->getQueue());
        self::assertSame([], $job->getPayload());
        self::assertNull($job->getRaw());
    }

    public function testRawBodyIsCarriedWhenProvided(): void
    {
        $job = new Job('id', 'q', ['a' => 1], 2, '{"raw":true}');

        self::assertSame('{"raw":true}', $job->getRaw());
        self::assertSame(2, $job->getAttempts());
    }
}

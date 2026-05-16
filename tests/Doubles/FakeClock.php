<?php

namespace Uptimex\Client\Tests\Doubles;

use DateTimeImmutable;
use Uptimex\Client\Support\Clock;

/**
 * A controllable {@see Clock} for deterministic spool / drain tests.
 */
final class FakeClock implements Clock
{
    public function __construct(private int $timestamp = 1_700_000_000) {}

    public function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable)->setTimestamp($this->timestamp);
    }

    public function monotonic(): float
    {
        return (float) $this->timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->timestamp += $seconds;
    }
}

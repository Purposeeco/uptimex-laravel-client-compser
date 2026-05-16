<?php

namespace Uptimex\Client\Support;

use DateTimeImmutable;

/**
 * Real-time {@see Clock} backed by PHP's system clock.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }

    public function monotonic(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}

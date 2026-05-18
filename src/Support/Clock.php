<?php

namespace Uptimex\Client\Support;

use DateTimeImmutable;

/**
 * Injectable wall clock. Exists so the agent's retry backoff and
 * shutdown-drain budget are deterministic under test instead of
 * reaching for global `Carbon::setTestNow()` state.
 */
interface Clock
{
    /**
     * The current wall-clock time.
     */
    public function now(): DateTimeImmutable;

    /**
     * A monotonic timestamp in seconds, suitable for measuring elapsed
     * time inside a budget loop. Never goes backwards across NTP steps.
     */
    public function monotonic(): float;
}

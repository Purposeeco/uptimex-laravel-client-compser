<?php

namespace Uptimex\Client\Drain;

/**
 * The outcome of one drain pass. Surfaced by `uptimex:spool:drain`.
 */
final class DrainResult
{
    public function __construct(
        public readonly int $sent = 0,
        public readonly int $failed = 0,
        public readonly int $remaining = 0,
        public readonly bool $lockContended = false,
    ) {}

    /**
     * Result for a pass that did nothing because another drainer held
     * the lock.
     */
    public static function contended(int $remaining): self
    {
        return new self(remaining: $remaining, lockContended: true);
    }
}

<?php

namespace Uptimex\Client\Agent;

/**
 * The outcome of one {@see Shipper::ship()} pass.
 */
final class ShipReport
{
    public function __construct(
        public readonly int $sent,
        public readonly bool $failed,
        public readonly int $queueDepth,
    ) {}
}

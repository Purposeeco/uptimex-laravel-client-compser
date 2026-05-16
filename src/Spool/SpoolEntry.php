<?php

namespace Uptimex\Client\Spool;

use DateTimeImmutable;

/**
 * A single pending item in the spool: a {@see SpooledBatch} plus the
 * on-disk metadata the drainer needs — its id, how many times delivery
 * has already failed, and when it became (or becomes) eligible to send.
 */
final class SpoolEntry
{
    public function __construct(
        public readonly string $id,
        public readonly SpooledBatch $batch,
        public readonly int $attempts,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $eligibleAt,
        public readonly int $sizeBytes,
    ) {}
}

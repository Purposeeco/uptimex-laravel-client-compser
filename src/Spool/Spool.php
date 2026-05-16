<?php

namespace Uptimex\Client\Spool;

/**
 * A durable outbox for finished telemetry batches.
 *
 * The write side (a dispatcher) only ever calls {@see write()}; the drain
 * side lists, deletes and re-schedules. Keeping the surface this small
 * means a future RedisSpool or AgentSocketSpool is a drop-in replacement.
 */
interface Spool
{
    /**
     * Persist a finished batch durably. Returns the entry id.
     *
     * @throws \RuntimeException when the batch cannot be persisted.
     */
    public function write(SpooledBatch $batch): string;

    /**
     * Pending entries eligible to send right now, oldest first, capped at
     * $limit. Corrupt files are quarantined and skipped — never throws.
     *
     * @return list<SpoolEntry>
     */
    public function pending(int $limit): array;

    /**
     * Remove an entry once its delivery has been confirmed. Idempotent.
     */
    public function delete(string $id): void;

    /**
     * Record a failed delivery attempt: bumps the attempt counter and
     * pushes the entry's next-eligible time out with capped backoff.
     */
    public function markFailed(SpoolEntry $entry): void;

    /**
     * Total number of pending entries on disk (eligible or not).
     */
    public function size(): int;
}

<?php

namespace Uptimex\Client\Drain;

use Throwable;
use Uptimex\Client\Spool\Spool;
use Uptimex\Client\Support\Clock;
use Uptimex\Client\Support\Lock;
use Uptimex\Client\Transport\NullTransport;
use Uptimex\Client\Transport\Transport;

/**
 * Default {@see Drainer}: ships spooled batches over the {@see Transport},
 * one host-wide drain at a time, capped by a {@see DrainBudget}.
 *
 * The zero-loss guarantee lives here: a spool file is deleted ONLY after
 * the server confirms receipt (a 2xx, which HttpTransport reports as a
 * `true` return). A failed send leaves the file on disk and reschedules it
 * with backoff. A crash between send and delete re-sends the batch on the
 * next pass — the ingest server de-dupes by batch_uuid, so that is safe.
 */
final class SpoolDrainer implements Drainer
{
    private const LOCK = 'uptimex-drain';

    public function __construct(
        private readonly Spool $spool,
        private readonly Transport $transport,
        private readonly Lock $lock,
        private readonly Clock $clock,
        private readonly int $failfast = 3,
    ) {}

    public function drain(DrainBudget $budget): DrainResult
    {
        // A disabled / unconfigured SDK resolves to NullTransport — draining
        // would "succeed" and delete a spool that built up while it was on.
        if ($this->transport instanceof NullTransport) {
            return new DrainResult(remaining: $this->spool->size());
        }

        $handle = $this->lock->tryAcquire(self::LOCK);
        if ($handle === null) {
            return DrainResult::contended($this->spool->size());
        }

        try {
            return $this->runLocked($budget);
        } catch (Throwable) {
            return new DrainResult(remaining: $this->spool->size());
        } finally {
            $handle->release();
        }
    }

    private function runLocked(DrainBudget $budget): DrainResult
    {
        $startedAt = $this->clock->monotonic();
        $sent = 0;
        $failed = 0;
        $consecutiveFailures = 0;

        foreach ($this->spool->pending($budget->maxBatches) as $entry) {
            if (($this->clock->monotonic() - $startedAt) >= $budget->maxSeconds) {
                break;
            }
            if ($consecutiveFailures >= $this->failfast) {
                break; // ingest looks down — stop flogging it this pass
            }

            $ok = false;
            try {
                $ok = $this->transport->send($entry->batch->toArray());
            } catch (Throwable) {
                $ok = false;
            }

            if ($ok) {
                $this->spool->delete($entry->id);
                $sent++;
                $consecutiveFailures = 0;
            } else {
                $this->spool->markFailed($entry);
                $failed++;
                $consecutiveFailures++;
            }
        }

        return new DrainResult(
            sent: $sent,
            failed: $failed,
            remaining: $this->spool->size(),
        );
    }
}

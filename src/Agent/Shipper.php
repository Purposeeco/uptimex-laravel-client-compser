<?php

namespace Uptimex\Client\Agent;

use Throwable;
use Uptimex\Client\Support\Clock;
use Uptimex\Client\Transport\NullTransport;
use Uptimex\Client\Transport\Transport;

/**
 * Ships queued batches to the UptimeX server via the {@see Transport}.
 *
 * On a failed send the whole ship cadence backs off exponentially — the
 * endpoint is down, not one batch — gated on a monotonic clock so the agent
 * never `sleep()`s (a sleep would freeze its event loop). A batch leaves the
 * queue only after a confirmed 2xx. Never throws.
 */
final class Shipper
{
    private int $consecutiveFailures = 0;

    private float $nextAttemptAt = 0.0;

    private int $shippedTotal = 0;

    public function __construct(
        private readonly Transport $transport,
        private readonly Clock $clock,
        private readonly int $retryBaseSeconds = 5,
        private readonly int $retryMaxSeconds = 300,
    ) {}

    /**
     * True when the SDK has no token (a no-op transport) — the agent should
     * idle rather than "successfully" ship and discard real batches.
     */
    public function transportIsNoop(): bool
    {
        return $this->transport instanceof NullTransport;
    }

    /**
     * Whether the backoff window is open — a ship may be attempted now.
     */
    public function readyToShip(): bool
    {
        return $this->clock->monotonic() >= $this->nextAttemptAt;
    }

    /**
     * Ship up to $maxBatches from the front of the queue, stopping at the
     * first failed send (the endpoint is down).
     */
    public function ship(BatchQueue $queue, int $maxBatches): ShipReport
    {
        $sent = 0;
        $failed = false;

        foreach ($queue->peek($maxBatches) as $batch) {
            $ok = false;
            try {
                $ok = $batch->isEmpty() ? true : $this->transport->send($batch->toArray());
            } catch (Throwable) {
                $ok = false;
            }

            if (! $ok) {
                $failed = true;
                break;
            }
            $sent++;
        }

        if ($sent > 0) {
            $queue->drop($sent);
            $this->shippedTotal += $sent;
        }

        if ($failed) {
            $this->onFailure();
        } elseif ($sent > 0) {
            $this->onSuccess();
        }

        return new ShipReport($sent, $failed, $queue->count());
    }

    /**
     * Drain the queue best-effort until it is empty, a send fails, or the
     * monotonic $deadline passes. Used at shutdown — ignores the backoff gate.
     */
    public function drain(BatchQueue $queue, float $deadline): int
    {
        $total = 0;

        while (! $queue->isEmpty() && $this->clock->monotonic() < $deadline) {
            $report = $this->ship($queue, 50);
            $total += $report->sent;
            if ($report->failed || $report->sent === 0) {
                break;
            }
        }

        return $total;
    }

    public function shippedTotal(): int
    {
        return $this->shippedTotal;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    private function onSuccess(): void
    {
        $this->consecutiveFailures = 0;
        $this->nextAttemptAt = 0.0;
    }

    private function onFailure(): void
    {
        $this->consecutiveFailures++;
        $raw = $this->retryBaseSeconds * (2 ** ($this->consecutiveFailures - 1));
        $delay = min($this->retryMaxSeconds, (int) $raw);
        $this->nextAttemptAt = $this->clock->monotonic() + $delay;
    }
}

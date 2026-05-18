<?php

namespace Uptimex\Client\Agent;

use Illuminate\Support\Facades\Log;
use Uptimex\Client\Delivery\TelemetryBatch;

/**
 * A bounded in-memory FIFO of pending telemetry batches inside the agent.
 *
 * When a push would exceed the cap the oldest batches are dropped (and the
 * loss logged), so a long ingest outage can never let the agent exhaust the
 * host's memory.
 */
final class BatchQueue
{
    private readonly int $maxBatches;

    /** @var list<TelemetryBatch> oldest first. */
    private array $batches = [];

    private int $droppedTotal = 0;

    public function __construct(int $maxBatches = 10000)
    {
        $this->maxBatches = max(1, $maxBatches);
    }

    public function push(TelemetryBatch $batch): void
    {
        $this->batches[] = $batch;

        $overflow = count($this->batches) - $this->maxBatches;
        if ($overflow > 0) {
            array_splice($this->batches, 0, $overflow);
            $this->droppedTotal += $overflow;
            Log::warning('uptimex.agent.queue_overflow', [
                'dropped' => $overflow,
                'depth' => count($this->batches),
                'max' => $this->maxBatches,
            ]);
        }
    }

    /**
     * The oldest $limit batches, without removing them.
     *
     * @return list<TelemetryBatch>
     */
    public function peek(int $limit): array
    {
        return array_slice($this->batches, 0, max(0, $limit));
    }

    /**
     * Remove the oldest $count batches — called after a confirmed delivery.
     */
    public function drop(int $count): void
    {
        if ($count > 0) {
            array_splice($this->batches, 0, $count);
        }
    }

    public function count(): int
    {
        return count($this->batches);
    }

    public function isEmpty(): bool
    {
        return $this->batches === [];
    }

    public function droppedTotal(): int
    {
        return $this->droppedTotal;
    }
}

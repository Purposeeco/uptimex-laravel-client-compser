<?php

namespace Uptimex\Client\Tests\Doubles;

use Uptimex\Client\Delivery\BatchDispatcher;
use Uptimex\Client\Delivery\TelemetryBatch;
use Uptimex\Client\Transport\Transport;

/**
 * Test double for {@see BatchDispatcher}. Bridges a finished trace's batch to
 * the in-memory transport bound in TestCase, so tests can keep asserting what
 * would have been sent via `$this->transport->sentBatches()`. It also records
 * every batch it received, for tests that prefer to assert on the dispatcher.
 */
final class FakeDispatcher implements BatchDispatcher
{
    /** @var list<TelemetryBatch> */
    public array $dispatched = [];

    public function __construct(private readonly Transport $transport) {}

    public function dispatch(TelemetryBatch $batch): bool
    {
        $this->dispatched[] = $batch;

        if ($batch->isEmpty()) {
            return true;
        }

        return $this->transport->send($batch->toArray());
    }
}

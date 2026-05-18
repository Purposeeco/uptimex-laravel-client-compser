<?php

namespace Uptimex\Client\Delivery;

/**
 * Strategy for what happens to a finished batch when a trace ends.
 *
 * `Uptimex::endTrace()` depends only on this interface — whether the batch
 * is handed to the local agent, sent inline over HTTPS, or dropped is the
 * strategy's concern. A new delivery mode is a new implementation plus one
 * match arm in the service provider; nothing else changes.
 */
interface BatchDispatcher
{
    /**
     * Hand off a finished batch for delivery.
     *
     * MUST NOT throw — telemetry must never break the host request.
     * Returns true if the batch was accepted (queued by the agent or sent).
     */
    public function dispatch(TelemetryBatch $batch): bool;
}

<?php

namespace Uptimex\Client\Delivery;

use Uptimex\Client\Spool\SpooledBatch;

/**
 * Strategy for what happens to a finished batch when a trace ends.
 *
 * `Uptimex::endTrace()` depends only on this interface — whether the batch
 * is spooled to disk, sent inline over HTTP, or dropped is the strategy's
 * concern. A new delivery mode is a new implementation plus one match arm
 * in the service provider; nothing else changes.
 */
interface BatchDispatcher
{
    /**
     * Hand off a finished batch for delivery.
     *
     * MUST NOT throw — telemetry must never break the host request.
     * Returns true if the batch was accepted (spooled or sent).
     */
    public function dispatch(SpooledBatch $batch): bool;
}

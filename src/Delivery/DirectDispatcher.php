<?php

namespace Uptimex\Client\Delivery;

use Throwable;
use Uptimex\Client\Spool\SpooledBatch;
use Uptimex\Client\Transport\Transport;

/**
 * Sends a batch inline over the {@see Transport}, exactly as the SDK did
 * before the spool model existed.
 *
 * Used for serverless runtimes (no second request to drain a spool on),
 * for `uptimex:test` (which needs a real synchronous round-trip), and as
 * the safety fallback when the spool directory is not writable.
 */
final class DirectDispatcher implements BatchDispatcher
{
    public function __construct(private readonly Transport $transport) {}

    public function dispatch(SpooledBatch $batch): bool
    {
        if ($batch->isEmpty()) {
            return true;
        }

        try {
            return $this->transport->send($batch->toArray());
        } catch (Throwable) {
            return false;
        }
    }
}

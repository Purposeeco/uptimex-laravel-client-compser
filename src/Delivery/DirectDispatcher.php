<?php

namespace Uptimex\Client\Delivery;

use Throwable;
use Uptimex\Client\Transport\Transport;

/**
 * Sends a batch inline over the {@see Transport} at the end of the request.
 *
 * Used for serverless runtimes (Vapor / Lambda, where no long-lived agent
 * can run), for `uptimex:test` (which needs a real synchronous round-trip),
 * and as the {@see SocketDispatcher}'s fallback when no agent is listening.
 */
final class DirectDispatcher implements BatchDispatcher
{
    public function __construct(private readonly Transport $transport) {}

    public function dispatch(TelemetryBatch $batch): bool
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

<?php

namespace Uptimex\Client\Delivery;

/**
 * No-op dispatcher, bound when the SDK is disabled or has no token.
 */
final class NullDispatcher implements BatchDispatcher
{
    public function dispatch(TelemetryBatch $batch): bool
    {
        return true;
    }
}
